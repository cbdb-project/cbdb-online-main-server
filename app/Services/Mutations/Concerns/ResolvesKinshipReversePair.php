<?php

namespace App\Services\Mutations\Concerns;

use App\Models\KinshipCode;
use Illuminate\Support\Facades\DB;

/**
 * 親屬互逆鏡像「反向關係碼」解析（KinshipCreate/MutationHandler 共用）。
 *
 * 背景：反向關係常有歧義須由使用者選擇（如父→子或女、第幾子…）。legacy 以 c_kinship_pair
 * 讓使用者從 searchKinPair 候選集挑選；先前 v2 為修污染 bug 改成一律權威推導 c_kin_pair1、
 * 完全不採信前端，連帶移除了使用者的合法選擇權。
 *
 * 本 trait 折衷：未提供覆寫＝沿用權威預設 c_kin_pair1（行為與既有/測試一致）；提供覆寫＝
 * 必須是「此正向碼的合法配對候選」之一（對齊 ApiController::searchKinPair），否則丟例外（呼叫端
 * 轉 422、整筆回滾）——既恢復使用者選擇權，又維持 fail-closed、杜絕對鏡像列強塞任意反向碼。
 */
trait ResolvesKinshipReversePair {
    /** 親屬碼的權威預設反向碼（KINSHIP_CODES.c_kin_pair1）；0／查無 → null。 */
    protected function lookupKinPair($code): ?int {
        if ($code === null || $code === '' || (int) $code === 0) {
            return null;
        }
        $v = DB::table('KINSHIP_CODES')->where('c_kincode', $code)->value('c_kin_pair1');

        return $v !== null ? (int) $v : null;
    }

    /**
     * 解析本次鏡像要寫入的反向親屬碼。
     * - $clientReverse 為 null/空/0 → 回權威預設 c_kin_pair1（不採信、行為不變）。
     * - 否則須 ∈ 合法配對候選集，違反即丟 \InvalidArgumentException（呼叫端轉 422、回滾）。
     */
    protected function resolveReversePair($kinCode, $clientReverse): ?int {
        $default = $this->lookupKinPair($kinCode);
        if ($clientReverse === null || $clientReverse === '' || (int) $clientReverse === 0) {
            return $default;
        }
        $reverse = (int) $clientReverse;
        if (!in_array($reverse, $this->legitReversePairCodes($kinCode), true)) {
            throw new \InvalidArgumentException('反向親屬碼非此關係的合法配對，請從建議清單中選擇。');
        }

        return $reverse;
    }

    /**
     * 合法反向碼候選集（對齊 ApiController::searchKinPair）：
     * KINSHIP_CODES 中 c_kin_pair1 或 c_kin_pair2 等於該碼者；空集時退回該碼自身 pair1/pair2 指向。
     *
     * @return int[]
     */
    protected function legitReversePairCodes($kinCode): array {
        if ($kinCode === null || $kinCode === '' || (int) $kinCode === 0) {
            return [];
        }
        $codes = KinshipCode::where('c_kin_pair1', '=', $kinCode)
            ->orWhere('c_kin_pair2', '=', $kinCode)
            ->pluck('c_kincode');

        if ($codes->isEmpty()) {
            $self = KinshipCode::find($kinCode);
            if ($self) {
                $pairCodes = array_filter([$self->c_kin_pair1, $self->c_kin_pair2]);
                $codes = !empty($pairCodes)
                    ? KinshipCode::whereIn('c_kincode', $pairCodes)->pluck('c_kincode')
                    : collect();
            }
        }

        return $codes->map(fn ($c) => (int) $c)->all();
    }
}
