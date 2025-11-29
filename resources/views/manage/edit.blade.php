@extends('layouts.dashboard')

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">编辑用户设置</h3>
                <div class="box-tools pull-right">
                    <a href="{{ route('manage.index') }}" class="btn btn-sm btn-default">
                        <i class="fa fa-arrow-left"></i> 返回列表
                    </a>
                </div>
            </div>

            <form action="{{ route('manage.update', $user->id) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="box-body">
                    <!-- 基本信息（只读） -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>用户 ID</label>
                                <input type="text" class="form-control" value="{{ $user->id }}" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>用户名</label>
                                <input type="text" class="form-control" value="{{ $user->name }}" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>电子邮箱</label>
                                <input type="text" class="form-control" value="{{ $user->email }}" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>机构</label>
                                <input type="text" class="form-control" value="{{ $user->institution }}" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>注册时间</label>
                                <input type="text" class="form-control" value="{{ $user->created_at }}" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>最后更新时间</label>
                                <input type="text" class="form-control" value="{{ $user->updated_at }}" readonly>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <!-- 可编辑的设置 -->
                    <h4>用户设置</h4>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="is_active">账号状态</label>
                                <select name="is_active" id="is_active" class="form-control">
                                    <option value="1" {{ $user->is_active == 1 ? 'selected' : '' }}>已激活</option>
                                    <option value="0" {{ $user->is_active == 0 ? 'selected' : '' }}>未激活</option>
                                </select>
                                <p class="help-block">未激活的用户无法登录系统</p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="is_admin">用户角色</label>
                                <select name="is_admin" id="is_admin" class="form-control">
                                    <option value="0" {{ $user->is_admin == 0 ? 'selected' : '' }}>一般用户</option>
                                    <option value="1" {{ $user->is_admin == 1 ? 'selected' : '' }}>专家</option>
                                    <option value="2" {{ $user->is_admin == 2 ? 'selected' : '' }}>众包</option>
                                    <option value="3" {{ $user->is_admin == 3 ? 'selected' : '' }}>系统管理员</option>
                                </select>
                                <p class="help-block">
                                    <strong>一般用户：</strong>基本权限<br>
                                    <strong>专家：</strong>拥有管理权限<br>
                                    <strong>众包：</strong>众包用户权限<br>
                                    <strong>系统管理员：</strong>最高权限
                                </p>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <!-- 危险操作区 -->
                    <h4 class="text-danger">危险操作</h4>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="delete_user" id="delete_user" value="1">
                                    删除此用户
                                </label>
                                <p class="help-block text-danger">
                                    <strong>警告：</strong>勾选此项并保存将会删除该用户账号。此操作不可恢复！
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="box-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> 保存修改
                    </button>
                    <a href="{{ route('manage.index') }}" class="btn btn-default">
                        <i class="fa fa-times"></i> 取消
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('delete_user').addEventListener('change', function() {
    if (this.checked) {
        if (!confirm('您真的确定要删除此用户吗？\n\n此操作不可恢复！\n\n请确认！')) {
            this.checked = false;
        }
    }
});

document.querySelector('form').addEventListener('submit', function(e) {
    const deleteCheckbox = document.getElementById('delete_user');
    if (deleteCheckbox.checked) {
        if (!confirm('最后确认：您真的要删除此用户吗？')) {
            e.preventDefault();
            return false;
        }
    }
});
</script>
@endsection
