<?php

namespace App\Exceptions;

/**
 * `char_variant_map` 的結構驗證失敗（單一 codepoint、不成環、payload 完整）。
 *
 * **為什麼需要一個專屬型別而不是直接用 `\RuntimeException`**：
 * `Illuminate\Database\QueryException` 繼承 `PDOException`、而 `PDOException`
 * 繼承 `\RuntimeException`。`CharVariantMapService::assertWritable()` 內部會查兩次
 * `char_variant_map`，所以呼叫端若 `catch (\RuntimeException)`，任何資料庫錯誤都會被
 * 當成「驗證失敗」，把原始 SQLSTATE 與 SQL 字串 flash 給使用者（資訊洩漏 + 無法據以行動
 * 的訊息），而且該次寫入會被靜默跳過而不是誠實地 500。
 *
 * 呼叫端一律只 catch 這個型別，讓真正的資料庫錯誤照常往上冒。
 */
class VariantMappingException extends \RuntimeException {
}
