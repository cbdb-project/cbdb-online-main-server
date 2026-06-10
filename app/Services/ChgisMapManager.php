<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * CHGIS 底圖（chgis_map.mbtiles）檔案管理
 *
 * 負責本地底圖檔的存在性判定與自 HuggingFace 串流下載。
 * 由 cbdb:fetch-chgis-map 指令與 lazy 下載 Job 共用。
 * 設定見 config/chgis_map.php，設計見 docs/CHGIS_MAP_PLACE_LINK.md §4。
 */
class ChgisMapManager {
    /** SQLite/mbtiles 檔頭魔術位元組（16 bytes，含結尾 NUL）。 */
    private const SQLITE_MAGIC = "SQLite format 3\0";

    /** 本地底圖檔絕對路徑。 */
    public function path(): string {
        return (string) config('chgis_map.source.path');
    }

    /** 下載來源 URL（HuggingFace resolve raw）。 */
    public function sourceUrl(): string {
        return (string) config('chgis_map.source.url');
    }

    /** 視為有效檔案的體積下限（位元組）。 */
    public function expectedMinBytes(): int {
        return (int) config('chgis_map.source.expected_min_bytes', 5_000_000);
    }

    /**
     * 底圖是否就緒（存在、體積達下限、且為合法 SQLite/mbtiles 格式）。
     *
     * 同時驗證魔術位元組，避免體積達標的 HTML 錯誤頁／LFS 指標／損壞檔
     * 被誤判為就緒而永久跳過下載。
     */
    public function isReady(): bool {
        $path = $this->path();

        return is_file($path)
            && filesize($path) >= $this->expectedMinBytes()
            && $this->hasSqliteMagic($path);
    }

    /**
     * 串流下載底圖到本地（原子寫入）。
     *
     * 下載到唯一命名的 *.part 暫存檔（與正式檔同目錄以保證 rename 原子性），
     * 依序驗證 HTTP 狀態、體積下限、SQLite 魔術位元組後，才 rename 為正式檔，
     * 避免半截檔或 HTML 錯誤頁被視為就緒。
     *
     * 注意：本方法非並發安全——唯一 .part 命名可避免並發互相寫壞同一暫存檔，
     * 但仍可能重複下載。需要互斥時請由呼叫端加鎖（見 docs §4.6 P3）。
     *
     * @throws RuntimeException 下載失敗或檔案不完整／格式不符時
     */
    public function download(): void {
        $path = $this->path();
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("無法建立目錄：{$dir}");
        }

        // 唯一暫存檔名，避免並發下載互相覆寫造成交錯壞檔。
        $partPath = $path . '.part.' . getmypid() . '.' . bin2hex(random_bytes(4));

        try {
            try {
                $response = Http::timeout((int) config('chgis_map.source.timeout', 1800))
                    ->connectTimeout(30)
                    ->sink($partPath)
                    ->get($this->sourceUrl());
            } catch (\Throwable $e) {
                throw new RuntimeException('CHGIS 底圖下載失敗：' . $e->getMessage(), 0, $e);
            }

            if (!$response->successful()) {
                throw new RuntimeException('CHGIS 底圖下載失敗，HTTP 狀態碼：' . $response->status());
            }

            clearstatcache(true, $partPath);
            $size = is_file($partPath) ? filesize($partPath) : 0;
            if ($size < $this->expectedMinBytes()) {
                throw new RuntimeException(sprintf(
                    'CHGIS 底圖下載不完整：實際 %d bytes，低於下限 %d bytes',
                    $size,
                    $this->expectedMinBytes()
                ));
            }

            if (!$this->hasSqliteMagic($partPath)) {
                throw new RuntimeException('CHGIS 底圖內容非 mbtiles（SQLite）格式，可能是錯誤頁或損壞檔');
            }

            // 原子替換，且不可在失敗時遺失既有可用底圖：
            // Windows 的 rename 不覆蓋既存目標，故先把舊檔改名為備份，
            // 新檔就位後才刪備份；任一步失敗則還原備份。
            $backup = null;
            if (is_file($path)) {
                $backup = $path . '.bak.' . getmypid() . '.' . bin2hex(random_bytes(4));
                if (!@rename($path, $backup)) {
                    throw new RuntimeException("無法備份既有底圖：{$path}");
                }
            }

            if (!@rename($partPath, $path)) {
                $restoreFailed = false;
                if ($backup !== null) {
                    $restoreFailed = !@rename($backup, $path);
                }

                $message = "無法將暫存檔移動為正式檔：{$path}";
                if ($restoreFailed) {
                    $message .= '，且無法還原備份檔';
                }

                throw new RuntimeException($message);
            }

            if ($backup !== null) {
                @unlink($backup);
            }
        } finally {
            // 任一失敗路徑都清掉自己的暫存檔；成功 rename 後 partPath 已不存在。
            if (is_file($partPath)) {
                @unlink($partPath);
            }
        }
    }

    /**
     * 檢查檔案開頭是否為 SQLite 魔術位元組。
     */
    private function hasSqliteMagic(string $file): bool {
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return false;
        }

        try {
            $header = fread($fh, strlen(self::SQLITE_MAGIC));
        } finally {
            fclose($fh);
        }

        return $header === self::SQLITE_MAGIC;
    }
}
