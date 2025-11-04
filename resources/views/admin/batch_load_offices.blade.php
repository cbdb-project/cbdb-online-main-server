@extends('layouts.dashboard')

@section('content')
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">批次匯入官職</h3>
        </div>
        <div class="panel-body">
            <p class="text-muted">
                每行輸入 <code>中文職名</code>、<code>英文職名</code>、<code>朝代（中文）</code>、
                <code>官職類型 ID</code>、<code>所屬單位（備註用，可空白）</code>、<code>來源 TEXT_ID</code>，
                以 <code>Tab</code> 分隔。例：<code>宗人府供事[TAB]Clerk in the Imperial Clan Court[TAB]清[TAB]200501[TAB]宗人府[TAB]4763</code>。
                系統會自動分配 <code>c_office_id</code>，建立 <code>OFFICE_CODES</code> 與 <code>OFFICE_CODE_TYPE_REL</code>。
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
                    已新增 {{ count($results) }} 筆官職資料。
                </div>
            @endif

            <form method="post" action="{{ route('admin.batch-load-offices.store') }}">
                {{ csrf_field() }}
                <div class="form-group @if($errors->has('entries')) has-error @endif">
                    <label for="entries">批次資料（以 Tab 分隔）</label>
                    <textarea name="entries" id="entries" class="form-control" rows="10" placeholder="職名[TAB]英文[TAB]朝代[TAB]類型ID[TAB]所屬單位[TAB]來源TEXT_ID">{{ old('entries', $input) }}</textarea>
                    @if($errors->has('entries'))
                        <span class="help-block">{{ $errors->first('entries') }}</span>
                    @endif
                </div>
                <button type="submit" class="btn btn-primary">送出匯入</button>
                <a href="{{ route('admin.batch-load-offices') }}" class="btn btn-default">清除重填</a>
            </form>

            @if(!empty($results))
                <div class="table-responsive" style="margin-top: 20px;">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                        <tr>
                            <th>行號</th>
                            <th>分配 c_office_id</th>
                            <th>中文職名</th>
                            <th>英文職名</th>
                            <th>拼音</th>
                            <th>朝代 / 代碼</th>
                            <th>類型 ID</th>
                            <th>所屬單位</th>
                            <th>來源 TEXT_ID</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($results as $row)
                            <tr>
                                <td>{{ $row['line'] }}</td>
                                <td>{{ $row['office_id'] }}</td>
                                <td>{{ $row['name'] }}</td>
                                <td>{{ $row['translation'] }}</td>
                                <td>{{ $row['pinyin'] }}</td>
                                <td>{{ $row['dynasty_label'] }} / {{ $row['dynasty_code'] }}</td>
                                <td>{{ $row['type_id'] }}</td>
                                <td>{{ $row['department'] }}</td>
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
