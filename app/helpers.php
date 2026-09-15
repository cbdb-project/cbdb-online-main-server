<?php

if (!function_exists('get_app_version')) {
    /**
     * 获取应用版本号（基于 Git commit ID）
     *
     * @return string 返回短版本的 commit ID（前7位）或 'unknown'
     */
    function get_app_version() {
        // 尝试从缓存中获取版本号（避免频繁读取文件或执行 git 命令）
        $cacheKey = 'app_version';
        $cachedVersion = \Cache::get($cacheKey);

        if ($cachedVersion !== null) {
            return $cachedVersion;
        }

        $version = 'unknown';

        try {
            // 优先从 version.txt 文件读取（适用于生产环境）
            $versionFile = base_path('version.txt');
            if (file_exists($versionFile)) {
                $version = trim(file_get_contents($versionFile));
            }

            // 如果文件不存在或为空，尝试从 Git 获取（适用于开发环境）
            if (empty($version) || $version === 'unknown') {
                $gitVersion = trim(shell_exec('git rev-parse --short=7 HEAD 2>/dev/null') ?? '');
                if (!empty($gitVersion)) {
                    $version = $gitVersion;
                }
            }

            // 缓存版本号10分钟
            \Cache::put($cacheKey, $version, now()->addMinutes(10));

            return $version;
        } catch (\Exception $e) {
            return 'unknown';
        }
    }
}

// ── 2026-09-15（Blade 下架環節 4d）─────────────────────────────────────────
//
// 這裡原本有 `migration_flag()` 與 `migration_flag_is_new()`（讀 config/migration_flags.php）。
// 所有 legacy Blade 頁面都已實體刪除（環節 2／4a／4b-4／4c），flag 對渲染早就沒有作用；
// 4d 把最後幾個「連結指向」的分支也收斂掉之後，這兩個函式與那份 config 都成了死碼，一併移除。
//
// 🔴 **要回到 Blade 只能 git revert 並重新部署**——沒有任何 runtime 開關。
// 護欄：
//   - LegacyBladePageRetirementTest::the_migration_flag_mechanism_no_longer_exists()（機制不存在）
//   - NavigationSchemaTest::test_every_sidebar_href_points_at_the_react_app()（側邊欄每條 href）
//   - OperationsIndexLinksTest::test_code_resource_view_link_ignores_the_codes_flag()
//     （`code_table_edit_url()`——**不是** FlagAwareUrlHelpersTest，那支只驗 person_* helper）

if (!function_exists('person_page_url')) {
    /**
     * 人物列表搜尋頁 URL。
     *
     * 原本依 `basicinformation.index` flag 在 React／legacy 之間切換；Blade 下架計畫環節 2
     * 刪除 legacy 人物頁後那個分支只會導到一條 302（多一跳），故收斂為直接回 React 路由。
     *
     * @param array<string, scalar|null> $params
     */
    function person_index_url(array $params = []): string {
        $params = array_filter($params, fn ($value) => $value !== null && $value !== '');

        return route('app.basicinformation.index', $params, false);
    }
}

if (!function_exists('person_show_base_url')) {
    /** 人物詳情頁 base URL（供前端自行組 `/{id}` 用）。 */
    function person_show_base_url(): string {
        return '/app/basicinformation';
    }
}

if (!function_exists('person_index_base_url')) {
    /** 人物列表頁 base URL（供前端自行組 `?q=` 用）。 */
    function person_index_base_url(): string {
        return '/app/basicinformation';
    }
}

if (!function_exists('person_page_url')) {
    /**
     * 人物頁 URL（供頁內連結統一使用，避免各處寫死 /basicinformation/{id}）。
     *
     * @param int|string $id 人物 c_personid
     * @param string     $type 'edit'（預設）| 'show'
     */
    function person_page_url($id, string $type = 'edit'): string {
        return $type === 'show'
            ? route('app.basicinformation.show', ['id' => $id], false)
            : route('app.basicinformation.edit', ['id' => $id], false);
    }
}

if (!function_exists('code_table_edit_url')) {
    /**
     * 代碼表編輯頁 URL：一律指 React 版。
     *
     * ── 2026-09-15（Blade 下架環節 4d）─────────────────────────────
     * 原本依 codes flag 二選一。legacy Blade 編輯頁已於環節 4b-4a 實體刪除，
     * flag 對渲染完全沒有作用 ⇒ 分支移除。
     *
     * @param string     $table 代碼表名
     * @param int|string $id    codes 編輯頁的 path id（單值、'_._' 複合鍵，或 operations 存的
     *                          query-string 格式；後者由 CodesController::buildConditionsFromId 解析）
     */
    function code_table_edit_url(string $table, $id): string {
        return route('app.codes.edit', ['table_name' => $table, 'id' => $id], false);
    }
}

if (!function_exists('person_create_url')) {
    /** 新增人物頁 URL。 */
    function person_create_url(): string {
        return route('app.basicinformation.create', [], false);
    }
}

// 聯合主鍵保留字弱點防禦函式。
// 歷史上定義在 resources/views/biogmains/defense.blade.php（@include 時載入），
// 後來移至此處統一自動載入，供控制器（如 OperationsController::serializeOperationRow）共用。
// 那個 Blade 檔已隨 Blade 下架環節 2 刪除，所以本處是唯一定義（原本的 function_exists
// 守衛只是過渡期用來避免重複載入，現在沒有第二個載入點了）。
if (!function_exists('unionPKDef')) {
    function unionPKDef($key) {
        $key = str_replace("/", "(slash)", $key);
        //因為反斜線在php有用途, 兩個反斜線代表一個反斜線.
        $key = str_replace("\\", "(backslash)", $key);
        $key = str_replace("{", "(brackets)", $key);
        $key = str_replace("}", "(brackets_r)", $key);
        // URL 特殊字符處理：? 會被解析為查詢字符串開始，# 會被解析為錨點，& 會被解析為參數分隔符
        $key = str_replace("?", "(question)", $key);
        $key = str_replace("#", "(hash)", $key);
        $key = str_replace("&", "(amp)", $key);
        // 複合主鍵分隔符處理：- 是複合主鍵的分隔符，必須編碼以避免解析錯誤
        $key = str_replace("-", "(minus)", $key);

        return $key;
    }
}

if (!function_exists('unionPKDef_decode')) {
    function unionPKDef_decode($key) {
        $key = str_replace("(slash)", "/", $key);
        $key = str_replace("(backslash)", "\\", $key);
        $key = str_replace("(brackets)", "{", $key);
        $key = str_replace("(brackets_r)", "}", $key);
        $key = str_replace("(question)", "?", $key);
        $key = str_replace("(hash)", "#", $key);
        $key = str_replace("(amp)", "&", $key);
        $key = str_replace("(minus)", "-", $key);

        return $key;
    }
}

if (!function_exists('unionPKDef_decode_for_convert')) {
    function unionPKDef_decode_for_convert($key) {
        $key = str_replace("(slash)", "/", $key);
        $key = str_replace("(backslash)", "\\", $key);
        $key = str_replace("(brackets)(brackets)", "{ { ", $key);
        $key = str_replace("(brackets)", "{", $key);
        $key = str_replace("(brackets_r)(brackets_r)", "} } ", $key);
        $key = str_replace("(brackets_r)", "}", $key);
        $key = str_replace("(question)", "?", $key);
        $key = str_replace("(hash)", "#", $key);
        $key = str_replace("(amp)", "&", $key);
        $key = str_replace("(minus)", "-", $key);

        return $key;
    }
}

if (!function_exists('unionPKDef_for_url')) {
    function unionPKDef_for_url($compositePK) {
        if (empty($compositePK)) {
            return $compositePK;
        }
        $parts = explode("-", $compositePK);
        foreach ($parts as $key => $value) {
            $parts[$key] = unionPKDef($value);
        }

        return implode("-", $parts);
    }
}
