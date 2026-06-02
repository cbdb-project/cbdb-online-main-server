@extends('layouts.dashboard-v3')

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card card-primary">
            <div class="card-header">
                <h3 class="card-title">{{ __('admin.manage_edit_user_title') }}</h3>
                <div class="card-tools">
                    <a href="{{ route('manage.index') }}" class="btn btn-sm btn-secondary">
                        <i class="fa fa-arrow-left"></i> {{ __('common.back') }}
                    </a>
                </div>
            </div>

            <form action="{{ route('manage.update', $user->id) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="card-body">
                    <!-- 基本資訊（唯讀） -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ __('admin.manage_user_id') }}</label>
                                <input type="text" class="form-control" value="{{ $user->id }}" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ __('admin.manage_username') }}</label>
                                <input type="text" class="form-control" value="{{ $user->name }}" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ __('common.email') }}</label>
                                <input type="text" class="form-control" value="{{ $user->email }}" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ __('common.institution') }}</label>
                                <input type="text" class="form-control" value="{{ $user->institution }}" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ __('admin.manage_registered_at') }}</label>
                                <input type="text" class="form-control" value="{{ $user->created_at }}" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>{{ __('admin.manage_updated_at') }}</label>
                                <input type="text" class="form-control" value="{{ $user->updated_at }}" readonly>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <!-- 可編輯的設定 -->
                    <h4>{{ __('admin.manage_user_settings') }}</h4>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="is_active">{{ __('admin.manage_account_status') }}</label>
                                <select name="is_active" id="is_active" class="form-control">
                                    <option value="1" {{ $user->is_active == 1 ? 'selected' : '' }}>{{ __('admin.manage_activated_opt') }}</option>
                                    <option value="0" {{ $user->is_active == 0 ? 'selected' : '' }}>{{ __('admin.manage_not_activated_opt') }}</option>
                                </select>
                                <small class="form-text text-muted">{{ __('admin.manage_inactive_login_hint') }}</small>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="is_admin">{{ __('admin.manage_role_col') }}</label>
                                <select name="is_admin" id="is_admin" class="form-control">
                                    <option value="0" {{ $user->is_admin == 0 ? 'selected' : '' }}>{{ __('admin.manage_role_general') }}</option>
                                    <option value="1" {{ $user->is_admin == 1 ? 'selected' : '' }}>{{ __('admin.manage_role_expert') }}</option>
                                    <option value="2" {{ $user->is_admin == 2 ? 'selected' : '' }}>{{ __('admin.manage_role_crowdsource') }}</option>
                                    <option value="3" {{ $user->is_admin == 3 ? 'selected' : '' }}>{{ __('admin.manage_role_sysadmin') }}</option>
                                </select>
                                <small class="form-text text-muted">
                                    @include('manage._role-descriptions')
                                </small>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <!-- 危險操作區 -->
                    <h4 class="text-danger">{{ __('admin.manage_dangerous_ops') }}</h4>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" name="delete_user" id="delete_user" value="1">
                                    {{ __('admin.manage_delete_user') }}
                                </label>
                                <small class="form-text text-danger">
                                    {!! __('admin.manage_delete_warning') !!}
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> {{ __('common.save_changes') }}
                    </button>
                    <a href="{{ route('manage.index') }}" class="btn btn-secondary">
                        <i class="fa fa-times"></i> {{ __('common.cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var _confirmDelete1 = {!! Js::from(__('admin.manage_confirm_delete_1')) !!};
var _confirmDelete2 = {!! Js::from(__('admin.manage_confirm_delete_2')) !!};

document.getElementById('delete_user').addEventListener('change', function() {
    if (this.checked) {
        if (!confirm(_confirmDelete1)) {
            this.checked = false;
        }
    }
});

document.querySelector('form').addEventListener('submit', function(e) {
    const deleteCheckbox = document.getElementById('delete_user');
    if (deleteCheckbox.checked) {
        if (!confirm(_confirmDelete2)) {
            e.preventDefault();
            return false;
        }
    }
});
</script>
@endsection
