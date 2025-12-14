@extends('layouts.dashboard-v3')

@section('content')

<div class="card card-default">
    <div class="card-header">
        <h3 class="card-title">個人資料設定</h3>
    </div>
    <div class="card-body">

        @if(session('success'))
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                <h4><i class="icon fa fa-check"></i> 成功！</h4>
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                <h4><i class="icon fa fa-ban"></i> 錯誤！</h4>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('profile.update') }}" class="form-horizontal" method="post">
            {{ method_field('PATCH') }}
            {{ csrf_field() }}

            <div class="card card-primary">
                <div class="card-header">
                    <h3 class="card-title">基本資料</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="name" class="col-sm-2 control-label">姓名 <span class="text-red">*</span></label>
                        <div class="col-sm-10">
                            <input type="text" name="name" id="name" class="form-control"
                                   value="{{ old('name', $user->name) }}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email" class="col-sm-2 control-label">Email <span class="text-red">*</span></label>
                        <div class="col-sm-10">
                            <input type="email" name="email" id="email" class="form-control"
                                   value="{{ old('email', $user->email) }}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="institution" class="col-sm-2 control-label">所屬機構</label>
                        <div class="col-sm-10">
                            <input type="text" name="institution" id="institution" class="form-control"
                                   value="{{ old('institution', $user->institution) }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card card-warning">
                <div class="card-header">
                    <h3 class="card-title">修改密碼</h3>
                    <div class="card-tools">
                        <p class="help-block" style="margin: 0;">如果不需要修改密碼，請留空以下欄位</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="current_password" class="col-sm-2 control-label">當前密碼</label>
                        <div class="col-sm-10">
                            <input type="password" name="current_password" id="current_password" class="form-control">
                            <p class="help-block">如需修改密碼，請先輸入當前密碼</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="new_password" class="col-sm-2 control-label">新密碼</label>
                        <div class="col-sm-10">
                            <input type="password" name="new_password" id="new_password" class="form-control">
                            <p class="help-block">密碼至少需要 6 個字符</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="new_password_confirmation" class="col-sm-2 control-label">確認新密碼</label>
                        <div class="col-sm-10">
                            <input type="password" name="new_password_confirmation" id="new_password_confirmation" class="form-control">
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <div class="col-sm-offset-2 col-sm-10">
                    <button type="submit" class="btn btn-primary">儲存變更</button>
                    <a href="{{ url('/home') }}" class="btn btn-secondary">取消</a>
                </div>
            </div>
        </form>
    </div>
</div>

@endsection
