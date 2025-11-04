@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">批次匯入書稿資料</h3>
        </div>
        <div class="panel-body">
            <p class="text-muted">
                將作者 CBDB ID、書名、來源 <code>TEXT_ID</code> 貼在下方文字框，每行以 <code>Tab</code> 分隔三欄。
                範例：<code>12345[TAB]某某書名[TAB]67890</code>。系統會依序建立 <code>TEXT_CODES</code>，
                自動排定 <code>c_textid</code>、轉換拼音並標記書籍朝代，預設 <code>c_text_type_id</code> 為 <code>01</code>。
            </p>

            @if(!empty($batchId))
                <div class="alert alert-info">
                    本次批次編號：<code>{{ $batchId }}</code>
                </div>
            @endif

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
                    已新增 {{ count($results) }} 筆資料。
                </div>
            @endif

            <form method="post" action="{{ route('admin.batch-load-book-titles.store') }}">
                {{ csrf_field() }}
                <div class="form-group @if($errors->has('entries')) has-error @endif">
                    <label for="entries">批次資料（以 Tab 分隔）</label>
                    <textarea name="entries" id="entries" class="form-control" rows="10" placeholder="作者ID[TAB]書名[TAB]來源TEXT_ID">{{ old('entries', $input) }}</textarea>
                    @if($errors->has('entries'))
                        <span class="help-block">{{ $errors->first('entries') }}</span>
                    @endif
                </div>
                <button type="submit" class="btn btn-primary">送出匯入</button>
                <a href="{{ route('admin.batch-load-book-titles') }}" class="btn btn-default">清除重填</a>
            </form>

            @if(!empty($results))
                <div class="table-responsive" style="margin-top: 20px;">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                        <tr>
                            <th>行號</th>
                            <th>作者 ID</th>
                            <th>書名（已清理）</th>
                            <th>來源 TEXT_ID</th>
                            <th>書籍朝代</th>
                            <th>新 c_textid</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($results as $row)
                            <tr>
                                <td>{{ $row['line'] }}</td>
                                <td>{{ $row['author_id'] }}</td>
                                <td>{{ $row['title'] }}</td>
                                <td>{{ $row['source'] }}</td>
                                <td>{{ $row['dynasty'] ?? '—' }}</td>
                                <td>{{ $row['c_textid'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
