@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">批次匯入社會機構</h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                每行請依序輸入：<code>機構名稱</code>、<code>類型（中文）</code>、<code>朝代（中文）</code>、<code>地址名稱（可留白）</code>、
                <code>地址 ID</code>、<code>來源 TEXT_ID</code>，以 <code>Tab</code> 分隔。
                範例：<code>南浦書院[TAB]書院[TAB]清[TAB]浦城[TAB]7793[TAB]4763</code>。
                系統會依類型與朝代對應的代碼建立 <code>SOCIAL_INSTITUTION_NAME_CODES</code>、
                <code>SOCIAL_INSTITUTION_CODES</code> 與 <code>SOCIAL_INSTITUTION_ADDR</code>。
            </p>

            @if(!empty($batchErrors))
                <div class="alert alert-danger">
                    <p class="text-bold">匯入失敗：</p>
                    <ul class="list-unstyled">
                        @foreach($batchErrors as $message)
                            <li>・{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(!empty($results))
                <div class="alert alert-success">
                    已新增 {{ count($results) }} 筆社會機構記錄。
                </div>
            @endif

            <form method="post" action="{{ route('admin.batch-load-social-institutes.store') }}">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="entries">批次資料（以 Tab 分隔）</label>
                    <textarea name="entries" id="entries" class="form-control @error('entries') is-invalid @enderror" rows="10" placeholder="名稱[TAB]類型[TAB]朝代[TAB]地址名[TAB]地址ID[TAB]來源TEXT_ID">{{ old('entries', $input) }}</textarea>
                    @error('entries')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-primary">送出匯入</button>
                <a href="{{ route('admin.batch-load-social-institutes') }}" class="btn btn-default">清除重填</a>
            </form>

            @if(!empty($results))
                <div class="table-responsive" style="margin-top: 20px;">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                        <tr>
                            <th>行號</th>
                            <th>機構名稱</th>
                            <th>名稱代碼</th>
                            <th>名稱拼音</th>
                            <th>是否新名稱</th>
                            <th>機構代碼</th>
                            <th>類型 / 代碼</th>
                            <th>朝代 / 代碼</th>
                            <th>地址 ID</th>
                            <th>來源 TEXT_ID</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($results as $row)
                            <tr>
                                <td>{{ $row['line'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['name_code'] }}</td>
                                <td>{{ $row['name_pinyin'] }}</td>
                                <td>{{ $row['name_created'] ? '是' : '否' }}</td>
                                <td>{{ $row['inst_code'] }}</td>
                                <td>{{ $row['type_label'] }} / {{ $row['type_code'] }}</td>
                                <td>{{ $row['dynasty_label'] }} / {{ $row['dynasty_code'] }}</td>
                                <td>{{ $row['addr_id'] }}</td>
                                <td>{{ $row['source_id'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
