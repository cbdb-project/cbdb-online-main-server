@extends('layouts.dashboard-v3')

@section('content')
<div class="card card-default">
    <div class="card-header">
        <h3 class="card-title">{{ __('admin.unidirect_title') }}</h3>
    </div>
    <div class="card-body">

        {{-- 上半部分：親屬關係修復 --}}
        <div class="row" style="margin-bottom: 30px;">
            <div class="col-md-12">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fa fa-users"></i>
                            {{ __('admin.unidirect_kinship_title') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <div id="kinship-result-container"></div>

                        <form id="kinship-repair-form">
                            {{ csrf_field() }}

                            <div class="form-group">
                                <label>{{ __('admin.unidirect_kinship_personid_label') }}</label>
                                <input type="number" name="c_personid" class="form-control" required
                                       placeholder="{{ __('admin.unidirect_kinship_personid_ph') }}">
                                <span class="help-block small">{{ __('admin.unidirect_kinship_personid_help') }}</span>
                            </div>

                            <div class="form-group">
                                <label>{{ __('admin.unidirect_kinship_kin_id_label') }}</label>
                                <input type="number" name="c_kin_id" class="form-control" required
                                       placeholder="{{ __('admin.unidirect_kinship_kin_id_ph') }}">
                                <span class="help-block small">{{ __('admin.unidirect_kinship_kin_id_help') }}</span>
                            </div>

                            <div class="form-group">
                                <label>{{ __('admin.unidirect_kinship_kin_code_label') }}</label>
                                <input type="number" name="c_kin_code" class="form-control" required
                                       placeholder="{{ __('admin.unidirect_kinship_kin_code_ph') }}">
                                <span class="help-block small">{{ __('admin.unidirect_kinship_kin_code_help') }}</span>
                            </div>

                            <div class="form-group">
                                <label>{{ __('admin.unidirect_kinship_new_code_label') }}</label>
                                <input type="number" name="new_c_kin_code" class="form-control" required
                                       placeholder="{{ __('admin.unidirect_kinship_new_code_ph') }}">
                                <span class="help-block small">{{ __('admin.unidirect_kinship_new_code_help') }}</span>
                            </div>

                            <button type="submit" class="btn btn-primary" id="kinship-submit-btn">
                                <i class="fa fa-check"></i> {{ __('admin.unidirect_kinship_btn') }}
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fa fa-undo"></i> {{ __('admin.unidirect_reset_btn') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- 下半部分：社會關係修復 --}}
        <div class="row" style="margin-bottom: 30px;">
            <div class="col-md-12">
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fa fa-sitemap"></i>
                            {{ __('admin.unidirect_assoc_title') }}
                        </h3>
                    </div>
                    <div class="card-body">
                        <div id="assoc-result-container"></div>

                        <form id="assoc-repair-form">
                            {{ csrf_field() }}

                            <div class="form-group">
                                <label>{{ __('admin.unidirect_kinship_personid_label') }}</label>
                                <input type="number" name="c_personid" class="form-control" required
                                       placeholder="{{ __('admin.unidirect_kinship_personid_ph') }}">
                                <span class="help-block small">{{ __('admin.unidirect_assoc_personid_help') }}</span>
                            </div>

                            <div class="form-group">
                                <label>{{ __('admin.unidirect_assoc_assoc_id_label') }}</label>
                                <input type="number" name="c_assoc_id" class="form-control" required
                                       placeholder="{{ __('admin.unidirect_assoc_assoc_id_ph') }}">
                                <span class="help-block small">{{ __('admin.unidirect_kinship_kin_id_help') }}</span>
                            </div>

                            <div class="form-group">
                                <label>{{ __('admin.unidirect_assoc_assoc_code_label') }}</label>
                                <input type="number" name="c_assoc_code" class="form-control" required
                                       placeholder="{{ __('admin.unidirect_assoc_assoc_code_ph') }}">
                                <span class="help-block small">{{ __('admin.unidirect_kinship_kin_code_help') }}</span>
                            </div>

                            <div class="form-group">
                                <label>{{ __('admin.unidirect_assoc_new_code_label') }}</label>
                                <input type="number" name="new_c_assoc_code" class="form-control" required
                                       placeholder="{{ __('admin.unidirect_assoc_new_code_ph') }}">
                                <span class="help-block small">{{ __('admin.unidirect_assoc_new_code_help') }}</span>
                            </div>

                            <button type="submit" class="btn btn-success" id="assoc-submit-btn">
                                <i class="fa fa-check"></i> {{ __('admin.unidirect_assoc_btn') }}
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fa fa-undo"></i> {{ __('admin.unidirect_reset_btn') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- 說明文字 --}}
        <div class="panel panel-info">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-info-circle"></i> {{ __('admin.unidirect_desc_title') }}</h3>
            </div>
            <div class="panel-body">
                <h4>{{ __('admin.unidirect_desc_function_title') }}</h4>
                <p>{{ __('admin.unidirect_desc_function_text') }}</p>

                <h4>{{ __('admin.unidirect_desc_kinship_title') }}</h4>
                <ul>
                    <li>{!! __('admin.unidirect_desc_kinship_use') !!}</li>
                    <li>{!! __('admin.unidirect_desc_kinship_params') !!}
                        <ul>
                            <li><code>c_personid</code>：{{ __('admin.unidirect_kinship_personid_label') }}</li>
                            <li><code>c_kin_id</code>：{{ __('admin.unidirect_kinship_kin_id_label') }}</li>
                            <li><code>c_kin_code</code>：{{ __('admin.unidirect_kinship_kin_code_label') }}</li>
                            <li><code>{{ __('admin.unidirect_kinship_new_code_label') }}</code></li>
                        </ul>
                    </li>
                    <li>{!! __('admin.unidirect_desc_logic_title') !!}
                        <ol>
                            <li>{{ __('admin.unidirect_desc_logic_1') }}</li>
                            <li>{{ __('admin.unidirect_desc_logic_2') }}</li>
                            <li>{{ __('admin.unidirect_desc_logic_3') }}</li>
                            <li>{{ __('admin.unidirect_desc_logic_4') }}</li>
                            <li>{{ __('admin.unidirect_desc_logic_5') }}</li>
                        </ol>
                    </li>
                    <li>{!! __('admin.unidirect_desc_kinship_example') !!}</li>
                </ul>

                <h4>{{ __('admin.unidirect_desc_assoc_title') }}</h4>
                <ul>
                    <li>{!! __('admin.unidirect_desc_assoc_use') !!}</li>
                    <li>{!! __('admin.unidirect_desc_kinship_params') !!}
                        <ul>
                            <li><code>c_personid</code>：{{ __('admin.unidirect_kinship_personid_label') }}</li>
                            <li><code>c_assoc_id</code>：{{ __('admin.unidirect_assoc_assoc_id_label') }}</li>
                            <li><code>c_assoc_code</code>：{{ __('admin.unidirect_assoc_assoc_code_label') }}</li>
                            <li><code>{{ __('admin.unidirect_assoc_new_code_label') }}</code></li>
                        </ul>
                    </li>
                    <li>{!! __('admin.unidirect_desc_logic_title') !!}
                        <ol>
                            <li>{{ __('admin.unidirect_desc_assoc_logic_1') }}</li>
                            <li>{{ __('admin.unidirect_desc_logic_2') }}</li>
                            <li>{{ __('admin.unidirect_desc_logic_3') }}</li>
                            <li>{{ __('admin.unidirect_desc_logic_4') }}</li>
                            <li>{{ __('admin.unidirect_desc_assoc_logic_5') }}</li>
                        </ol>
                    </li>
                    <li>{!! __('admin.unidirect_desc_assoc_example') !!}</li>
                </ul>

                <h4>{{ __('admin.unidirect_desc_notes_title') }}</h4>
                <ul>
                    <li class="text-danger">{!! __('admin.unidirect_desc_unique') !!}</li>
                    <li class="text-danger">{!! __('admin.unidirect_desc_duplicate') !!}</li>
                    <li class="text-warning">{!! __('admin.unidirect_desc_code_warning') !!}</li>
                    <li class="text-info">{!! __('admin.unidirect_desc_integrity') !!}</li>
                    <li class="text-info">{!! __('admin.unidirect_desc_permission') !!}</li>
                </ul>

                <h4>{{ __('admin.unidirect_desc_tables_title') }}</h4>
                <ul>
                    <li><code>KIN_DATA</code></li>
                    <li><code>KINSHIP_CODES</code></li>
                    <li><code>ASSOC_DATA</code></li>
                    <li><code>ASSOC_CODES</code></li>
                </ul>
            </div>
        </div>

    </div>
</div>
@endsection

@section('css')
<style>
.panel-heading h3 {
    margin: 0;
}

.panel-body .form-group {
    margin-bottom: 20px;
}

.help-block {
    margin-top: 5px;
    margin-bottom: 0;
}

.alert {
    margin-bottom: 15px;
}

.result-detail {
    background-color: #f5f5f5;
    padding: 10px;
    border-radius: 4px;
    margin-top: 10px;
    font-family: monospace;
    font-size: 12px;
}

.result-detail strong {
    display: inline-block;
    min-width: 150px;
}
</style>
@endsection

@section('js')
<script>
var __unidirect = {
    kinshipConfirm:  {!! Js::from(__('admin.unidirect_kinship_confirm')) !!},
    assocConfirm:    {!! Js::from(__('admin.unidirect_assoc_confirm')) !!},
    processing:      {!! Js::from(__('admin.unidirect_processing')) !!},
    originalRelation:{!! Js::from(__('admin.unidirect_original_relation')) !!},
    newRelation:     {!! Js::from(__('admin.unidirect_new_relation')) !!},
    successTitle:    {!! Js::from(__('admin.unidirect_success_title')) !!},
    failureTitle:    {!! Js::from(__('admin.unidirect_failure_title')) !!},
    errorTitle:      {!! Js::from(__('admin.unidirect_error_title')) !!},
    processFailed:   {!! Js::from(__('admin.unidirect_process_failed')) !!},
    timeout:         {!! Js::from(__('admin.unidirect_timeout')) !!},
    networkFailed:   {!! Js::from(__('admin.unidirect_network_failed')) !!},
    serverError:     {!! Js::from(__('admin.unidirect_server_error')) !!},
};
onViteReady(function() {
    // 親屬關係修復表單提交
    $('#kinship-repair-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $('#kinship-submit-btn');
        var $resultContainer = $('#kinship-result-container');
        var originalBtnHtml = $btn.html();

        if (!confirm(__unidirect.kinshipConfirm)) {
            return false;
        }

        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> ' + __unidirect.processing);
        $resultContainer.empty();

        $.ajax({
            url: '{{ route('admin.unidirectional-relationship-repair.kinship') }}',
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).html(originalBtnHtml);

                if (response.success) {
                    var detailHtml = '<div class="result-detail">' +
                        '<strong>' + __unidirect.originalRelation + '</strong>' +
                        'c_personid=' + response.original.c_personid + ', ' +
                        'c_kin_id=' + response.original.c_kin_id + ', ' +
                        'c_kin_code=' + response.original.c_kin_code + '<br>' +
                        '<strong>' + __unidirect.newRelation + '</strong>' +
                        'c_personid=' + response.created.c_personid + ', ' +
                        'c_kin_id=' + response.created.c_kin_id + ', ' +
                        'c_kin_code=' + response.created.c_kin_code +
                        '</div>';

                    $resultContainer.html(
                        '<div class="alert alert-success alert-dismissible">' +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '<strong>' + __unidirect.successTitle + '</strong> ' + response.message + detailHtml +
                        '</div>'
                    );

                    $form[0].reset();
                } else {
                    $resultContainer.html(
                        '<div class="alert alert-danger alert-dismissible">' +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '<strong>' + __unidirect.failureTitle + '</strong> ' + response.message +
                        '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).html(originalBtnHtml);

                var errorMsg = __unidirect.processFailed;
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (status === 'timeout') {
                    errorMsg = __unidirect.timeout;
                } else if (xhr.status === 0) {
                    errorMsg = __unidirect.networkFailed;
                } else {
                    errorMsg = __unidirect.serverError.replace(':status', xhr.status);
                }

                $resultContainer.html(
                    '<div class="alert alert-danger alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<strong>' + __unidirect.errorTitle + '</strong> ' + errorMsg +
                    '</div>'
                );
            }
        });
    });

    // 社會關係修復表單提交
    $('#assoc-repair-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $('#assoc-submit-btn');
        var $resultContainer = $('#assoc-result-container');
        var originalBtnHtml = $btn.html();

        if (!confirm(__unidirect.assocConfirm)) {
            return false;
        }

        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> ' + __unidirect.processing);
        $resultContainer.empty();

        $.ajax({
            url: '{{ route('admin.unidirectional-relationship-repair.assoc') }}',
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false).html(originalBtnHtml);

                if (response.success) {
                    var detailHtml = '<div class="result-detail">' +
                        '<strong>' + __unidirect.originalRelation + '</strong>' +
                        'c_personid=' + response.original.c_personid + ', ' +
                        'c_assoc_id=' + response.original.c_assoc_id + ', ' +
                        'c_assoc_code=' + response.original.c_assoc_code + '<br>' +
                        '<strong>' + __unidirect.newRelation + '</strong>' +
                        'c_personid=' + response.created.c_personid + ', ' +
                        'c_assoc_id=' + response.created.c_assoc_id + ', ' +
                        'c_assoc_code=' + response.created.c_assoc_code +
                        '</div>';

                    $resultContainer.html(
                        '<div class="alert alert-success alert-dismissible">' +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '<strong>' + __unidirect.successTitle + '</strong> ' + response.message + detailHtml +
                        '</div>'
                    );

                    $form[0].reset();
                } else {
                    $resultContainer.html(
                        '<div class="alert alert-danger alert-dismissible">' +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '<strong>' + __unidirect.failureTitle + '</strong> ' + response.message +
                        '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).html(originalBtnHtml);

                var errorMsg = __unidirect.processFailed;
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (status === 'timeout') {
                    errorMsg = __unidirect.timeout;
                } else if (xhr.status === 0) {
                    errorMsg = __unidirect.networkFailed;
                } else {
                    errorMsg = __unidirect.serverError.replace(':status', xhr.status);
                }

                $resultContainer.html(
                    '<div class="alert alert-danger alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<strong>' + __unidirect.errorTitle + '</strong> ' + errorMsg +
                    '</div>'
                );
            }
        });
    });
});
</script>
@endsection
