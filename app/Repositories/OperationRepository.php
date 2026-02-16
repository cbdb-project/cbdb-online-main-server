<?php

/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/9/17
 * Time: 16:52
 */

namespace App\Repositories;

use App\Models\Operation;
use Illuminate\Support\Facades\DB;

class OperationRepository {
    /**
     * @param int $user_id 用户ID
     * @param int $c_personid 人物ID
     * @param int $op_type 操作类型
     * @param string $resource 修改表明
     * @param int $resource_id 修改数据ID
     * @param array $resource_data 数据
     * @param array $ori 数据
     * @param int $crowdsourcing_status 0.專業用戶修改紀錄
     *                                  1.crowdsourcing記錄並已插入數據庫
     *                                  2.crowdsourcing記錄還沒有被處理
     *                                  3.crowdsourcing記錄reject
     *                                  4.crowdsourcing處理失敗
     * @return mixed
     */
    public function store($user_id, $c_personid, $op_type, $resource, $resource_id, $resource_data, $ori = '', $crowdsourcing_status = 0) {
        $resource_data = $this->attachProposalNote($resource_data);
        $operation = new Operation();
        $operation->user_id = $user_id;
        $operation->c_personid = $c_personid;
        $operation->op_type = $op_type;
        $operation->resource = $resource;
        $operation->resource_id = $resource_id;
        $operation->resource_data = json_encode($resource_data, JSON_UNESCAPED_UNICODE);
        if (!empty($ori)) {
            $operation->resource_original = json_encode($ori, JSON_UNESCAPED_UNICODE);
        }
        if ($crowdsourcing_status != 0) {
            $operation->crowdsourcing_status = $crowdsourcing_status;
        }
        $operation->save();

        return $operation;
    }

    protected function attachProposalNote($resourceData) {
        if (!is_array($resourceData)) {
            return $resourceData;
        }

        if (array_key_exists('__proposal_comment', $resourceData)) {
            unset($resourceData['__proposal_comment']);
        }

        if (array_key_exists('__note', $resourceData) && trim((string) $resourceData['__note']) !== '') {
            return $resourceData;
        }

        $comment = '';

        try {
            $comment = trim((string) request()->input('__proposal_comment', ''));
        } catch (\Throwable $e) {
            $comment = '';
        }

        if ($comment !== '') {
            $resourceData['__note'] = $comment;
        }

        return $resourceData;
    }

    public function hasPendingCreateProposal(string $resource, string $resourceId, ?int $excludeId = null): bool {
        try {
            $query = Operation::where('resource', $resource)
                ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
                ->where('resource_id', $resourceId);

            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }

            return $query->get()->contains(function (Operation $operation) {
                $payload = json_decode($operation->resource_data, true);
                $status = is_array($payload) ? ($payload['__review_status'] ?? null) : null;

                return in_array($status, ['pending', 'rejected'], true);
            });
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function objectToArray($object) {
        //先編碼成json字串，再解碼成陣列
        return json_decode(json_encode($object), true);
    }

    public function getArrDiff($arr1, $arr2, $arr3) {
        //20251001Compare 功能有時無法顯示修改內容修改
        if (!is_array($arr1)) {
            return null;  // 沒有修改後資料就不產生比對結果
        }

        $arr2 = is_array($arr2) ? $arr2 : [];
        $arr3 = is_array($arr3) ? $arr3 : [];

        $formatValue = function ($value) {
            if (is_array($value) || is_object($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            if ($value === null) {
                return '(null)';
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            return (string)$value;
        };

        $normalize = function ($value) {
            if (is_array($value) || is_object($value)) {
                return json_encode($value);
            }
            if ($value === null) {
                return '';
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            return trim((string)$value);
        };

        $valuesEqual = function ($left, $right) use ($normalize) {
            return $normalize($left) === $normalize($right);
        };

        $ignoredKeys = ['_method', '_token'];
        $keys = array_keys($arr1);
        $rows = [];

        foreach ($keys as $key) {
            if (in_array($key, $ignoredKeys, true) || strpos($key, '__') === 0) {
                continue;
            }

            $afterRaw = array_key_exists($key, $arr1) ? $arr1[$key] : null;
            $beforeRaw = array_key_exists($key, $arr2) ? $arr2[$key] : null;

            if ($valuesEqual($afterRaw, $beforeRaw)) {
                continue;
            }

            $currentExists = array_key_exists($key, $arr3);
            $currentRaw = $currentExists ? $arr3[$key] : null;

            $rows[] = [
                'field' => $key,
                'before' => $formatValue($beforeRaw),
                'after' => $formatValue($afterRaw),
                'current' => $currentExists ? $formatValue($currentRaw) : '(未取得)',
                'matches_current' => $currentExists && $normalize($afterRaw) === $normalize($currentRaw),
                'matches_before' => $currentExists && $normalize($beforeRaw) === $normalize($currentRaw),
            ];
        }

        if (empty($rows)) {
            return null;
        }

        return ['rows' => $rows];
    }

    /**
     * Build a structured diff payload for POSTED_TO_ADDR_DATA resources.
     *
     * @param array $afterRows
     * @param array $beforeRows
     * @param array $currentRows
     * @return array|null
     */
    public function buildPostedToAddrDiff(array $afterRows, array $beforeRows, array $currentRows) {
        $reference = $this->firstNonEmptyRow($afterRows, $beforeRows, $currentRows);

        $keys = [
            'before' => $this->extractPostedToAddrKeys($beforeRows, $reference),
            'after' => $this->extractPostedToAddrKeys($afterRows, $reference),
            'current' => $this->extractPostedToAddrKeys($currentRows, $reference),
        ];

        $addresses = $this->buildPostedToAddrAddressMatrix($afterRows, $beforeRows, $currentRows);

        if ($reference === null && empty($addresses)) {
            return null;
        }

        return [
            'type' => 'POSTED_TO_ADDR_DATA',
            'keys' => $keys,
            'addresses' => $addresses,
        ];
    }

    /**
     * Cache for address labels.
     *
     * @var array<int,string>
     */
    protected $addressLabelCache = [];

    protected function firstNonEmptyRow(array ...$rowSets) {
        foreach ($rowSets as $rows) {
            if (!empty($rows)) {
                $first = reset($rows);
                if (is_array($first) && !empty($first)) {
                    return $first;
                }
            }
        }

        return null;
    }

    protected function extractPostedToAddrKeys(array $rows, ?array $fallback) {
        $source = !empty($rows) ? reset($rows) : $fallback;

        return [
            'c_personid' => $this->normalizePostedToAddrInteger($source['c_personid'] ?? null),
            'c_office_id' => $this->normalizePostedToAddrInteger($source['c_office_id'] ?? null),
            'c_posting_id' => $this->normalizePostedToAddrInteger($source['c_posting_id'] ?? null),
        ];
    }

    protected function buildPostedToAddrAddressMatrix(array $afterRows, array $beforeRows, array $currentRows) {
        $uniqueIds = $this->collectPostedToAddrIds($afterRows, $beforeRows, $currentRows);
        if (empty($uniqueIds)) {
            return [];
        }

        $this->warmAddressLabelCache($uniqueIds);

        $beforeMap = $this->buildPostedToAddrMap($beforeRows);
        $afterMap = $this->buildPostedToAddrMap($afterRows);
        $currentMap = $this->buildPostedToAddrMap($currentRows);

        $matrix = [];
        foreach ($uniqueIds as $addrId) {
            $matrix[] = [
                'id' => $addrId,
                'before' => $beforeMap[$addrId] ?? null,
                'after' => $afterMap[$addrId] ?? null,
                'current' => $currentMap[$addrId] ?? null,
            ];
        }

        return $matrix;
    }

    protected function collectPostedToAddrIds(array ...$rowSets) {
        $ids = [];
        foreach ($rowSets as $rows) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $addrId = $this->normalizePostedToAddrInteger($row['c_addr_id'] ?? null);
                if ($addrId === null) {
                    continue;
                }
                $ids[] = $addrId;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    protected function warmAddressLabelCache(array $addrIds) {
        $existing = array_map('intval', array_keys($this->addressLabelCache));
        $missing = array_diff($addrIds, $existing);
        if (empty($missing)) {
            return;
        }

        try {
            $rows = DB::table('ADDR_CODES')
                ->select('c_addr_id', 'c_name_chn')
                ->whereIn('c_addr_id', $missing)
                ->get();

            foreach ($rows as $row) {
                $id = (int) $row->c_addr_id;
                $this->addressLabelCache[$id] = $this->formatAddressLabel($id, $row->c_name_chn);
            }
        } catch (\Throwable $e) {
            // Swallow exceptions (e.g. table missing in tests) and fall back to generic labels.
        }
        foreach ($missing as $id) {
            if (!array_key_exists($id, $this->addressLabelCache)) {
                $this->addressLabelCache[$id] = $this->formatAddressLabel($id, null);
            }
        }
    }

    protected function buildPostedToAddrMap(array $rows) {
        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $addrId = $this->normalizePostedToAddrInteger($row['c_addr_id'] ?? null);
            if ($addrId === null) {
                continue;
            }
            $map[$addrId] = $this->addressLabelCache[$addrId] ?? $this->formatAddressLabel($addrId, null);
        }

        return $map;
    }

    protected function normalizePostedToAddrInteger($value) {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    protected function formatAddressLabel(int $addrId, ?string $name) {
        $label = $name ? trim($name) : '未詳';

        return $addrId.' '.$label;
    }
}
