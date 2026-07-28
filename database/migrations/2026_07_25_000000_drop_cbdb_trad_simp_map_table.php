<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CBDB__TRAD_SIMP_MAP 已停用：資料改為 vendored 進版控的 third_party/opencc/TSCharacters.txt
 * （OpenCC 原始字典檔，由 `php artisan cbdb:sync-opencc-trad-simp` 更新），App\Support\TradSimpMap
 * 在讀取當下直接解析這份原始檔，不另外產生衍生檔、不寫資料庫，隨部署流程上線，不再由後台
 * 管理員即時觸發下載寫入資料庫。此表已無任何程式碼查詢（原三個消費點——匯入指令、
 * NameSearchIndexService、RebuildNameSearchIndex——皆已改讀 App\Support\TradSimpMap），
 * 亦已從 config/codes.php 的 tables／ui_hidden 移除（連帶不再對 Codes UI、Query
 * Playground、NL 查詢、MCP 只讀工具開放）。內容完全可由 OpenCC 來源重新取得，刪除不會
 * 遺失不可回復的資料。
 */
return new class () extends Migration {
    public function up(): void {
        Schema::dropIfExists('CBDB__TRAD_SIMP_MAP');
    }

    public function down(): void {
        // 內容已改由 third_party/opencc/TSCharacters.txt 提供，回滾只重建空表結構，
        // 不重新灌入資料——如需要，重新執行 App\Support\TradSimpMap::full() 手動回填即可。
        Schema::create('CBDB__TRAD_SIMP_MAP', function (Blueprint $table) {
            $table->binary('trad_char', 4)->primary();
            $table->binary('simp_char', 4);
        });
    }
};
