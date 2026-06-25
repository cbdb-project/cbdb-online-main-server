<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 親屬（KIN_DATA）／社會關係（ASSOC_DATA）互逆鏡像的「單一真相來源」服務（docs/RELATIONSHIP_MIRROR_INLINE_DESIGN.md §8）。
 *
 * 把分散在 BiogMainRepository、*CreateHandler、UnidirectionalRelationshipRepairController 的
 * 「定位／合法反向碼集／建反向列／清單化」邏輯收斂於此，消除「update 寬／delete 窄／repair 另一套」的多路不一致。
 *
 * 本服務為**純資料／計算**輔助（不開交易、不寫 audit），由各呼叫端在其交易內使用。
 *
 * ⚠ 兩個易混淆的「反向碼集」概念（刻意分開）：
 * - validReverse*Set($code)：$code **自身**的配對碼（KINSHIP_CODES.c_kin_pair1/pair2、ASSOC_CODES.c_assoc_pair/pair2）。
 *   用於 #66 衝突基準「對面碼是否仍屬 $code 的合法反向」。
 * - legitReverseKinCodes($oldKinCode)：**指向** $oldKinCode 的碼集（KINSHIP_CODES 中 c_kin_pair1/pair2 = $oldKinCode）。
 *   用於 update 鏡像定位器「對面反向列可能持有的碼」。兩者在純對稱配對時相等，但語義與查詢方向不同，不可互換。
 */
class RelationshipMirrorService {
    /** ASSOC_DATA 反向列預設 c_assoc_first_year（未知年份哨兵）。 */
    public const DEFAULT_ASSOC_FIRST_YEAR = -9999;

    /** 關係類型（kinship / association）的表與欄位設定。 */
    public function repairConfig(string $type): array {
        return $type === 'kinship' ? [
            'table' => 'KIN_DATA',
            'related_id_field' => 'c_kin_id',
            'relation_code_field' => 'c_kin_code',
            'relation_name' => '親屬關係',
        ] : [
            'table' => 'ASSOC_DATA',
            'related_id_field' => 'c_assoc_id',
            'relation_code_field' => 'c_assoc_code',
            'relation_name' => '社會關係',
        ];
    }

    /** 親屬碼 $code 自身的合法反向集（KINSHIP_CODES.c_kin_pair1 / c_kin_pair2）。空/0/查無 → 空陣列。 */
    public function validReverseKinSet($code): array {
        if ($code === null || (int) $code === 0) {
            return [];
        }
        $r = DB::table('KINSHIP_CODES')->where('c_kincode', $code)->first();
        if (!$r) {
            return [];
        }

        return array_values(array_filter([$r->c_kin_pair1 ?? null, $r->c_kin_pair2 ?? null], static fn ($v) => $v !== null && (int) $v !== 0));
    }

    /** 社會關係碼 $code 自身的合法反向集（ASSOC_CODES.c_assoc_pair / c_assoc_pair2）。空/0/查無 → 空陣列。 */
    public function validReverseAssocSet($code): array {
        if ($code === null || (int) $code === 0) {
            return [];
        }
        $r = DB::table('ASSOC_CODES')->where('c_assoc_code', $code)->first();
        if (!$r) {
            return [];
        }

        return array_values(array_filter([$r->c_assoc_pair ?? null, $r->c_assoc_pair2 ?? null], static fn ($v) => $v !== null && (int) $v !== 0));
    }

    /**
     * 把「多條匹配的正向列」格式化為前端清單（人物/碼/出處/建立資訊）。供 repair 頁與行內裁決彈窗（§5.4）共用。
     *
     * @param string                     $type    kinship|association
     * @param iterable<int,object>       $records 匹配列（DB 查詢結果物件，以屬性存取）
     * @return array<int,array<string,mixed>>
     */
    public function formatRecords(string $type, $records): array {
        $mapper = $type === 'kinship'
            ? static fn ($r) => [
                'c_personid' => $r->c_personid,
                'c_kin_id' => $r->c_kin_id,
                'c_kin_code' => $r->c_kin_code,
                'c_source' => $r->c_source,
                'c_created_by' => $r->c_created_by ?? null,
                'c_created_date' => $r->c_created_date ?? null,
            ]
            : static fn ($r) => [
                'c_personid' => $r->c_personid,
                'c_assoc_id' => $r->c_assoc_id,
                'c_assoc_code' => $r->c_assoc_code,
                'c_text_title' => $r->c_text_title,
                'c_source' => $r->c_source,
                'c_created_by' => $r->c_created_by ?? null,
                'c_created_date' => $r->c_created_date ?? null,
            ];

        return collect($records)->map($mapper)->values()->toArray();
    }

    /** 反向關係列是否已存在（repair 用；定位條件對齊 legacy）。 */
    public function reverseRelationExists(string $type, $relation, array $params): bool {
        if ($type === 'kinship') {
            return DB::table('KIN_DATA')
                ->where('c_personid', $params['related_id'])
                ->where('c_kin_id', $params['person_id'])
                ->where('c_kin_code', $params['new_relation_code'])
                ->exists();
        }

        $relationFirstYear = $relation->c_assoc_first_year ?? self::DEFAULT_ASSOC_FIRST_YEAR;

        return DB::table('ASSOC_DATA')
            ->where('c_personid', $params['related_id'])
            ->where('c_assoc_id', $params['person_id'])
            ->where('c_assoc_code', $params['new_relation_code'])
            ->where('c_kin_code', $relation->c_kin_code)
            ->where('c_kin_id', $relation->c_kin_id)
            ->where('c_assoc_kin_code', $relation->c_assoc_kin_code)
            ->where('c_assoc_kin_id', $relation->c_assoc_kin_id)
            ->where('c_text_title', $relation->c_text_title)
            ->where('c_assoc_first_year', $relationFirstYear)
            ->where('c_assoc_count', $relation->c_assoc_count ?? 1)
            ->where('c_sequence', $relation->c_sequence ?? 0)
            ->exists();
    }

    /** 由正向列建反向列（欄位拷貝；反向碼用 new_relation_code、對方為主體、原人為客體）。供 repair 與行內補建（§4.2）共用。 */
    public function buildReverseRelation(string $type, $relation, array $params): array {
        if ($type === 'kinship') {
            return [
                'c_personid' => $params['related_id'],
                'c_kin_id' => $params['person_id'],
                'c_kin_code' => $params['new_relation_code'],
                'c_source' => $relation->c_source ?? null,
                'c_pages' => $relation->c_pages ?? null,
                'c_notes' => $relation->c_notes ?? null,
                'c_autogen_notes' => $relation->c_autogen_notes ?? null,
            ];
        }

        return [
            'c_personid' => $params['related_id'],
            'c_assoc_id' => $params['person_id'],
            'c_assoc_code' => $params['new_relation_code'],
            'c_kin_code' => $relation->c_kin_code,
            'c_kin_id' => $relation->c_kin_id,
            'c_assoc_kin_code' => $relation->c_assoc_kin_code,
            'c_assoc_kin_id' => $relation->c_assoc_kin_id,
            'c_text_title' => $relation->c_text_title,
            'c_tertiary_personid' => $relation->c_tertiary_personid ?? null,
            'c_tertiary_type_notes' => $relation->c_tertiary_type_notes ?? null,
            'c_assoc_count' => $relation->c_assoc_count ?? 1,
            'c_sequence' => $relation->c_sequence ?? 0,
            'c_assoc_first_year' => $relation->c_assoc_first_year ?? self::DEFAULT_ASSOC_FIRST_YEAR,
            'c_assoc_last_year' => $relation->c_assoc_last_year ?? null,
            'c_assoc_fy_nh_code' => $relation->c_assoc_fy_nh_code ?? null,
            'c_assoc_fy_nh_year' => $relation->c_assoc_fy_nh_year ?? null,
            'c_assoc_fy_range' => $relation->c_assoc_fy_range ?? null,
            'c_assoc_ly_nh_code' => $relation->c_assoc_ly_nh_code ?? null,
            'c_assoc_ly_nh_year' => $relation->c_assoc_ly_nh_year ?? null,
            'c_assoc_ly_range' => $relation->c_assoc_ly_range ?? null,
            'c_assoc_fy_intercalary' => $relation->c_assoc_fy_intercalary ?? null,
            'c_assoc_fy_month' => $relation->c_assoc_fy_month ?? null,
            'c_assoc_fy_day' => $relation->c_assoc_fy_day ?? null,
            'c_assoc_fy_day_gz' => $relation->c_assoc_fy_day_gz ?? null,
            'c_assoc_ly_intercalary' => $relation->c_assoc_ly_intercalary ?? null,
            'c_assoc_ly_month' => $relation->c_assoc_ly_month ?? null,
            'c_assoc_ly_day' => $relation->c_assoc_ly_day ?? null,
            'c_assoc_ly_day_gz' => $relation->c_assoc_ly_day_gz ?? null,
            'c_addr_id' => $relation->c_addr_id ?? null,
            'c_inst_code' => $relation->c_inst_code ?? 0,
            'c_inst_name_code' => $relation->c_inst_name_code ?? 0,
            'c_litgenre_code' => $relation->c_litgenre_code ?? null,
            'c_occasion_code' => $relation->c_occasion_code ?? null,
            'c_topic_code' => $relation->c_topic_code ?? null,
            'c_assoc_claimer_id' => $relation->c_assoc_claimer_id ?? null,
            'c_source' => $relation->c_source ?? null,
            'c_pages' => $relation->c_pages ?? null,
            'c_notes' => $relation->c_notes ?? null,
        ];
    }
}
