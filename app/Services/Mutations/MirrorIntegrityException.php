<?php

namespace App\Services\Mutations;

use RuntimeException;

/**
 * 鏡像同步「資料完整性 fail-closed」中止（#70）。
 *
 * 當後台雙向鏡像同步偵測到無法安全進行的資料完整性問題（如 KINSHIP_CODES 缺配對碼、或偵測到對面疑似漂移列但
 * 正向碼無任何權威反向碼可收斂）時拋出。在 handleDirect 的 DB::transaction 內拋出 → 整筆回滾（含正向列），
 * 避免單邊寫入／假成功（200 卻什麼都沒修）。由 handleDirect 捕獲後轉成結構化 422，而非裸 RuntimeException 漏成 500。
 *
 * 僅 v2 direct 路徑會被 handleDirect 轉譯；legacy 直呼 repository 時維持未捕獲（行為不變）。
 */
class MirrorIntegrityException extends RuntimeException {
}
