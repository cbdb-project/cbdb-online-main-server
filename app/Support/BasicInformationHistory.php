<?php

namespace App\Support;

/**
 * 人物頁歷史紀錄（audit log／operations）的分頁定義。
 *
 * ⚠️ 下面兩個 map 的**鍵是 legacy 路由名，已無對應路由**（Blade 下架計畫環節 2 刪除）；
 * 以路由名查表的 resolveFromRoute() 也已隨之移除（全庫零呼叫）。留下鍵只是為了保持
 * 陣列結構與可讀的來源標記——**現行的查表入口是 resolveFromPage()**（以 `history_page`
 * 這個 page 值查），值（page／label／tables）仍然全部在服役。
 * 消費者：AdminAuditLogController::resolveHistoryContext()、OperationsController 同名方法。
 */
class BasicInformationHistory {
    private const EXACT_ROUTE_MAP = [
        'basicinformation.edit' => [
            'page' => 'basic',
            'label' => '基本資料',
            'tables' => ['BIOG_MAIN'],
        ],
        'basicinformation.show' => [
            'page' => 'basic',
            'label' => '基本資料',
            'tables' => ['BIOG_MAIN'],
        ],
    ];

    private const PREFIX_ROUTE_MAP = [
        'basicinformation.addresses.' => [
            'page' => 'addresses',
            'label' => '地址',
            'tables' => ['BIOG_ADDR_DATA'],
        ],
        'basicinformation.altnames.' => [
            'page' => 'altnames',
            'label' => '別名',
            'tables' => ['ALTNAME_DATA'],
        ],
        'basicinformation.texts.' => [
            'page' => 'texts',
            'label' => '著述',
            'tables' => ['BIOG_TEXT_DATA'],
        ],
        'basicinformation.offices.' => [
            'page' => 'offices',
            'label' => '官名',
            'tables' => ['POSTED_TO_OFFICE_DATA', 'POSTED_TO_ADDR_DATA'],
        ],
        'basicinformation.entries.' => [
            'page' => 'entries',
            'label' => '入仕',
            'tables' => ['ENTRY_DATA'],
        ],
        'basicinformation.events.' => [
            'page' => 'events',
            'label' => '事件',
            'tables' => ['EVENTS_DATA'],
        ],
        'basicinformation.statuses.' => [
            'page' => 'statuses',
            'label' => '社會區分',
            'tables' => ['STATUS_DATA'],
        ],
        'basicinformation.kinship.' => [
            'page' => 'kinship',
            'label' => '親屬',
            'tables' => ['KIN_DATA'],
        ],
        'basicinformation.assoc.' => [
            'page' => 'assoc',
            'label' => '社會關係',
            'tables' => ['ASSOC_DATA'],
        ],
        'basicinformation.possession.' => [
            'page' => 'possession',
            'label' => '財產',
            'tables' => ['POSSESSION_DATA'],
        ],
        'basicinformation.socialinst.' => [
            'page' => 'socialinst',
            'label' => '社交機構',
            'tables' => ['BIOG_INST_DATA'],
        ],
        'basicinformation.sources.' => [
            'page' => 'sources',
            'label' => '出處',
            'tables' => ['BIOG_SOURCE_DATA'],
        ],
    ];

    public static function resolveFromPage(?string $page): ?array {
        $page = trim((string) $page);
        if ($page === '') {
            return null;
        }

        foreach (array_merge(array_values(self::EXACT_ROUTE_MAP), array_values(self::PREFIX_ROUTE_MAP)) as $definition) {
            if (($definition['page'] ?? null) === $page) {
                return self::normalizeDefinition($definition);
            }
        }

        return null;
    }

    public static function normalizeTables(array $tables): array {
        $allowedTables = self::allTables();
        $normalized = [];

        foreach ($tables as $table) {
            $tableName = strtoupper(trim((string) $table));
            if ($tableName === '' || !in_array($tableName, $allowedTables, true)) {
                continue;
            }
            $normalized[] = $tableName;
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeDefinition(array $definition): array {
        return [
            'page' => trim((string) ($definition['page'] ?? '')),
            'label' => trim((string) ($definition['label'] ?? '')),
            'tables' => self::normalizeTables((array) ($definition['tables'] ?? [])),
        ];
    }

    private static function allTables(): array {
        static $tables = null;

        if ($tables !== null) {
            return $tables;
        }

        $tables = [];
        foreach (array_merge(array_values(self::EXACT_ROUTE_MAP), array_values(self::PREFIX_ROUTE_MAP)) as $definition) {
            foreach ((array) ($definition['tables'] ?? []) as $table) {
                $tableName = strtoupper(trim((string) $table));
                if ($tableName !== '') {
                    $tables[] = $tableName;
                }
            }
        }

        $tables = array_values(array_unique($tables));

        return $tables;
    }
}
