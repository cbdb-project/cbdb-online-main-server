@extends('layouts.dashboard-v3')

@section('content')
<div class="card card-default">
    <div class="card-header">
        <h3 class="card-title">{{ __('admin.wiki_page_title') }}</h3>
    </div>
    <div class="card-body">

        {{-- 統計信息 --}}
        <div class="row" style="margin-bottom: 20px;">
            @foreach($targetSourceIds as $id)
            <div class="col-md-4">
                <a href="{{ route('admin.wiki-maintenance', ['source_id' => $id]) }}" style="text-decoration: none;">
                    <div class="info-box{{ $currentSourceId == $id ? ' info-box-selected' : '' }}">
                        <span class="info-box-icon bg-{{ $sourceColors[$id] }}">
                            <i class="{{ $sourceIcons[$id] }}"></i>
                        </span>
                        <div class="info-box-content">
                            <span class="info-box-text">{{ $sourceNames[$id] }}</span>
                            <span class="info-box-number">{{ number_format($stats[$id]) }} {{ __('admin.wiki_records_unit') }}</span>
                        </div>
                    </div>
                </a>
            </div>
            @endforeach
        </div>

        <p class="text-muted">{{ __('admin.wiki_maintenance_desc') }}</p>

        {{-- 記錄列表 --}}
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>{{ __('admin.wiki_col_person_id') }}</th>
                        <th>{{ __('admin.wiki_col_name_chn') }}</th>
                        <th>{{ __('admin.wiki_col_text_id') }}</th>
                        <th>{{ __('admin.wiki_col_page') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $record)
                        <tr>
                            <td>
                                <a href="/basicinformation/{{ $record->c_personid }}/sources" target="_blank">
                                    {{ $record->c_personid }}
                                </a>
                            </td>
                            <td>{{ $record->c_name_chn ?? '-' }}</td>
                            <td>{{ $record->c_textid }}</td>
                            <td>
                                @if($record->c_url_api && $record->c_textid && $record->c_pages)
                                    @php
                                        $url_part = $record->c_pages;
                                        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $url_part)) {
                                            $url_part = rawurlencode($url_part);
                                        }
                                        $full_url = $record->c_url_api . $url_part . ($record->c_url_api_coda ?? '');
                                    @endphp
                                    <a href="{{ $full_url }}" target="_blank">{{ $record->c_pages }}</a>
                                @else
                                    {{ $record->c_pages ?? '-' }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted">{{ __('admin.wiki_no_records') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 分頁導航 --}}
        @if($total > 0)
            <div class="row">
                <div class="col-md-6">
                    <p class="text-muted">
                        {{ __('admin.wiki_showing', ['from' => ($page - 1) * $perPage + 1, 'to' => min($page * $perPage, $total), 'total' => number_format($total)]) }}
                    </p>
                </div>
                <div class="col-md-6">
                    <nav aria-label="{{ __('admin.wiki_pagination_label') }}" class="float-right">
                        <ul class="pagination">
                            {{-- 上一頁 --}}
                            @if($hasPrev)
                                <li class="page-item">
                                    <a class="page-link" href="{{ route('admin.wiki-maintenance', ['source_id' => $currentSourceId, 'page' => $page - 1]) }}">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            @else
                                <li class="page-item disabled">
                                    <span class="page-link" aria-hidden="true">&laquo;</span>
                                </li>
                            @endif

                            {{-- 頁碼 --}}
                            @php
                                $startPage = max(1, $page - 2);
                                $endPage = min(ceil($total / $perPage), $page + 2);
                            @endphp

                            @for($i = $startPage; $i <= $endPage; $i++)
                                @if($i == $page)
                                    <li class="page-item active">
                                        <span class="page-link">{{ $i }}</span>
                                    </li>
                                @else
                                    <li class="page-item">
                                        <a class="page-link" href="{{ route('admin.wiki-maintenance', ['source_id' => $currentSourceId, 'page' => $i]) }}">
                                            {{ $i }}
                                        </a>
                                    </li>
                                @endif
                            @endfor

                            {{-- 下一頁 --}}
                            @if($hasNext)
                                <li class="page-item">
                                    <a class="page-link" href="{{ route('admin.wiki-maintenance', ['source_id' => $currentSourceId, 'page' => $page + 1]) }}">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            @else
                                <li class="page-item disabled">
                                    <span class="page-link" aria-hidden="true">&raquo;</span>
                                </li>
                            @endif
                        </ul>
                    </nav>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@section('css')
<style>
.info-box {
    transition: all 0.3s ease;
    cursor: pointer;
}

.info-box:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.info-box-selected {
    border: 3px solid #3c8dbc;
    box-shadow: 0 2px 8px rgba(60, 141, 188, 0.3);
}

.info-box-selected:hover {
    border-color: #2f7ba8;
    box-shadow: 0 4px 12px rgba(60, 141, 188, 0.5);
}

a:hover {
    text-decoration: none;
}
</style>
@endsection
