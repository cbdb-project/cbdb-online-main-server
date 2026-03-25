<?php

namespace App\Http\Controllers;

use App\Services\ViewTableService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ViewTableController extends Controller {
    public function __construct(
        private readonly ViewTableService $viewTableService
    ) {
    }

    public function index() {
        $definitions = Config::get('view_tables', []);

        $items = collect($definitions)->map(function ($definition, $key) {
            $aliases = Arr::get($definition, 'aliases', []);
            $primaryAlias = $aliases[0] ?? ('View_' . Str::studly(str_replace('-', '_', $key)));

            return [
                'key' => $key,
                'primary_alias' => $primaryAlias,
                'title' => Arr::get($definition, 'title', $key),
                'description' => Arr::get($definition, 'description', ''),
                'aliases' => $aliases,
                'builder' => Arr::get($definition, 'builder'),
            ];
        })->sortBy(function ($item) {
            return Str::lower($item['primary_alias']);
        })->values();

        return view('view.list', [
            'views' => $items,
            'page_title' => '檢視表總覽',
        ]);
    }

    public function show(Request $request, string $key) {
        $definitions = Config::get('view_tables', []);

        $definition = null;
        $resolvedKey = null;

        foreach ($definitions as $definitionKey => $candidate) {
            $candidates = array_merge([$definitionKey], Arr::get($candidate, 'aliases', []));
            foreach ($candidates as $alias) {
                if (strcasecmp($alias, $key) === 0) {
                    $definition = $candidate;
                    $resolvedKey = $definitionKey;

                    break 2;
                }
            }
        }

        if ($definition === null) {
            abort(404);
        }

        $effectiveKey = $resolvedKey ?? $key;
        $builderCallable = $definition['builder'] ?? null;
        if (!is_callable($builderCallable)) {
            abort(404, 'View definition missing valid builder.');
        }

        $builder = call_user_func($builderCallable, $request);

        if ($request->filled('search')) {
            $searchable = Config::get('view_table_searchable.' . $effectiveKey, []);
            if (!empty($searchable)) {
                $term = '%' . $request->query('search') . '%';
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

        $debugQuery = clone $builder;
        $debugQuery->limit($perPage)->offset(($currentPage - 1) * $perPage);
        $debugSql = $debugQuery->toSql();
        $debugBindings = $debugQuery->getBindings();
        $debugRenderedSql = $this->viewTableService->formatSql($debugSql, $debugBindings);

        $rows = $builder->paginate($perPage)->appends($request->except('page'));

        return view('view.index', [
            'title' => $definition['title'] ?? $effectiveKey,
            'description' => $definition['description'] ?? null,
            'columns' => $definition['columns'] ?? [],
            'rows' => $rows,
            'key' => $effectiveKey,
            'page_title' => $definition['title'] ?? $effectiveKey,
            'page_description' => $definition['description'] ?? '',
            'page_url' => route('view.show', $effectiveKey),
            'archer' => "<li class='breadcrumb-item'><a href='/view'>檢視表總覽</a></li>",
            'debug_sql' => $debugSql,
            'debug_sql_formatted' => $debugRenderedSql,
            'debug_bindings' => $debugBindings,
            'debug_per_page' => $perPage,
            'debug_current_page' => $currentPage,
        ]);
    }

    public function appIndex(): InertiaResponse {
        return Inertia::render('ViewTables/List', [
            'views' => $this->viewTableService->listDefinitions(),
            'listUrl' => route('app.view.index', [], false),
        ]);
    }

    public function appShow(Request $request, string $key): InertiaResponse {
        $data = $this->viewTableService->buildViewData($key, $request);

        if ($data === null) {
            abort(404);
        }

        $data['pageUrl'] = route('app.view.show', ['key' => $data['key']], false);
        $data['listUrl'] = route('app.view.index', [], false);

        return Inertia::render('ViewTables/Show', $data);
    }
}
