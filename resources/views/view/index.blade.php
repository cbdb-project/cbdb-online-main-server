@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-body">
            @if(!empty($description))
                <p>{{ $description }}</p>
            @endif

            <div class="form-inline" style="margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                <form method="get" class="form-inline" style="flex-grow: 1;">
                    <div class="form-group" style="margin-right: 8px;">
                        <label for="search" class="sr-only">Search</label>
                        <input type="text" name="search" id="search" value="{{ request('search') }}" class="form-control" placeholder="Search..." style="width: 240px;">
                    </div>
                    <button type="submit" class="btn btn-primary">搜尋</button>
                    @if(request()->has('search') && request('search') !== '')
                        <a href="{{ route('view.show', $key) }}" class="btn btn-secondary" style="margin-left: 8px;">清除</a>
                    @endif
                </form>
                <button type="button" class="btn btn-secondary btn-sm" data-toggle="modal" data-target="#view-sql-modal">
                    顯示 SQL
                </button>
            </div>

            <div class="table-responsive table-scroll-x view-table-responsive">
                <table class="table table-bordered table-striped table-sm">
                    <thead>
                    <tr>
                        @foreach($columns as $label)
                            <th>{{ $label }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $row)
                        <tr>
                            @foreach(array_keys($columns) as $columnKey)
                                <td>{{ data_get($row, $columnKey) }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) }}">無資料</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="float-right">
                {{ $rows->links() }}
            </div>
        </div>
    </div>

    <div class="modal fade" id="view-sql-modal" tabindex="-1" role="dialog" aria-labelledby="viewSqlModalLabel">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="viewSqlModalLabel">本次查詢 SQL</h4>
                </div>
                <div class="modal-body">
                    <p class="text-muted">每頁 {{ $debug_per_page }} 筆，當前第 {{ $debug_current_page }} 頁</p>
                    <p><strong>SQL</strong></p>
                    <pre style="white-space: pre-wrap; word-break: break-all;">{{ $debug_sql_formatted }}</pre>
                    <p><strong>Bindings</strong></p>
                    @if(!empty($debug_bindings))
                        <pre>{{ json_encode($debug_bindings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    @else
                        <pre>(none)</pre>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">關閉</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js')
@endsection
