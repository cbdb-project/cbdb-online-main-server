@extends('layouts.dashboard-v3')

@section('content')

    <div class="card card-default">
        <div class="card-body">
            <div class="d-flex align-items-center mb-3 flex-nowrap">
                <form method="GET" action="{{ route('basicinformation.index') }}" class="flex-grow-1 mr-2">
                    <div class="input-group w-100">
                        <input name="q" type="text" class="form-control" placeholder="搜尋人物 (所有 ü 在拼音中我们都以 v 替代)" aria-label="搜尋人物" value="{{ $q }}">
                        @if(!empty($dynastyFacets) && count($dynastyFacets) > 0)
                            <select name="c_dy" class="custom-select" style="max-width: 180px;" onchange="this.form.submit()">
                                <option value="">全部朝代 ({{ collect($dynastyFacets)->sum('count') }})</option>
                                @foreach($dynastyFacets as $facet)
                                    <option value="{{ $facet->c_dy }}" {{ (string)($c_dy ?? '') === (string)$facet->c_dy ? 'selected' : '' }}>
                                        {{ $facet->c_dynasty_chn }} ({{ $facet->count }})
                                    </option>
                                @endforeach
                            </select>
                        @endif
                        <div class="input-group-append">
                            <button type="submit" class="btn btn-secondary">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </form>
                @auth
                    @if(Auth::user()->isActive())
                        <a href="{{ route('basicinformation.create') }}" class="btn btn-secondary">
                            新增
                        </a>
                    @endif
                @endauth
            </div>

            {{-- 人物列表表格 --}}
            <div class="table-responsive table-scroll-x">
                <table class="table table-hover table-sm">
                    <caption>共計 {{ $names->total() }} 條記錄</caption>
                    <thead>
                        <tr>
                            <th>c_personid</th>
                            <th>c_name_chn</th>
                            <th>c_name</th>
                            <th>dynasty</th>
                            <th>index year</th>
                            <th>index address</th>
                            <th>zi</th>
                            <th>hao</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($names as $item)
                            <tr>
                                <td><a href="{{ route('basicinformation.edit', $item->c_personid) }}" target="_blank">{{ $item->c_personid }}</a></td>
                                <td><a href="{{ route('basicinformation.edit', $item->c_personid) }}" target="_blank">{{ $item->c_name_chn }}</a></td>
                                <td><a href="{{ route('basicinformation.edit', $item->c_personid) }}" target="_blank">{{ $item->c_name }}</a></td>
                                <td><a href="{{ route('basicinformation.edit', $item->c_personid) }}" target="_blank">{{ $item->c_dynasty_chn ?? '' }}</a></td>
                                <td><a href="{{ route('basicinformation.edit', $item->c_personid) }}" target="_blank">{{ $item->c_index_year }}</a></td>
                                <td><a href="{{ route('basicinformation.edit', $item->c_personid) }}" target="_blank">{{ $item->ADDR_c_name_chn ?? '' }}</a></td>
                                <td><a href="{{ route('basicinformation.edit', $item->c_personid) }}" target="_blank">{{ $item->c_alt_name_chn_zi ?? '' }}</a></td>
                                <td><a href="{{ route('basicinformation.edit', $item->c_personid) }}" target="_blank">{{ $item->c_alt_name_chn_hao ?? '' }}</a></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center">暫無數據</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- 分页 --}}
            <div class="float-right">
                {{ $names->appends(['q' => $q, 'c_dy' => $c_dy ?? ''])->links() }}
            </div>
        </div>
    </div>

@endsection

@section('js')

@endsection
