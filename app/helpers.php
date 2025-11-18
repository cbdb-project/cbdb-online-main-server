<?php

if (!function_exists('get_app_version')) {
    /**
     * 获取应用版本号（基于 Git commit ID）
     *
     * @return string 返回短版本的 commit ID（前7位）或 'unknown'
     */
    function get_app_version()
    {
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
