@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">按入仕查詢結果</h3>
            <div class="card-tools">
                <a href="{{ route('search-by.entry.index') }}" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left"></i> 返回搜尋
                </a>
            </div>
        </div>
        <div class="card-body">
            <!-- 搜尋條件摘要 -->
            <div class="alert alert-info">
                <h5 class="mb-2"><i class="icon fas fa-info"></i> 搜尋條件</h5>
                <ul class="mb-0">
                    @if(request()->input('entry_codes'))
                        <li>
                            入仕代碼：
                            @foreach(request()->input('entry_codes') as $code)
                                <span class="badge badge-primary">{{ $code }}</span>
                            @endforeach
                        </li>
                    @endif
                    @if(request()->input('year_from'))
                        <li>起始年：{{ request()->input('year_from') }}</li>
                    @endif
                    @if(request()->input('year_to'))
                        <li>結束年：{{ request()->input('year_to') }}</li>
                    @endif
                    @if(request()->input('addr_id'))
                        <li>地址 ID：{{ request()->input('addr_id') }}</li>
                    @endif
                </ul>
            </div>

            <!-- 結果統計 -->
            <div class="mb-3">
                <p class="text-muted">
                    共找到 <strong>{{ $results->total() }}</strong> 筆結果
                    （顯示第 {{ $results->firstItem() ?? 0 }} - {{ $results->lastItem() ?? 0 }} 筆）
                </p>
            </div>

            <!-- 結果表格 -->
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-sm">
                    <thead>
                        <tr>
                            <th>人物 ID</th>
                            <th>中文名</th>
                            <th>英文名</th>
                            <th>朝代</th>
                            <th>索引年</th>
                            <th>索引地址</th>
                            <th>入仕代碼</th>
                            <th>入仕途徑（中文）</th>
                            <th>入仕途徑（英文）</th>
                            <th>入仕年份</th>
                            <th>序號</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($results as $row)
                            <tr>
                                <td>{{ $row->c_personid }}</td>
                                <td>{{ $row->c_name_chn }}</td>
                                <td>{{ $row->c_name }}</td>
                                <td>{{ $row->c_dynasty_chn ?? $row->c_dynasty }}</td>
                                <td>{{ $row->c_index_year }}</td>
                                <td>{{ $row->c_index_addr_chn ?? $row->c_index_addr_name }}</td>
                                <td>{{ $row->c_entry_code }}</td>
                                <td>{{ $row->c_entry_desc_chn }}</td>
                                <td>{{ $row->c_entry_desc }}</td>
                                <td>{{ $row->c_year }}</td>
                                <td>{{ $row->c_sequence }}</td>
                                <td>
                                    <a href="{{ route('basicinformation.show', $row->c_personid) }}" class="btn btn-sm btn-info" target="_blank">
                                        查看
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="text-center">無符合條件的結果</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- 分頁 -->
            @if($results->hasPages())
                <div class="d-flex justify-content-center">
                    {{ $results->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
