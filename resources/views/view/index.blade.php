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
                        <label for="search" class="sr-only">{{ __('common.search') }}</label>
                        <input type="text" name="search" id="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('common.search') }}..." style="width: 240px;">
                    </div>
                    <button type="submit" class="btn btn-primary">{{ __('common.search') }}</button>
                    @if(request()->has('search') && request('search') !== '')
                        <a href="{{ route('view.show', $key) }}" class="btn btn-secondary" style="margin-left: 8px;">{{ __('common.clear') }}</a>
                    @endif
                </form>
                <button type="button" class="btn btn-secondary btn-sm" data-toggle="modal" data-target="#view-sql-modal">
                    {{ __('common.show_sql') }}
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
                            <td colspan="{{ count($columns) }}">{{ __('common.no_data') }}</td>
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
                    <h4 class="modal-title" id="viewSqlModalLabel">{{ __('common.current_query_sql') }}</h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">{{ __('common.per_page_info', ['per_page' => $debug_per_page, 'page' => $debug_current_page]) }}</p>
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
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('common.close') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('js')
@endsection
