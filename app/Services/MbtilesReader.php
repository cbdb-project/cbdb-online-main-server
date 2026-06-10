<?php

namespace App\Services;

use PDO;

/**
 * MBTiles 讀取器
 *
 * 以唯讀方式開啟 mbtiles（SQLite 容器），依 z/x/y 取出 PNG 圖磚。
 * MBTiles 採 TMS 排列，y 軸與 XYZ/Leaflet 相反，故需翻轉 tile_row。
 * 設計見 docs/CHGIS_MAP_PLACE_LINK.md §5.2。
 */
class MbtilesReader {
    private ?PDO $pdo = null;

    public function __construct(private readonly string $path) {
    }

    /** mbtiles 檔是否存在。 */
    public function isAvailable(): bool {
        return is_file($this->path);
    }

    /**
     * 取出 z/x/y（XYZ 慣例）對應的圖磚 PNG 位元組；不存在回 null。
     */
    public function tile(int $z, int $x, int $y): ?string {
        // XYZ → TMS：翻轉 y 軸
        $tmsY = (1 << $z) - 1 - $y;

        $stmt = $this->pdo()->prepare(
            'SELECT tile_data FROM tiles WHERE zoom_level = ? AND tile_column = ? AND tile_row = ? LIMIT 1'
        );
        $stmt->execute([$z, $x, $tmsY]);
        $data = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $data === false ? null : $data;
    }

    /**
     * 延遲建立唯讀 PDO 連線（同一實例重用，降低逐磚開檔成本）。
     */
    private function pdo(): PDO {
        if ($this->pdo === null) {
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
            // 以唯讀模式開啟（若 PDO 版本支援），避免產生 wal/shm 或鎖檔。
            if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS') && defined('PDO::SQLITE_OPEN_READONLY')) {
                $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
            }

            $this->pdo = new PDO('sqlite:' . $this->path, null, null, $options);
        }

        return $this->pdo;
    }
}
