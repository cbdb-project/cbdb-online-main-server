<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class ViewTableService {
    /**
     * 列出所有 view table 定義，依 primary alias 排序。
     */
    public function listDefinitions(): Collection {
        $definitions = Config::get('view_tables', []);

        return collect($definitions)->map(function ($definition, $key) {
            $aliases = Arr::get($definition, 'aliases', []);
            $primaryAlias = $aliases[0] ?? ('View_' . Str::studly(str_replace('-', '_', $key)));

            $translationKey = 'views.view_' . str_replace('posessions', 'possessions', str_replace('-', '_', $key));
            $translatedTitle = trans($translationKey);
            // Localized title (follows current locale, used for ENG column)
            $title = (is_string($translatedTitle) && $translatedTitle !== $translationKey)
                ? $translatedTitle
                : Arr::get($definition, 'title', $key);
            // Original Chinese title from config (always zh-TW, used for CHN column)
            $titleChn = Arr::get($definition, 'title', $key);

            return [
                'key' => $key,
                'primary_alias' => $primaryAlias,
                'title' => $title,
                'title_chn' => $titleChn,
                'description' => Arr::get($definition, 'description', ''),
                'aliases' => $aliases,
                'column_count' => count(Arr::get($definition, 'columns', [])),
            ];
        })->sortBy(function ($item) {
            return Str::lower($item['primary_alias']);
        })->values();
    }

    /**
     * 根據 key 或 alias 解析出 view table 定義（case-insensitive）。
     *
     * @return array{definition: array, resolved_key: string}|null
     */
    public function resolveDefinition(string $key): ?array {
        $definitions = Config::get('view_tables', []);

        foreach ($definitions as $definitionKey => $candidate) {
            $candidates = array_merge([$definitionKey], Arr::get($candidate, 'aliases', []));
            foreach ($candidates as $alias) {
                if (strcasecmp($alias, $key) === 0) {
                    return [
                        'definition' => $candidate,
                        'resolved_key' => $definitionKey,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * 建立完整的 view data 供 Inertia page 使用。
     *
     * @return array{title: string, description: string|null, columns: array, rows: array, key: string, filters: array, pagination: array, debug: array}
     */
    public function buildViewData(string $key, Request $request): ?array {
        $resolved = $this->resolveDefinition($key);
        if ($resolved === null) {
            return null;
        }

        $definition = $resolved['definition'];
        $effectiveKey = $resolved['resolved_key'];

        $builderCallable = $definition['builder'] ?? null;
        if (!is_callable($builderCallable)) {
            return null;
        }

        $builder = call_user_func($builderCallable, $request);

        $search = $request->query('search', '');
        if ($search !== '' && $search !== null) {
            $searchable = Config::get('view_table_searchable.' . $effectiveKey, []);
            if (!empty($searchable)) {
                $term = '%' . $search . '%';
                $builder->where(function ($query) use ($searchable, $term) {
                    foreach ($searchable as $column) {
                        $query->orWhere($column, 'like', $term);
                    }
                });
            }
        }

        $perPage = (int) ($definition['page_size'] ?? 50);
        $perPage = $perPage > 0 ? $perPage : 50;
        $currentPage = max((int) $request->query('page', 1), 1);

        // Build debug SQL
        $debugQuery = clone $builder;
        $debugQuery->limit($perPage)->offset(($currentPage - 1) * $perPage);
        $debugSql = $debugQuery->toSql();
        $debugBindings = $debugQuery->getBindings();
        $debugRenderedSql = $this->renderSql($debugSql, $debugBindings);

        // Paginate
        $paginator = $builder->paginate($perPage)->appends($request->except('page'));

        // Convert paginator to React-friendly format
        $columns = $definition['columns'] ?? [];
        $columnKeys = array_keys($columns);

        $rows = collect($paginator->items())->map(function ($row) use ($columnKeys) {
            $item = [];
            foreach ($columnKeys as $col) {
                $item[$col] = data_get($row, $col);
            }

            return $item;
        })->all();

        $translationKey = 'views.view_' . str_replace('posessions', 'possessions', str_replace('-', '_', $effectiveKey));
        $translatedTitle = trans($translationKey);
        $localizedTitle = (is_string($translatedTitle) && $translatedTitle !== $translationKey)
            ? $translatedTitle
            : ($definition['title'] ?? $effectiveKey);

        return [
            'title' => $localizedTitle,
            'description' => $definition['description'] ?? null,
            'columns' => $columns,
            'rows' => $rows,
            'key' => $effectiveKey,
            'primary_alias' => ($definition['aliases'][0] ?? ('View_' . Str::studly(str_replace('-', '_', $effectiveKey)))),
            'aliases' => Arr::get($definition, 'aliases', []),
            'column_count' => count($columns),
            'filters' => [
                'search' => $search ?: '',
            ],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'debug' => [
                'sql' => $debugSql,
                'rendered_sql' => $debugRenderedSql,
                'bindings' => $debugBindings,
                'per_page' => $perPage,
                'current_page' => $currentPage,
            ],
        ];
    }

    /**
     * 將 SQL 中的 ? 佔位符替換為實際的 binding 值。
     *
     * @see renderSql() 的別名，供 Controller 呼叫使用。
     */
    public function formatSql(string $sql, array $bindings): string {
        return $this->renderSql($sql, $bindings);
    }

    /**
     * 將 SQL 中的 ? 佔位符替換為實際的 binding 值。
     */
    public function renderSql(string $sql, array $bindings): string {
        if (empty($bindings)) {
            return $sql;
        }

        $preparedBindings = array_map(function ($binding) {
            if ($binding === null) {
                return 'NULL';
            }
            if (is_bool($binding)) {
                return $binding ? '1' : '0';
            }
            if (is_int($binding) || is_float($binding)) {
                return (string) $binding;
            }

            return "'" . str_replace("'", "''", $binding) . "'";
        }, $bindings);

        return Str::replaceArray('?', $preparedBindings, $sql);
    }
}
