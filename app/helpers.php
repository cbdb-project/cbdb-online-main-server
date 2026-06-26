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

if (!function_exists('migration_flag')) {
    /**
     * 取得遷移頁面 feature flag 值（'old' | 'new'）。
     * 見 config/migration_flags.php 與 docs/REACT_INERTIA_MIGRATION_PLAN.md §五之二。
     *
     * @param string $page 頁面 key（如 'dashboard'、'admin.audit-logs'）
     * @return string 'old' 或 'new'
     */
    function migration_flag(string $page): string {
        $value = config("migration_flags.pages.$page");

        if ($value === null) {
            $value = config('migration_flags.default', 'old');
        }

        return $value === 'new' ? 'new' : 'old';
    }
}

if (!function_exists('migration_flag_is_new')) {
    /**
     * 該遷移頁面是否已切換到新 React/Inertia 版本。
     *
     * @param string $page 頁面 key
     * @return bool
     */
    function migration_flag_is_new(string $page): bool {
        return migration_flag($page) === 'new';
    }
}

if (!function_exists('person_page_url')) {
    /**
     * 人物列表搜尋頁 flag-aware URL。
     *
     * @param array<string, scalar|null> $params
     */
    function person_index_url(array $params = []): string {
        $params = array_filter($params, fn ($value) => $value !== null && $value !== '');

        return (migration_flag_is_new('basicinformation.index') && \Illuminate\Support\Facades\Route::has('app.basicinformation.index'))
            ? route('app.basicinformation.index', $params, false)
            : route('basicinformation.index', $params, false);
    }
}

if (!function_exists('person_show_base_url')) {
    /** 人物詳情頁 base URL（供前端自行組 `/{id}` 用）。 */
    function person_show_base_url(): string {
        return (migration_flag_is_new('basicinformation.show') && \Illuminate\Support\Facades\Route::has('app.basicinformation.show'))
            ? '/app/basicinformation'
            : '/basicinformation';
    }
}

if (!function_exists('person_index_base_url')) {
    /** 人物列表頁 base URL（供前端自行組 `?q=` 用）。 */
    function person_index_base_url(): string {
        return (migration_flag_is_new('basicinformation.index') && \Illuminate\Support\Facades\Route::has('app.basicinformation.index'))
            ? '/app/basicinformation'
            : '/basicinformation';
    }
}

if (!function_exists('person_page_url')) {
    /**
     * 人物頁 flag-aware URL：對應 flag=new 時導向 React /app 版，否則 legacy（供頁內連結統一使用，
     * 避免各處寫死 /basicinformation/{id} 造成「新介面點人物卻開舊頁」）。
     *
     * @param int|string $id 人物 c_personid
     * @param string     $type 'edit'（預設）| 'show'
     */
    function person_page_url($id, string $type = 'edit'): string {
        if ($type === 'show') {
            return (migration_flag_is_new('basicinformation.show') && \Illuminate\Support\Facades\Route::has('app.basicinformation.show'))
                ? route('app.basicinformation.show', ['id' => $id], false)
                : route('basicinformation.show', ['basicinformation' => $id], false);
        }

        return (migration_flag_is_new('basicinformation.editor') && \Illuminate\Support\Facades\Route::has('app.basicinformation.edit'))
            ? route('app.basicinformation.edit', ['id' => $id], false)
            : route('basicinformation.edit', ['basicinformation' => $id], false);
    }
}

if (!function_exists('person_create_url')) {
    /** 新增人物頁 flag-aware URL：編輯器 flag=new 時導向 React 建立頁，否則 legacy。 */
    function person_create_url(): string {
        return (migration_flag_is_new('basicinformation.editor') && \Illuminate\Support\Facades\Route::has('app.basicinformation.create'))
            ? route('app.basicinformation.create', [], false)
            : route('basicinformation.create', [], false);
    }
}

// 聯合主鍵保留字弱點防禦函式。
// 原僅定義於 resources/views/biogmains/defense.blade.php（@include 時載入），
// 移至此處統一自動載入，供控制器（如 OperationsController::serializeOperationRow）共用；
// defense.blade.php 仍以 function_exists 守衛，重複載入時自動略過，行為一致。
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
