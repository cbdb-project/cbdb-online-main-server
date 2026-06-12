@extends('layouts.dashboard-v3')

@section('content')

    @php
        $dynastyMap = $dynastyMap ?? [];
        $isReadOnly = $isReadOnly ?? false;
        $showActions = Auth::check() && !$isReadOnly;
        $keyColumns = $keyColumns ?? [];
        $joinedColumns = $joinedColumns ?? [];
        $filters = $filters ?? [];
        $booleanEnabled = $booleanEnabled ?? false;
        // 連結/狀態攜帶只用實際套用的欄位（排除布林解析失敗被略過者），見設計 §9.2 / 決策 #19。
        // $filters 仍用於 filter-row 輸入框回填（顯示使用者輸入，含尚待修正的錯誤值）。
        $linkFilters = $appliedFilters ?? $filters;
        $sortBy = $sortBy ?? '';
        $sortDir = $sortDir ?? 'asc';
        $baseQuery = ['table_name' => $q];
        if (($search ?? '') !== '') {
            $baseQuery['search'] = $search;
        }
        if (!empty($linkFilters)) {
            $baseQuery['filters'] = $linkFilters;
        }
        if ($sortBy !== '') {
            $baseQuery['sort_by'] = $sortBy;
            $baseQuery['sort_dir'] = $sortDir;
        }
        if ($booleanEnabled) {
            $baseQuery['filter_bool'] = 1;
        }
        $filterErrors = $filterErrors ?? [];
        $filterDescriptions = $filterDescriptions ?? [];
        $booleanFilterAvailable = $booleanFilterAvailable ?? false;
        // 解析錯誤碼 → 本地化訊息；未定義的碼退回通用訊息，避免畫面洩漏原始 key。
        $filterErrMsg = function ($code) {
            $key = 'codes.filter_err_' . $code;
            return trans()->has($key) ? __($key) : __('codes.filter_err_unknown');
        };
        // 進階篩選開關連結：保留 search/sort 與「使用者原始輸入的所有欄位」（$filters，非 appliedFilters）。
        // 關鍵：按「關閉進階篩選」時，含語法錯誤被略過的欄位也要保留並降級為字面搜尋，不可憑空消失（見 §9.2）。
        $toggleBase = ['table_name' => $q];
        if (($search ?? '') !== '') {
            $toggleBase['search'] = $search;
        }
        if (!empty($filters)) {
            $toggleBase['filters'] = $filters;
        }
        if ($sortBy !== '') {
            $toggleBase['sort_by'] = $sortBy;
            $toggleBase['sort_dir'] = $sortDir;
        }
        $toggleOnParams = array_merge($toggleBase, ['filter_bool' => 1]);
        $toggleOffParams = $toggleBase;
    @endphp

    <div class="card card-default">
        <!-- /.card-header -->
        <div class="card-body">
            @if(!empty($copyrightNote))
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> {!! $copyrightNote !!}
                </div>
            @endif
            <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <form method="GET" action="{{ route('codes.show', ['table_name' => $q]) }}" style="flex: 0 0 auto; margin: 0;">
                    <div class="input-group input-group-sm" style="width: 420px;">
                        <input type="text"
                               name="search"
                               class="form-control"
                               placeholder="{{ __('common.search') }}"
                               value="{{ $search ?? '' }}">
                        <div class="input-group-append">
                            <button class="btn btn-secondary" type="submit">{{ __('common.search') }}</button>
                            @if(!empty($search))
                                <a class="btn btn-secondary" href="{{ route('codes.show', $booleanEnabled ? ['table_name' => $q, 'filter_bool' => 1] : ['table_name' => $q]) }}">{{ __('common.reset') }}</a>
                            @endif
                        </div>
                    </div>
                    @if(!empty($sortBy))
                        <input type="hidden" name="sort_by"  value="{{ $sortBy }}">
                        <input type="hidden" name="sort_dir" value="{{ $sortDir ?? 'asc' }}">
                    @endif
                    @if($booleanEnabled)
                        <input type="hidden" name="filter_bool" value="1">
                    @endif
                    @foreach($linkFilters as $col => $val)
                        @if($val !== '')
                            <input type="hidden" name="filters[{{ $col }}]" value="{{ $val }}">
                        @endif
                    @endforeach
                </form>
                <button type="submit" form="filter-form" class="btn btn-sm btn-primary">
                    {{ __('codes.apply_filters') }}
                </button>
                @if(!empty($filters) || !empty($sortBy))
                    <a class="btn btn-sm btn-secondary"
                       href="{{ route('codes.show', array_filter(['table_name' => $q, 'search' => $search, 'filter_bool' => $booleanEnabled ? 1 : null])) }}">
                        {{ __('codes.clear_filters') }}
                    </a>
                @endif
                @if($showActions)
                    <a class="btn btn-sm btn-secondary" href="/codes/{{ $q }}/create">{{ __('common.add') }}</a>
                @endif
                {{-- 進階布林篩選開關（桌面限定；游標大表不適用故隱藏；kill-switch 關閉時整個不顯示，見 §2.2 / 2.3 / B12） --}}
                @if($booleanFilterAvailable && !($useCursorPagination ?? false))
                    <div class="d-none d-md-flex align-items-center" style="gap: 6px; margin-left: auto;">
                        @if($booleanEnabled)
                            <span class="badge badge-primary" style="font-size: 0.8rem;">{{ __('codes.advanced_filter_on') }}</span>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('codes.show', $toggleOffParams) }}">{{ __('codes.advanced_filter_disable') }}</a>
                        @else
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('codes.show', $toggleOnParams) }}">{{ __('codes.advanced_filter') }}</a>
                        @endif
                        {{-- 原生可鍵盤開啟的說明揭露（details/summary），符合 §9.2 C8 無障礙要求 --}}
                        <details class="d-inline-block" style="position: relative;">
                            <summary class="btn btn-sm btn-link text-muted p-0" style="cursor: help;" aria-label="{{ __('codes.advanced_filter') }}">?</summary>
                            <div class="small text-muted p-2 border bg-white"
                                 style="position: absolute; z-index: 10; width: 320px; right: 0; box-shadow: 0 2px 6px rgba(0,0,0,.15);">
                                {{ __('codes.advanced_filter_hint') }}
                            </div>
                        </details>
                    </div>
                @endif
            </div>
            {{-- filter-form：放在 table 外部，input 欄位透過 form="filter-form" 關聯 --}}
            <form method="GET"
                  action="{{ route('codes.show', ['table_name' => $q]) }}"
                  id="filter-form">
                <input type="hidden" name="search"   value="{{ $search ?? '' }}">
                <input type="hidden" name="sort_by"  value="{{ $sortBy ?? '' }}">
                <input type="hidden" name="sort_dir" value="{{ $sortDir ?? 'asc' }}">
                @if($booleanEnabled)
                    <input type="hidden" name="filter_bool" value="1">
                @endif
            </form>
            @if($booleanEnabled)
                {{-- 語法範例（桌面限定）：唯讀展示，僅供閱讀參考，非可點按鈕 --}}
                <div class="d-none d-md-block small text-muted" style="margin-bottom: 8px;">
                    {{ __('codes.filter_chip_label') }}
                    @foreach((array) __('codes.filter_chip_examples') as $chip)
                        <code class="border rounded px-1" style="font-size: 0.75rem; color: #495057; background: #f6f8fa;">{{ $chip }}</code>@if(!$loop->last)　@endif
                    @endforeach
                </div>
                @if(!empty($filterErrors))
                    <div class="alert alert-warning py-2" role="alert" style="font-size: 0.85rem;">
                        {{ __('codes.filter_errors_heading', ['count' => count($filterErrors)]) }}
                        <ul class="mb-0 mt-1">
                            @foreach($filterErrors as $errCol => $errCode)
                                <li><code>{{ $errCol }}</code>：{{ $filterErrMsg($errCode) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if(!empty($filterDescriptions))
                    <div class="small text-muted" style="margin-bottom: 8px;">
                        {{ __('codes.filter_applied_label') }}：
                        @foreach($filterDescriptions as $descCol => $descText)
                            <span class="badge badge-light border" style="font-weight: normal;"><code>{{ $descCol }}</code>：{{ $descText }}</span>
                        @endforeach
                    </div>
                @endif
            @endif
            <div class="table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                <table class="table table-bordered table-striped table-sm">
                    <thead>
                    <tr>
                        @foreach ($thead as $item)
                            @php
                                if ($sortBy !== $item) {
                                    $nextSortParams = ['sort_by' => $item, 'sort_dir' => 'asc'];
                                    $sortIcon = '⇅';
                                } elseif ($sortDir === 'asc') {
                                    $nextSortParams = ['sort_by' => $item, 'sort_dir' => 'desc'];
                                    $sortIcon = '▲';
                                } else {
                                    $nextSortParams = ['sort_by' => '', 'sort_dir' => ''];
                                    $sortIcon = '▼';
                                }
                                $sortUrl = route('codes.show', array_merge(
                                    ['table_name' => $q],
                                    array_filter(request()->only(['search']), fn($v) => $v !== ''),
                                    !empty($linkFilters) ? ['filters' => $linkFilters] : [],
                                    $booleanEnabled ? ['filter_bool' => 1] : [],
                                    array_filter($nextSortParams, fn($v) => $v !== '')
                                ));
                            @endphp
                            <th>
                                <a href="{{ $sortUrl }}" style="color: inherit; text-decoration: none; white-space: nowrap;">
                                    @if(in_array($item, $joinedColumns))
                                        ({{ $item }})
                                    @else
                                        {{ $item }}
                                    @endif
                                    {{ $sortIcon }}
                                </a>
                                @if(in_array($item, $keyColumns, true))
                                    <span class="badge badge-info ml-1">PK</span>
                                @endif
                            </th>
                        @endforeach
                        @if($showActions)
                            <th style="width: 120px">{{ __('codes.actions') }}</th>
                        @endif
                    </tr>
                    <tr class="filter-row">
                        @foreach ($thead as $item)
                            @php
                                $hasFilterError = $booleanEnabled && isset($filterErrors[$item]);
                            @endphp
                            <th style="padding: 4px;">
                                <input type="text"
                                       form="filter-form"
                                       name="filters[{{ $item }}]"
                                       value="{{ $filters[$item] ?? '' }}"
                                       placeholder="{{ $item }}"
                                       aria-label="{{ $item }}"
                                       class="form-control form-control-sm{{ $hasFilterError ? ' is-invalid' : '' }}"
                                       autocomplete="off"
                                       @if($hasFilterError) aria-invalid="true" aria-describedby="filter-err-{{ $item }}" @endif>
                                @if($hasFilterError)
                                    <div id="filter-err-{{ $item }}" class="invalid-feedback d-block" style="font-size: 0.7rem;">{{ $filterErrMsg($filterErrors[$item]) }}</div>
                                @endif
                            </th>
                        @endforeach
                        @if($showActions)
                            <th></th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @forelse (($useCursorPagination ?? false) ? $data['data'] : $data as $item)
                        @php
                            $row = (array) $item;
                            $idParts = [];
                            if (!empty($keyColumns)) {
                                foreach ($keyColumns as $column) {
                                    if (array_key_exists($column, $row)) {
                                        $value = (string) $row[$column];
                                        if ($value !== '') {
                                            $idParts[] = $value;
                                        }
                                    }
                                }
                            }
                            if (empty($idParts)) {
                                foreach ($row as $value) {
                                    $stringValue = (string) $value;
                                    if ($stringValue !== '') {
                                        $idParts[] = $stringValue;
                                    }
                                    if (count($idParts) >= 2) {
                                        break;
                                    }
                                }
                            }
                            $id_ = implode('_._', $idParts);
                        @endphp
                        <tr>
                            @foreach ($thead as $column)
                                @php
                                    $value = $row[$column] ?? '';
                                    if ($column === 'c_dy' && $value !== '') {
                                        $key = is_scalar($value) ? (string) $value : null;
                                        if ($key !== null && isset($dynastyMap[$key])) {
                                            $value = $value . ' - ' . $dynastyMap[$key];
                                        }
                                    }
                                @endphp
                                <td>{{ $value }}</td>
                            @endforeach
                            @if($showActions)
                                <td>
                                    <div class="btn-group">
                                        <a type="button" class="btn btn-sm btn-info" href="{{ route('codes.edit', ['table_name'=>$q, 'id'=>$id_]) }}">{{ __('common.edit') }}</a>
                                        <a href="{{ route('codes.destroy', ['table_name'=>$q, 'id'=>$id_]) }}"
                                           onclick="alert({!! Js::from(__('codes.confirm_delete')) !!});
                                                    event.preventDefault();
                                                   document.getElementById('delete-form-{{ $id_ }}').submit();"
                                           class="btn btn-sm btn-danger">{{ __('common.delete') }}</a>
                                    </div>
                                    <form id="delete-form-{{ $id_ }}" action="{{ route('codes.destroy', ['table_name'=>$q, 'id'=>$id_]) }}" method="POST" style="display: none;">
                                        {{ method_field('DELETE') }}
                                        {{ csrf_field() }}
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($thead) + ($showActions ? 1 : 0) }}" class="text-center text-muted">{{ __('common.no_data') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                    <tfoot>
                    <tr>
                        @foreach ($thead as $item)
                            <th>
                                {{ $item }}
                                @if(in_array($item, $keyColumns, true))
                                    <span class="badge badge-info ml-1">PK</span>
                                @endif
                            </th>
                        @endforeach
                        @if($showActions)
                            <th style="width: 120px">{{ __('codes.actions') }}</th>
                        @endif
                    </tr>
                    </tfoot>
                </table>
            </div>
            <div class="float-right">
                @if($useCursorPagination ?? false)
                    {{-- 游标分页导航 --}}
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div class="btn-group" role="group">
                            @if($data['has_prev_pages'])
                                <a href="{{ route('codes.show', array_merge($baseQuery, ['before' => $data['prev_cursor']])) }}"
                                   class="btn btn-secondary btn-sm">
                                    <i class="fas fa-chevron-left"></i> {{ __('common.previous_page') }}
                                </a>
                            @else
                                <button class="btn btn-secondary btn-sm" disabled>
                                    <i class="fas fa-chevron-left"></i> {{ __('common.previous_page') }}
                                </button>
                            @endif

                            <span class="btn btn-secondary btn-sm" disabled style="cursor: default;">
                                ID: {{ ($data['first_id'] !== null && is_numeric($data['first_id'])) ? number_format($data['first_id']) : ($data['first_id'] ?? '-') }} - {{ ($data['last_id'] !== null && is_numeric($data['last_id'])) ? number_format($data['last_id']) : ($data['last_id'] ?? '-') }}
                            </span>

                            @if($data['has_more_pages'])
                                <a href="{{ route('codes.show', array_merge($baseQuery, ['after' => $data['next_cursor']])) }}"
                                   class="btn btn-secondary btn-sm">
                                    {{ __('common.next_page') }} <i class="fas fa-chevron-right"></i>
                                </a>
                            @else
                                <button class="btn btn-secondary btn-sm" disabled>
                                    {{ __('common.next_page') }} <i class="fas fa-chevron-right"></i>
                                </button>
                            @endif
                        </div>

                        {{-- 跳转到 ID --}}
                        <form method="GET" action="{{ route('codes.show', ['table_name' => $q]) }}" style="margin: 0;">
                            @if($search)
                                <input type="hidden" name="search" value="{{ $search }}">
                            @endif
                            @if(!empty($sortBy))
                                <input type="hidden" name="sort_by" value="{{ $sortBy }}">
                                <input type="hidden" name="sort_dir" value="{{ $sortDir }}">
                            @endif
                            @foreach($filters as $col => $val)
                                @if($val !== '')
                                    <input type="hidden" name="filters[{{ $col }}]" value="{{ $val }}">
                                @endif
                            @endforeach
                            <div class="input-group input-group-sm" style="width: 200px;">
                                <input type="number" name="after" placeholder="{{ __('common.jump_to_id') }}"
                                       class="form-control" min="0">
                                <div class="input-group-append">
                                    <button type="submit" class="btn btn-primary">{{ __('common.jump') }}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                @else
                    {{-- 标准 offset 分页 --}}
                    {{ $data->links() }}
                @endif
            </div>
        </div>
        <!-- /.card-body -->
    </div>
    <!-- /.card -->
@endsection
