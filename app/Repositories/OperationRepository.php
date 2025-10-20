<?php
/**
 * Created by PhpStorm.
 * User: fuqunchao
 * Date: 2017/9/17
 * Time: 16:52
 */

namespace App\Repositories;


use App\Operation;

class OperationRepository
{
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

    public function store($user_id, $c_personid, $op_type, $resource, $resource_id, $resource_data, $ori='', $crowdsourcing_status=0)
    {
        $operation = new Operation();
        $operation->user_id = $user_id;
        $operation->c_personid = $c_personid;
        $operation->op_type = $op_type;
        $operation->resource = $resource;
        $operation->resource_id = $resource_id;
        $operation->resource_data = json_encode($resource_data);
        if(!empty($ori)) $operation->resource_original = json_encode($ori);
        if($crowdsourcing_status != 0) $operation->crowdsourcing_status = $crowdsourcing_status;
        $operation->save();
    }

    public function objectToArray($object)
    {
        //先編碼成json字串，再解碼成陣列
        return json_decode(json_encode($object), true);
    }

    public function getArrDiff($arr1, $arr2, $arr3)
    {
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
            if (in_array($key, $ignoredKeys, true)) {
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
}
