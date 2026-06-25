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
     * 「指向 $oldKinCode」的合法反向碼集：KINSHIP_CODES 中 c_kin_pair1 或 c_kin_pair2 = $oldKinCode 者
     * （如「父(75)」展開為子/三子/季子/長女…）。空集時退回 $oldKinCode 自身 pair1/pair2 指向。
     * 與 ResolvesKinshipReversePair::legitReversePairCodes / syncKinMirrorOnUpdate 的 legitReverses 同義。
     *
     * ⚠ 注意：這是「指向 $oldKinCode 的碼集」（查詢方向 pair1/pair2 = $oldKinCode），與 validReverseKinSet（$oldKinCode
     * 自身的 pair1/pair2）方向相反、語義不同。kin 鏡像定位用本法（須涵蓋排行子等多碼）；assoc 無排行，定位用 validReverseAssocSet。
     *
     * @return int[]
     */
    public function legitReverseKinCodes($oldKinCode): array {
        if ($oldKinCode === null || (int) $oldKinCode === 0) {
            return [];
        }
        $codes = DB::table('KINSHIP_CODES')
            ->where('c_kin_pair1', $oldKinCode)->orWhere('c_kin_pair2', $oldKinCode)
            ->pluck('c_kincode')->map(static fn ($c) => (int) $c)->all();
        if (!empty($codes)) {
            return $codes;
        }

        return $this->validReverseKinSet($oldKinCode); // 退回自身配對指向
    }

    /**
     * 定位「對面互逆鏡像列」（與 syncKin/AssocMirrorOnUpdate 的嚴格定位器同套條件）。供 §4/§5 缺邊/多條偵測共用。
     *
     * - kinship  $locator：['person_id'(本人), 'opposite_id'(對方), 'autogen_notes', 'forward_code'(正向親屬碼)]
     *   → KIN_DATA where c_kin_id=本人, c_personid=對方, c_autogen_notes=備註, c_kin_code ∈ legitReverseKinCodes(正向碼)。
     * - association $locator：['person_id', 'opposite_id', 'text_title', 'first_year', 'forward_code']
     *   → ASSOC_DATA where c_assoc_id=本人, c_personid=對方, c_text_title=書名, c_assoc_first_year=首年, c_assoc_code ∈ validReverseAssocSet(正向碼)。
     *
     * 回傳命中列 Collection：count()==0 ⇒ 對面缺邊（問題 A）；count()>1 ⇒ 一對多/多對多（問題 B）。
     *
     * ⚠ 與 sync 嚴格定位器的兩處刻意差異（本法為「純偵測」用，較 sync 保守）：
     * (1) assoc 正向碼**無合法配對**（pair 皆 null / 碼缺於 ASSOC_CODES）時，sync 的空 where 群組會「不加碼約束→命中全部」，
     *     本法則 whereIn [-99999] → **命中 0**。對偵測而言正確：無定義反向碼＝無有意義的對面鏡像可言（不誤把他段關係當對面）。
     * (2) kin 正向碼缺於 KINSHIP_CODES（髒碼）時，sync 會 fail-closed 拋 MirrorIntegrityException；本法 legitReverseKinCodes
     *     回 [] → 命中 0（偵測不應因髒碼拋例外）。呼叫端若需 sync 的中止語義須自行處理。
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    public function locateOppositeEdges(string $type, array $locator) {
        if ($type === 'kinship') {
            $reverseCodes = $this->legitReverseKinCodes($locator['forward_code'] ?? null);
            $autogen = $locator['autogen_notes'] ?? null;
            $q = DB::table('KIN_DATA')
                ->where('c_kin_id', $locator['person_id'])
                ->where('c_personid', $locator['opposite_id'])
                ->whereIn('c_kin_code', $reverseCodes ?: [-99999]);
            // c_autogen_notes 的 NULL 與 '' 同為「無自動備註」：前端編輯載入時控制器把 DB NULL 補水成 ''（送 ''），
            // 但既有反向鏡像列的 c_autogen_notes 多為 NULL，精確 `= ''` 比對會漏命中 → 誤報「缺邊」。故空值（null/''）
            // 一律以 (IS NULL OR = '') 比對；非空才精確比對。
            if ($autogen === null || $autogen === '') {
                $q->where(function ($w) {
                    $w->whereNull('c_autogen_notes')->orWhere('c_autogen_notes', '');
                });
            } else {
                $q->where('c_autogen_notes', $autogen);
            }

            return $q->get();
        }

        $reverseCodes = $this->validReverseAssocSet($locator['forward_code'] ?? null);

        return DB::table('ASSOC_DATA')
            ->where('c_assoc_id', $locator['person_id'])
            ->where('c_personid', $locator['opposite_id'])
            ->where('c_text_title', $locator['text_title'] ?? '')
            ->where('c_assoc_first_year', $locator['first_year'] ?? self::DEFAULT_ASSOC_FIRST_YEAR)
            ->whereIn('c_assoc_code', $reverseCodes ?: [-99999])
            ->get();
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
