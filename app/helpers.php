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
