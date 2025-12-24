@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">MySQL EXPLAIN</h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                這個工具僅供管理員用於診斷查詢效能。請輸入只讀 SQL（僅支援 SELECT / WITH），系統會送出
                <code>EXPLAIN</code> 並顯示 MySQL 回傳的執行計畫，方便評估索引或查詢設計是否需要調整。
            </p>

            <form method="post" action="{{ route('admin.explainsql') }}" class="form">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="sql">SQL 語句（僅支援 SELECT / WITH）</label>
                    <textarea name="sql" id="sql" rows="5" class="form-control @error('sql') is-invalid @enderror" placeholder="SELECT ...">{{ old('sql', $sql) }}</textarea>
                    @error('sql')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-primary">執行 EXPLAIN</button>
            </form>

            @if($error)
                <div class="alert alert-danger" style="margin-top: 15px;">
                    {{ $error }}
                </div>
            @endif

            @if(is_array($results) && count($results) > 0)
                <div class="table-responsive" style="margin-top: 20px;">
                    <table class="table table-bordered table-striped table-sm">
                        <thead>
                        <tr>
                            @foreach($columns as $column)
                                <th>{{ $column }}</th>
                            @endforeach
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($results as $row)
                            <tr>
                                @foreach($columns as $column)
                                    <td>{{ data_get($row, $column) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif($results !== null && empty($results))
                <p style="margin-top: 20px;">查無資料。</p>
            @endif
        </div>
    </div>
@endsection
