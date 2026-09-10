<?php

namespace App\Services\Mutations;

/**
 * 自參照層級樹的環路守衛在**交易內**擋下的競態。
 *
 * 為什麼需要一個專屬例外：交易內的複查（鎖住走訪路徑那一次）必須以拋例外的方式讓
 * `DB::transaction()` 回滾，但呼叫端要能把它與資料庫例外分開、轉成 422 `tree_cycle`
 * 而不是 500。用 RuntimeException 的話會與其他既有的 runtime 錯誤混在一起。
 */
class TreeCycleException extends \RuntimeException {
}
