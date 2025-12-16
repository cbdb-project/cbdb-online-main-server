@extends('layouts.dashboard-v3')

@section('content')
<div class="card card-default">
    <div class="card-header">
        <h3 class="card-title">單向關係修復</h3>
    </div>
    <div class="card-body">

        {{-- 上半部分：親屬關係修復 --}}
        <div class="row" style="margin-bottom: 30px;">
            <div class="col-md-12">
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fa fa-users"></i>
                            親屬關係修復
                        </h3>
                    </div>
                    <div class="card-body">
                        <div id="kinship-result-container"></div>

                        <form id="kinship-repair-form">
                            {{ csrf_field() }}

                            <div class="form-group">
                                <label>當前單向關係中的 c_personid：</label>
                                <input type="number" name="c_personid" class="form-control" required
                                       placeholder="請輸入人物 ID">
                                <span class="help-block small">此人物的親屬關係記錄將被用作來源</span>
                            </div>

                            <div class="form-group">
                                <label>當前單向關係中的 c_kin_id：</label>
                                <input type="number" name="c_kin_id" class="form-control" required
                                       placeholder="請輸入親屬 ID">
                                <span class="help-block small">反向關係將以此人物為主體創建</span>
                            </div>

                            <div class="form-group">
                                <label>當前單向關係中的 c_kin_code：</label>
                                <input type="number" name="c_kin_code" class="form-control" required
                                       placeholder="請輸入親屬關係代碼">
                                <span class="help-block small">此代碼用於定位當前的單向關係記錄</span>
                            </div>

                            <div class="form-group">
                                <label>新創建的 c_kin_code：</label>
                                <input type="number" name="new_c_kin_code" class="form-control" required
                                       placeholder="請輸入新的親屬關係代碼">
                                <span class="help-block small">反向關係將使用此代碼（例如：如果原關係是「父」，新關係應為「子」）</span>
                            </div>

                            <button type="submit" class="btn btn-primary" id="kinship-submit-btn">
                                <i class="fa fa-check"></i> 修復親屬關係
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fa fa-undo"></i> 重置
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
                            社會關係修復
                        </h3>
                    </div>
                    <div class="card-body">
                        <div id="assoc-result-container"></div>

                        <form id="assoc-repair-form">
                            {{ csrf_field() }}

                            <div class="form-group">
                                <label>當前單向關係中的 c_personid：</label>
                                <input type="number" name="c_personid" class="form-control" required
                                       placeholder="請輸入人物 ID">
                                <span class="help-block small">此人物的社會關係記錄將被用作來源</span>
                            </div>

                            <div class="form-group">
                                <label>當前單向關係中的 c_assoc_id：</label>
                                <input type="number" name="c_assoc_id" class="form-control" required
                                       placeholder="請輸入關聯人物 ID">
                                <span class="help-block small">反向關係將以此人物為主體創建</span>
                            </div>

                            <div class="form-group">
                                <label>當前單向關係中的 c_assoc_code：</label>
                                <input type="number" name="c_assoc_code" class="form-control" required
                                       placeholder="請輸入社會關係代碼">
                                <span class="help-block small">此代碼用於定位當前的單向關係記錄</span>
                            </div>

                            <div class="form-group">
                                <label>新創建的 c_assoc_code：</label>
                                <input type="number" name="new_c_assoc_code" class="form-control" required
                                       placeholder="請輸入新的社會關係代碼">
                                <span class="help-block small">反向關係將使用此代碼（例如：如果原關係是「師」，新關係應為「生」）</span>
                            </div>

                            <button type="submit" class="btn btn-success" id="assoc-submit-btn">
                                <i class="fa fa-check"></i> 修復社會關係
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fa fa-undo"></i> 重置
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- 說明文字 --}}
        <div class="panel panel-info">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-info-circle"></i> 說明</h3>
            </div>
            <div class="panel-body">
                <h4>功能說明</h4>
                <p>此工具用於修復 CBDB 資料庫中的單向關係，為已存在的單向親屬關係或社會關係創建對應的反向關係記錄。</p>

                <h4>親屬關係修復</h4>
                <ul>
                    <li><strong>使用場景：</strong>當親屬關係僅在一個方向上存在時，可使用此功能創建反向關係</li>
                    <li><strong>輸入參數：</strong>
                        <ul>
                            <li><code>c_personid</code>：當前記錄中的人物 ID</li>
                            <li><code>c_kin_id</code>：當前記錄中的親屬 ID</li>
                            <li><code>c_kin_code</code>：當前記錄中的親屬關係代碼</li>
                            <li><code>新創建的 c_kin_code</code>：反向關係應使用的代碼</li>
                        </ul>
                    </li>
                    <li><strong>操作邏輯：</strong>
                        <ol>
                            <li>系統會根據您提供的三個參數（c_personid、c_kin_id、c_kin_code）檢索 KIN_DATA 表</li>
                            <li>如果找到多條記錄，系統會提示錯誤並停止操作</li>
                            <li>如果找到唯一記錄，系統會將其 c_personid 和 c_kin_id 交換</li>
                            <li>使用新的 c_kin_code 創建反向關係記錄</li>
                            <li>其他欄位資訊（如來源、頁碼、備註等）沿用原記錄</li>
                        </ol>
                    </li>
                    <li><strong>範例：</strong>如果 A（c_personid=100）是 B（c_kin_id=200）的父親（c_kin_code=1），您可以創建 B 是 A 的兒子（new_c_kin_code=2）的反向關係</li>
                </ul>

                <h4>社會關係修復</h4>
                <ul>
                    <li><strong>使用場景：</strong>當社會關係僅在一個方向上存在時，可使用此功能創建反向關係</li>
                    <li><strong>輸入參數：</strong>
                        <ul>
                            <li><code>c_personid</code>：當前記錄中的人物 ID</li>
                            <li><code>c_assoc_id</code>：當前記錄中的關聯人物 ID</li>
                            <li><code>c_assoc_code</code>：當前記錄中的社會關係代碼</li>
                            <li><code>新創建的 c_assoc_code</code>：反向關係應使用的代碼</li>
                        </ul>
                    </li>
                    <li><strong>操作邏輯：</strong>
                        <ol>
                            <li>系統會根據您提供的三個參數（c_personid、c_assoc_id、c_assoc_code）檢索 ASSOC_DATA 表</li>
                            <li>如果找到多條記錄，系統會提示錯誤並停止操作</li>
                            <li>如果找到唯一記錄，系統會將其 c_personid 和 c_assoc_id 交換</li>
                            <li>使用新的 c_assoc_code 創建反向關係記錄</li>
                            <li>其他欄位資訊（如時間、地點、機構、來源等）沿用原記錄</li>
                        </ol>
                    </li>
                    <li><strong>範例：</strong>如果 A（c_personid=100）是 B（c_assoc_id=200）的老師（c_assoc_code=10），您可以創建 B 是 A 的學生（new_c_assoc_code=11）的反向關係</li>
                </ul>

                <h4>注意事項</h4>
                <ul>
                    <li class="text-danger"><strong>唯一性要求：</strong>系統要求您提供的參數組合能夠唯一定位一條記錄。如果找到多條記錄，操作將被中止</li>
                    <li class="text-danger"><strong>重複檢查：</strong>如果反向關係已存在，系統不會重複創建</li>
                    <li class="text-warning"><strong>關係代碼：</strong>請確保新創建的關係代碼（c_kin_code 或 c_assoc_code）是正確的反向關係代碼</li>
                    <li class="text-info"><strong>資料完整性：</strong>創建的反向關係記錄會保留原記錄的來源、備註等資訊，確保資料追溯性</li>
                    <li class="text-info"><strong>權限：</strong>此功能僅限活躍管理員使用</li>
                </ul>

                <h4>相關資料表</h4>
                <ul>
                    <li><code>KIN_DATA</code>：親屬關係資料表</li>
                    <li><code>KINSHIP_CODES</code>：親屬關係代碼表</li>
                    <li><code>ASSOC_DATA</code>：社會關係資料表</li>
                    <li><code>ASSOC_CODES</code>：社會關係代碼表</li>
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
onViteReady(function() {
    // 親屬關係修復表單提交
    $('#kinship-repair-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $('#kinship-submit-btn');
        var $resultContainer = $('#kinship-result-container');
        var originalBtnHtml = $btn.html();

        if (!confirm('確定要執行親屬關係修復嗎？\n\n此操作將創建一條新的反向關係記錄。')) {
            return false;
        }

        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中...');
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
                        '<strong>原始關係：</strong>' +
                        'c_personid=' + response.original.c_personid + ', ' +
                        'c_kin_id=' + response.original.c_kin_id + ', ' +
                        'c_kin_code=' + response.original.c_kin_code + '<br>' +
                        '<strong>新建關係：</strong>' +
                        'c_personid=' + response.created.c_personid + ', ' +
                        'c_kin_id=' + response.created.c_kin_id + ', ' +
                        'c_kin_code=' + response.created.c_kin_code +
                        '</div>';

                    $resultContainer.html(
                        '<div class="alert alert-success alert-dismissible">' +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '<strong>成功！</strong> ' + response.message + detailHtml +
                        '</div>'
                    );

                    $form[0].reset();
                } else {
                    $resultContainer.html(
                        '<div class="alert alert-danger alert-dismissible">' +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '<strong>失敗！</strong> ' + response.message +
                        '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).html(originalBtnHtml);

                var errorMsg = '處理失敗';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (status === 'timeout') {
                    errorMsg = '請求超時';
                } else if (xhr.status === 0) {
                    errorMsg = '網路連接失敗';
                } else {
                    errorMsg = '服務器錯誤 (' + xhr.status + ')';
                }

                $resultContainer.html(
                    '<div class="alert alert-danger alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<strong>錯誤！</strong> ' + errorMsg +
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

        if (!confirm('確定要執行社會關係修復嗎？\n\n此操作將創建一條新的反向關係記錄。')) {
            return false;
        }

        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中...');
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
                        '<strong>原始關係：</strong>' +
                        'c_personid=' + response.original.c_personid + ', ' +
                        'c_assoc_id=' + response.original.c_assoc_id + ', ' +
                        'c_assoc_code=' + response.original.c_assoc_code + '<br>' +
                        '<strong>新建關係：</strong>' +
                        'c_personid=' + response.created.c_personid + ', ' +
                        'c_assoc_id=' + response.created.c_assoc_id + ', ' +
                        'c_assoc_code=' + response.created.c_assoc_code +
                        '</div>';

                    $resultContainer.html(
                        '<div class="alert alert-success alert-dismissible">' +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '<strong>成功！</strong> ' + response.message + detailHtml +
                        '</div>'
                    );

                    $form[0].reset();
                } else {
                    $resultContainer.html(
                        '<div class="alert alert-danger alert-dismissible">' +
                        '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                        '<strong>失敗！</strong> ' + response.message +
                        '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false).html(originalBtnHtml);

                var errorMsg = '處理失敗';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                } else if (status === 'timeout') {
                    errorMsg = '請求超時';
                } else if (xhr.status === 0) {
                    errorMsg = '網路連接失敗';
                } else {
                    errorMsg = '服務器錯誤 (' + xhr.status + ')';
                }

                $resultContainer.html(
                    '<div class="alert alert-danger alert-dismissible">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<strong>錯誤！</strong> ' + errorMsg +
                    '</div>'
                );
            }
        });
    });
});
</script>
@endsection
