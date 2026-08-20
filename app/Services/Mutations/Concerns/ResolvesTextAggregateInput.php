<?php

namespace App\Services\Mutations\Concerns;

use App\Services\Import\TextImportService;
use Illuminate\Support\Facades\DB;

/**
 * 「文獻實體」聚合輸入的解析與校驗（create／update 共用同一形狀——AGENTS：必填欄位
 * create／update 一致）。必填：title（書名）。其餘欄位選填，給值時校驗參照表存在
 * （朝代／年號／年份範圍／文獻分類／存世狀態／文獻類型／來源文獻）。
 *
 * 版本列（instances）選填；每列必填 edition_id 與 instance_id（正整數，於文獻內定位版本），
 * 同一請求內 (edition_id, instance_id) 不可重複。
 *
 * 回傳 [errors, input]；input 形狀即 TextImportService::create()／update() 的輸入。
 */
trait ResolvesTextAggregateInput {
    /**
     * @param array<string, mixed> $changes
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function validateTextAggregate(array $changes, TextImportService $service, string $operation = 'update'): array {
        $errors = [];

        $title = trim((string) ($this->scalarOrNull($changes['title'] ?? $changes['c_title_chn'] ?? null) ?? ''));
        if ($title === '') {
            $errors['title'] = ['required'];
        }

        // 選填整數欄：給值須為整數。
        $optInt = function (string $key, ...$aliases) use ($changes, &$errors) {
            $raw = null;
            foreach ([$key, ...$aliases] as $k) {
                if (array_key_exists($k, $changes)) {
                    $raw = $this->scalarOrNull($changes[$k]);

                    break;
                }
            }
            if ($raw === null || $raw === '') {
                return null;
            }
            if (!preg_match('/^-?\d+$/', (string) $raw)) {
                $errors[$key] = ['integer'];

                return null;
            }

            return (int) $raw;
        };
        $optStr = function (string $key, ...$aliases) use ($changes) {
            foreach ([$key, ...$aliases] as $k) {
                if (array_key_exists($k, $changes)) {
                    $v = $this->scalarOrNull($changes[$k]);

                    return ($v !== null && trim((string) $v) !== '') ? (string) $v : null;
                }
            }

            return null;
        };

        $input = [
            'title' => $title,
            'title_pinyin' => $optStr('title_pinyin', 'c_title'),
            'title_trans' => $optStr('title_trans', 'c_title_trans'),
            'title_alt_chn' => $optStr('title_alt_chn', 'c_title_alt_chn'),
            'type_id' => $optStr('type_id', 'c_text_type_id'),
            'year' => $optInt('year', 'c_text_year'),
            'nh_code' => $optInt('nh_code', 'c_text_nh_code'),
            'nh_year' => $optInt('nh_year', 'c_text_nh_year'),
            'range_code' => $optInt('range_code', 'c_text_range_code'),
            'bibl_cat_code' => $optInt('bibl_cat_code', 'c_bibl_cat_code'),
            'extant' => $optInt('extant', 'c_extant'),
            'country' => $optInt('country', 'c_text_country'),
            'dynasty_code' => $optInt('dynasty_code', 'c_text_dy'),
            'source_id' => $optInt('source_id', 'c_source'),
            'pages' => $optStr('pages', 'c_pages'),
            'url_api' => $optStr('url_api', 'c_url_api'),
            'url_api_coda' => $optStr('url_api_coda', 'c_url_api_coda'),
            'url_homepage' => $optStr('url_homepage', 'c_url_homepage'),
            'notes' => $optStr('notes', 'c_notes'),
        ];

        // 參照表存在性（僅對給值欄）：這些皆為 FK 目標，先以 422 擋下並給欄位級錯誤。
        if ($input['dynasty_code'] !== null && !in_array($input['dynasty_code'], $service->dynastyMap(), true)) {
            $errors['dynasty_code'] = ['invalid'];
        }
        if ($input['nh_code'] !== null && !DB::table('NIAN_HAO')->where('c_nianhao_id', $input['nh_code'])->exists()) {
            $errors['nh_code'] = ['not_found_in_nian_hao'];
        }
        if ($input['range_code'] !== null && !DB::table('YEAR_RANGE_CODES')->where('c_range_code', $input['range_code'])->exists()) {
            $errors['range_code'] = ['not_found_in_year_range_codes'];
        }
        if ($input['bibl_cat_code'] !== null && !DB::table('TEXT_BIBLCAT_CODES')->where('c_text_cat_code', $input['bibl_cat_code'])->exists()) {
            $errors['bibl_cat_code'] = ['not_found_in_text_biblcat_codes'];
        }
        if ($input['extant'] !== null && !DB::table('EXTANT_CODES')->where('c_extant_code', $input['extant'])->exists()) {
            $errors['extant'] = ['not_found_in_extant_codes'];
        }
        if ($input['country'] !== null && !DB::table('COUNTRY_CODES')->where('c_country_code', $input['country'])->exists()) {
            $errors['country'] = ['not_found_in_country_codes'];
        }
        if ($input['type_id'] !== null && !DB::table('TEXT_TYPE')->where('c_text_type_code', $input['type_id'])->exists()) {
            $errors['type_id'] = ['not_found_in_text_type'];
        }
        if ($input['source_id'] !== null && $service->missingSourceIds([$input['source_id']]) !== []) {
            $errors['source_id'] = ['not_found_in_text_codes'];
        }

        // 版本列：選填；每列必填 edition_id／instance_id 正整數，同鍵不可重複。
        $rawInstances = $changes['instances'] ?? [];
        $instances = [];
        if (!is_array($rawInstances)) {
            $errors['instances'] = ['invalid'];
        } else {
            $seen = [];
            foreach (array_values($rawInstances) as $i => $row) {
                if (!is_array($row)) {
                    $errors["instances.$i"] = ['invalid'];

                    continue;
                }
                $editionId = $this->scalarOrNull($row['edition_id'] ?? $row['c_text_edition_id'] ?? null);
                $instanceId = $this->scalarOrNull($row['instance_id'] ?? $row['c_text_instance_id'] ?? null);
                if ($editionId === null || $editionId === '' || !ctype_digit((string) $editionId)
                    || $instanceId === null || $instanceId === '' || !ctype_digit((string) $instanceId)) {
                    $errors["instances.$i.key"] = ['required_integer'];

                    continue;
                }
                // ctype_digit() 會放行 "0"，但版本鍵語義上是正整數（於文獻內定位版本），
                // 0 會產出無效的聚合鍵。create 一律擋；**update 不在這裡擋**——生產庫存在
                // 一列歷史資料 (c_textid=40354, 0, 0)，無條件拒絕會讓那筆文獻連帶整個編輯頁
                // 都送不出去（編輯頁會把既有版本列原樣回送）。update 的 0 值改由
                // TextAggregateDefinition::guardWrite() 比對既有列後放行／擋下。
                if ($operation === 'create' && ((int) $editionId === 0 || (int) $instanceId === 0)) {
                    $errors["instances.$i.key"] = ['positive_integer_required'];

                    continue;
                }
                $key = ((int) $editionId).'|'.((int) $instanceId);
                if (isset($seen[$key])) {
                    $errors["instances.$i.key"] = ['duplicate'];

                    continue;
                }
                $seen[$key] = true;

                $rowInt = function (string $k, string $alias) use ($row, &$errors, $i) {
                    $v = $this->scalarOrNull($row[$k] ?? $row[$alias] ?? null);
                    if ($v === null || $v === '') {
                        return null;
                    }
                    if (!preg_match('/^-?\d+$/', (string) $v)) {
                        $errors["instances.$i.$k"] = ['integer'];

                        return null;
                    }

                    return (int) $v;
                };
                $rowStr = function (string $k, string $alias) use ($row) {
                    $v = $this->scalarOrNull($row[$k] ?? $row[$alias] ?? null);

                    return ($v !== null && trim((string) $v) !== '') ? (string) $v : null;
                };

                $instance = [
                    'edition_id' => (int) $editionId,
                    'instance_id' => (int) $instanceId,
                    'title_chn' => $rowStr('title_chn', 'c_instance_title_chn'),
                    'title_pinyin' => $rowStr('title_pinyin', 'c_instance_title'),
                    'publisher' => $rowStr('publisher', 'c_publisher'),
                    'pub_loc' => $rowStr('pub_loc', 'c_pub_loc'),
                    'pub_year' => $rowInt('pub_year', 'c_pub_year'),
                    'pub_dy' => $rowInt('pub_dy', 'c_pub_dy'),
                    'pub_nh_code' => $rowInt('pub_nh_code', 'c_pub_nh_code'),
                    'pub_nh_year' => $rowInt('pub_nh_year', 'c_pub_nh_year'),
                    'source_id' => $rowInt('source_id', 'c_source'),
                    'pages' => $rowStr('pages', 'c_pages'),
                    'extant' => $rowInt('extant', 'c_extant'),
                    'notes' => $rowStr('notes', 'c_notes'),
                ];

                if ($instance['pub_dy'] !== null && !in_array($instance['pub_dy'], $service->dynastyMap(), true)) {
                    $errors["instances.$i.pub_dy"] = ['invalid'];
                }
                if ($instance['pub_nh_code'] !== null && !DB::table('NIAN_HAO')->where('c_nianhao_id', $instance['pub_nh_code'])->exists()) {
                    $errors["instances.$i.pub_nh_code"] = ['not_found_in_nian_hao'];
                }
                if ($instance['source_id'] !== null && $service->missingSourceIds([$instance['source_id']]) !== []) {
                    $errors["instances.$i.source_id"] = ['not_found_in_text_codes'];
                }

                $instances[] = $instance;
            }
        }
        $input['instances'] = $instances;

        return [$errors, $input];
    }
}
