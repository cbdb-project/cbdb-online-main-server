<?php

namespace App\Support;

class CodesTableDescription {
    /**
     * Resolve a code table's description for the current locale.
     *
     * Prefers the `codes.table_desc.<TABLE>` translation (en/zh-TW), falling back to the
     * raw config/codes.php value when no translation key exists. `trans()` returns the key
     * itself on a miss (and an array for a group key), so both are guarded.
     *
     * Shared by CodesRepository (/app/codes list) and QueryPlaygroundService (QBE table
     * list / schema panel) so a single lookup rule governs every user-facing surface.
     */
    public static function for(string $table): string {
        $key = 'codes.table_desc.' . $table;
        $translated = trans($key);

        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }

        return (string) config("codes.tables.{$table}", '');
    }
}
