<?php

namespace App\Services\Pinyin;

use App\Support\PinyinUmlaut;
use Illuminate\Support\Facades\DB;

/**
 * Code 表拼音 v→ü 批次掃描器（Phase B）。
 *
 * 對一張表的指定欄位掃描含 `v/V` 的列，套用確定性規則 {@see PinyinUmlaut::normalize()}，
 * 分類為：
 *   - mutations：規則命中（值會改變，如 `Lvchuan`→`Lüchuan`）→ 遷移候選。
 *   - otherVs：含 v 但規則未命中（如 `Vietnam`、`Bavard`）→ 安全網清單，供人眼確認無誤傷。
 *
 * 純讀取、不寫入（寫入由指令走受審計 API）。與 §D-5 掃描規則、§D-6 Tier 登錄一致。
 */
class CodeTablePinyinScanner {
    /**
     * @param  list<string>  $keyColumns  主鍵欄（構成每列 pk）
     * @param  list<string>  $columns     要掃描的欄位
     * @return array{mutations: list<array{pk:array<string,mixed>,column:string,from:string,to:string}>, otherVs: list<array{pk:array<string,mixed>,column:string,value:string}>, scannedRows:int}
     */
    public function scan(string $table, array $keyColumns, array $columns, ?string $connection = null): array {
        if ($columns === []) {
            return ['mutations' => [], 'otherVs' => [], 'scannedRows' => 0];
        }

        $db = DB::connection($connection);
        $select = array_values(array_unique(array_merge($keyColumns, $columns)));

        // 預篩含 v/V 的列（LIKE 於 SQLite/MySQL 預設對 ASCII 大小寫不敏感，v 與 V 皆命中）；
        // 逐欄 orWhere，任一欄含 v 即取回，再於 PHP 端逐欄精確判定。
        $query = $db->table($table)->select($select)->where(function ($q) use ($columns) {
            foreach ($columns as $c) {
                $q->orWhere($c, 'like', '%v%');
            }
        });

        $mutations = [];
        $otherVs = [];
        $scannedRows = 0;

        foreach ($query->cursor() as $row) {
            $scannedRows++;
            $rowArr = (array) $row;
            $pk = [];
            foreach ($keyColumns as $k) {
                $pk[$k] = $rowArr[$k] ?? null;
            }
            foreach ($columns as $c) {
                $val = $rowArr[$c] ?? null;
                if (!is_string($val) || $val === '' || !preg_match('/[Vv]/', $val)) {
                    continue;
                }
                $norm = PinyinUmlaut::normalize($val);
                if ($norm !== $val) {
                    $mutations[] = ['pk' => $pk, 'column' => $c, 'from' => $val, 'to' => $norm];
                } else {
                    $otherVs[] = ['pk' => $pk, 'column' => $c, 'value' => $val];
                }
            }
        }

        return ['mutations' => $mutations, 'otherVs' => $otherVs, 'scannedRows' => $scannedRows];
    }
}
