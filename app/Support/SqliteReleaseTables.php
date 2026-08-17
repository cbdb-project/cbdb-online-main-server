<?php

namespace App\Support;

/**
 * 對外釋出的 SQLite **允許出現的表**（#1251）。
 *
 * 這是產物自檢（`cbdb:assert-sqlite-release-scope`）的 oracle：釋出檔裡的每一張表／檢視都必須
 * 出現在這份清單裡，否則自檢失敗。刻意用「精確集合」而不是「名稱形狀」（例如「全大寫就算公開」）：
 * 形狀判準會放行任何日後新增的全大寫表——`AUDIT_LOG_ARCHIVE`、`USER_LOGIN_EVENTS` 這種名字一樣
 * 全大寫，卻是個資。範圍擴張必須是一次刻意、可 review 的改動。
 *
 * **與 `scripts/export-daily-sqlite.sh` 的 `TABLES=(...)` 是兩份清單，必須完全一致**：
 * 那份 shell 陣列驅動逐表匯出（釋出範圍的「意圖」），這份常數是驗收產物的「事實」。
 * 兩者由 SqliteReleaseAllowlistTest::the_release_script_allowlist_matches_the_release_table_constant()
 * 逐項比對，任何一邊漏改都會紅。新增 CBDB 表時兩邊都要加。
 *
 * 為什麼不讓 shell 腳本直接讀這份常數（那樣就只有一份）：釋出腳本要在匯出**之前**就知道表清單，
 * 若改成 `mapfile -t TABLES < <(php artisan …)`，命令失敗時 TABLES 會靜默變成空陣列（`mapfile` 的
 * 結束碼不反映 process substitution 裡的失敗），釋出範圍就會由執行環境決定。維持兩份 + 相等測試
 * 反而更難出錯。
 */
final class SqliteReleaseTables {
    /**
     * 允許釋出的 CBDB 資料表與代碼表（目前 77 張）。
     *
     * @var array<int,string>
     */
    public const PUBLIC_TABLES = [
        'ADDR_BELONGS_DATA',
        'ADDR_CODES',
        'ADMIN_CAT_CODES',
        'ADMIN_CAT_CODE_TYPE_REL',
        'ADMIN_CAT_TYPES',
        'ALTNAME_CODES',
        'ALTNAME_DATA',
        'APPOINTMENT_CODES',
        'APPOINTMENT_CODE_TYPE_REL',
        'APPOINTMENT_TYPES',
        'ASSOC_CODES',
        'ASSOC_CODE_TYPE_REL',
        'ASSOC_DATA',
        'ASSOC_TYPES',
        'ASSUME_OFFICE_CODES',
        'BIOG_ADDR_CODES',
        'BIOG_ADDR_DATA',
        'BIOG_INST_CODES',
        'BIOG_INST_DATA',
        'BIOG_MAIN',
        'BIOG_SOURCE_DATA',
        'BIOG_TEXT_DATA',
        'CHORONYM_CODES',
        'COUNTRY_CODES',
        'DYNASTIES',
        'ENTRY_CODES',
        'ENTRY_CODE_TYPE_REL',
        'ENTRY_DATA',
        'ENTRY_TYPES',
        'ETHNICITY_TRIBE_CODES',
        'EVENTS_ADDR',
        'EVENTS_DATA',
        'EVENT_CODES',
        'EXTANT_CODES',
        'GANZHI_CODES',
        'HOUSEHOLD_STATUS_CODES',
        'INDEXYEAR_TYPE_CODES',
        'KINSHIP_CODES',
        'KIN_DATA',
        'KIN_MOURNING',
        'KIN_MOURNING_STEPS',
        'LITERARYGENRE_CODES',
        'MEASURE_CODES',
        'MERGED_PERSON_DATA',
        'NIAN_HAO',
        'OCCASION_CODES',
        'OFFICE_CATEGORIES',
        'OFFICE_CODES',
        'OFFICE_CODE_TYPE_REL',
        'OFFICE_TYPE_TREE',
        'PARENTAL_STATUS_CODES',
        'POSSESSION_ACT_CODES',
        'POSSESSION_ADDR',
        'POSSESSION_DATA',
        'POSTED_TO_ADDR_DATA',
        'POSTED_TO_OFFICE_DATA',
        'POSTING_DATA',
        'SCHOLARLYTOPIC_CODES',
        'SOCIAL_INSTITUTION_ADDR',
        'SOCIAL_INSTITUTION_ADDR_TYPES',
        'SOCIAL_INSTITUTION_ALTNAME_CODES',
        'SOCIAL_INSTITUTION_ALTNAME_DATA',
        'SOCIAL_INSTITUTION_CODES',
        'SOCIAL_INSTITUTION_NAME_CODES',
        'SOCIAL_INSTITUTION_TYPES',
        'STATUS_CODES',
        'STATUS_CODE_TYPE_REL',
        'STATUS_DATA',
        'STATUS_TYPES',
        'TEXT_BIBLCAT_CODES',
        'TEXT_BIBLCAT_CODE_TYPE_REL',
        'TEXT_BIBLCAT_TYPES',
        'TEXT_CODES',
        'TEXT_INSTANCE_DATA',
        'TEXT_ROLE_CODES',
        'TEXT_TYPE',
        'YEAR_RANGE_CODES',
    ];
}
