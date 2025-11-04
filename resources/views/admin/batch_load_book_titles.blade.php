@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">批次匯入書稿資料</h3>
        </div>
        <div class="panel-body">
            <p class="text-muted">
                將作者 CBDB ID 與書名貼在下方文字框，每行以 <code>Tab</code> 分隔兩欄。
                範例：<code>12345[TAB]某某書名</code>。系統會依序建立 <code>TEXT_CODES</code> 資料，
                目前預設 <code>c_text_type_id</code> 為 <code>01</code>。
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
                    已新增 {{ count($results) }} 筆資料。
                </div>
            @endif

            <form method="post" action="{{ route('admin.batch-load-book-titles.store') }}">
                {{ csrf_field() }}
                <div class="form-group @if($errors->has('entries')) has-error @endif">
                    <label for="entries">批次資料（以 Tab 分隔）</label>
                    <textarea name="entries" id="entries" class="form-control" rows="10" placeholder="作者ID[TAB]書名">{{ old('entries', $input) }}</textarea>
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
                            <th>書名</th>
                            <th>新 c_textid</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($results as $row)
                            <tr>
                                <td>{{ $row['line'] }}</td>
                                <td>{{ $row['author_id'] }}</td>
                                <td>{{ $row['title'] }}</td>
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
