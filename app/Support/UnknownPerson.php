<?php

namespace App\Support;

/**
 * 「未詳」人物的單一判定。
 *
 * CBDB 以 `c_personid = 0` 表示「未詳」——它不是真實人物，只是資料上的佔位。`-999` 是同一件事的
 * 另一種表達：各寫入路徑落庫前會把它正規化成 0，所以**必須在正規化之前就一併視為未詳**，
 * 否則送 -999 就能繞過守衛、最後仍寫進一條指向 0 的邊（legacy assoc controller 的既有漏洞）。
 *
 * 抽成單一實作的理由：這個判定原本散在四處（mutation trait 的對象檢查、Possession／Posting 的
 * 擁有者檢查、提案核准守衛、複製工具的髒列跳過）。再多一個哨兵值就得改四個地方，而漏改任一處
 * 都是靜默的資料完整性缺口。
 */
final class UnknownPerson {
    /** 「未詳」的哨兵值：0 是落庫後的形式，-999 是正規化前的形式。 */
    private const SENTINELS = [0, -999];

    /**
     * 這個 personid 是否代表「未詳」。
     *
     * **判定必須與「寫入時實際發生的轉型」一致，而不是與「型別看起來對不對」一致。**
     * 關係資源的主鍵只驗缺欄、不驗型別（`CompositePrimaryKey::validateOrFail()`），所以請求可以
     * 送 `c_kin_id: "0e10"` 或 `"-999.0"` 這種字串進來；它們寫進 INTEGER 欄之後**就是 0 和 -999**
     * （PHP 的 `(int)` 與 MariaDB／SQLite 的欄位轉型在這裡一致，已實測）。因此這裡用
     * `is_numeric()` + `(int)` 是**刻意的 fail-closed**——一度改成「只接受嚴格整數語義」，
     * 結果反而放行了 `'0e10'`／`'0.0'`／`'-999.0'`，等於守衛自己開了一個後門。
     *
     * 副作用是 `0.5`、`-999.9` 這種「數值但明顯不是 id」的輸入也會被判為未詳，訊息會是
     * 「不能將『未詳』人物加為…」而不是「型別錯誤」。這是可接受的取捨：它們**不是合法的人物 id**，
     * 而擋下來的代價（訊息不夠精準）遠小於放行的代價（資料庫多一條指向不存在人物的邊）。
     *
     * **非數值一律回 false**（不視為未詳）：null、''、bool、'abc'、array 都屬於「主鍵不完整或
     * 型別錯誤」，由 `CompositePrimaryKey::validateOrFail()` 與欄位驗證負責報錯，本判定不越權
     * （特別是 `(int) 'abc' === 0` 會把任意字串誤判成未詳，必須先擋掉）。
     */
    public static function isUnknown(mixed $value): bool {
        if (!is_numeric($value)) {
            return false;
        }

        return in_array((int) $value, self::SENTINELS, true);
    }
}
