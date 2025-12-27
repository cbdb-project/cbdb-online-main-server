@extends('layouts.dashboard-v3')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-terminal mr-1"></i> {{ $page_title }}</h3>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> {{ $page_description }}。每頁限制 20 筆。
                </div>

                <!-- Tab Navigation -->
                <ul class="nav nav-tabs" id="queryTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="sql-tab" data-toggle="tab" href="#sqlPanel" role="tab">
                            <i class="fas fa-code"></i> SQL 查詢
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="nl-tab" data-toggle="tab" href="#nlPanel" role="tab">
                            <i class="fas fa-comment-dots"></i> 自然語言查詢
                        </a>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content mt-3" id="queryTabContent">
                    <!-- SQL Query Panel -->
                    <div class="tab-pane fade show active" id="sqlPanel" role="tabpanel">
                        <div class="form-group">
                            <label for="sqlInput">SQL Query:</label>
                            <textarea class="form-control" id="sqlInput" rows="5" style="font-family: monospace;">{{ $initial_sql ?? 'SELECT * FROM DYNASTIES' }}</textarea>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <button class="btn btn-primary" id="btnRun">
                                    <i class="fas fa-play"></i> 執行查詢 (Run)
                                </button>
                                <button class="btn btn-default ml-2" id="btnShare" title="複製分享連結">
                                    <i class="fas fa-share-alt"></i> 複製連結
                                </button>
                            </div>
                            <div id="loadingIndicator" style="display:none;">
                                <div class="spinner-border text-primary spinner-border-sm" role="status"></div> 查詢中...
                            </div>
                        </div>
                    </div>

                    <!-- Natural Language Query Panel -->
                    <div class="tab-pane fade" id="nlPanel" role="tabpanel">
                        <!-- Privacy Notice -->
                        <div class="alert alert-warning">
                            <h6><i class="fas fa-exclamation-triangle"></i> 重要提示：數據收集與第三方服務</h6>
                            <p class="mb-2">
                                使用本功能即表示您理解並同意：
                            </p>
                            <ul class="mb-2" style="font-size: 0.9em;">
                                <li>您的問題和生成的 SQL 將被記錄用於研究與改進</li>
                                <li>您的查詢將發送至第三方 AI 服務（Google Gemini API、OpenAI API 等，恕不另行通知）進行處理</li>
                                <li>詳細數據收集說明請參閱 <a href="#" data-toggle="modal" data-target="#privacyModal">隱私條款</a></li>
                            </ul>
                        </div>

                        <div class="form-group">
                            <label for="nlInput">自然語言問題:</label>
                            <textarea class="form-control" id="nlInput" rows="3" placeholder="例如：顯示所有朝代名稱"></textarea>
                        </div>

                        <!-- Consent Checkbox -->
                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="consentCheckbox">
                                <label class="custom-control-label" for="consentCheckbox">
                                    我已閱讀並同意 <a href="#" data-toggle="modal" data-target="#privacyModal">數據收集與隱私條款</a>，理解我的查詢將被記錄並發送至第三方服務處理
                                </label>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <button class="btn btn-success" id="btnGenerate" disabled>
                                    <i class="fas fa-magic"></i> 生成 SQL
                                </button>
                                <small class="text-muted ml-2">使用模型：<code>{{ $nl_model }}</code></small>
                            </div>
                            <div id="nlLoadingIndicator" style="display:none;">
                                <div class="spinner-border text-success spinner-border-sm" role="status"></div> 生成中 ({{ $nl_model }})...
                            </div>
                        </div>

                        <!-- Generated SQL Display -->
                        <div id="generatedSqlContainer" style="display:none;">
                            <div class="alert alert-success">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6><i class="fas fa-check-circle"></i> 生成的 SQL：</h6>
                                    <small class="text-muted" id="generatedModel"></small>
                                </div>
                                <pre id="generatedSql" style="background: #f4f4f4; padding: 10px; border-radius: 4px; margin-top: 10px;"></pre>
                                <div id="sqlExplanation" class="mt-2" style="font-size: 0.9em;"></div>
                            </div>
                            <div class="mt-2">
                                <button class="btn btn-primary" id="btnUseGenerated">
                                    <i class="fas fa-arrow-right"></i> 使用此 SQL 並執行
                                </button>
                                <button class="btn btn-secondary" id="btnCopyGenerated">
                                    <i class="fas fa-copy"></i> 複製到 SQL 查詢面板
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card" id="resultCard" style="display: none;">
            <div class="card-header">
                <h3 class="card-title">查詢結果</h3>
                <div class="card-tools">
                    <button class="btn btn-sm btn-secondary" id="btnPrev" disabled><i class="fas fa-chevron-left"></i> 上一頁</button>
                    <span class="px-2" id="pageDisplay">Page 1</span>
                    <button class="btn btn-sm btn-secondary" id="btnNext" disabled>下一頁 <i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
            <div class="card-body table-responsive p-0">
                <table class="table table-hover text-nowrap" id="resultTable">
                    <thead>
                        <tr id="tableHead"></tr>
                    </thead>
                    <tbody id="tableBody"></tbody>
                </table>
                <div id="noResults" class="p-3 text-center text-muted" style="display:none;">無資料 (No data found)</div>
            </div>
        </div>
        
        <div class="alert alert-danger mt-3" id="errorAlert" style="display: none;"></div>
    </div>
</div>

<!-- Privacy Modal -->
<div class="modal fade" id="privacyModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-shield-alt"></i> 自然語言查詢功能 - 數據收集與隱私條款
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <h6><strong>1. 功能說明</strong></h6>
                <p>
                    本功能使用人工智能大型語言模型（LLM）將您的自然語言問題轉換為 SQL 查詢語句。
                    此功能旨在協助用戶更方便地查詢資料庫，無需熟悉 SQL 語法。
                </p>

                <h6><strong>2. 第三方服務</strong></h6>
                <p>
                    本功能使用第三方 AI 服務（包括但不限於 <strong>Google Gemini API、OpenAI API</strong> 等）進行自然語言處理。
                    系統保留隨時切換服務提供商的權利，恕不另行通知。當您提交問題時：
                </p>
                <ul>
                    <li>您的問題文本將被發送至第三方服務提供商的服務器進行處理</li>
                    <li>資料庫結構信息（表名和欄位名）將一併發送以協助生成準確的查詢</li>
                    <li>服務提供商可能會根據其服務條款收集和處理這些數據</li>
                </ul>
                <p>
                    使用本功能即表示您同意接受相關第三方服務的條款，包括但不限於：
                </p>
                <ul>
                    <li><a href="https://ai.google.dev/gemini-api/terms" target="_blank" rel="noopener">Google Gemini API 服務條款</a>
                        和 <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Google 隱私政策</a></li>
                    <li><a href="https://openai.com/policies/terms-of-use" target="_blank" rel="noopener">OpenAI 使用條款</a>
                        和 <a href="https://openai.com/policies/privacy-policy" target="_blank" rel="noopener">OpenAI 隱私政策</a></li>
                    <li>其他可能使用的 AI 服務提供商的相關條款</li>
                </ul>
                <p class="text-muted" style="font-size: 0.9em;">
                    <strong>注意：</strong>系統可能在不同時間使用不同的服務提供商，且可能不會事先通知。
                    建議您在使用前查閱上述所有服務條款。
                </p>

                <h6><strong>3. 系統日誌記錄</strong></h6>
                <p>
                    為了研究和改進本功能，系統將記錄以下資訊：
                </p>
                <ul>
                    <li><strong>您的用戶 ID</strong>（如果已登入）</li>
                    <li><strong>您提交的自然語言問題</strong>（完整文本）</li>
                    <li><strong>生成的 SQL 查詢語句</strong></li>
                    <li><strong>完整的 LLM 提示詞</strong>（包含資料庫結構信息）</li>
                    <li><strong>LLM 的原始響應</strong></li>
                    <li><strong>執行時間和成功狀態</strong></li>
                    <li><strong>錯誤信息</strong>（如果發生）</li>
                </ul>
                <p>
                    這些記錄將用於：
                </p>
                <ul>
                    <li>分析功能使用模式</li>
                    <li>改進提示詞策略</li>
                    <li>優化查詢生成質量</li>
                    <li>系統性能監控和故障排查</li>
                </ul>

                <h6><strong>4. 數據保留</strong></h6>
                <p>
                    系統記錄將無限期保留於系統資料庫中，用於持續改進服務。
                    管理員可存取這些記錄以進行研究和系統維護。
                </p>

                <h6><strong>5. 數據安全</strong></h6>
                <p>
                    我們採取合理的技術措施保護您的數據，但無法保證絕對安全。
                    請勿在查詢中包含敏感個人資訊。
                </p>

                <h6><strong>6. 您的權利</strong></h6>
                <p>
                    <strong>本功能為可選功能。</strong>如果您不同意上述條款：
                </p>
                <ul>
                    <li>請勿使用自然語言查詢功能</li>
                    <li>您仍可使用傳統的 SQL 查詢面板進行資料庫查詢</li>
                </ul>

                <h6><strong>7. 條款變更</strong></h6>
                <p>
                    我們保留隨時修改本條款的權利。由於每次使用都需要您的明確同意，
                    您可以在每次使用前查看最新版本的條款。
                </p>

                <h6><strong>8. 聯絡我們</strong></h6>
                <p>
                    如對本條款或數據收集有任何疑問，請聯繫系統管理員。
                </p>

                <p class="text-muted mt-3" style="font-size: 0.85em;">
                    <strong>最後更新：</strong>2025年12月<br>
                    <strong>版本：</strong>1.0
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">關閉</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('js')
<script>
onViteReady(function() {
    let currentPage = 1;
    let generatedSqlText = '';

    // Check if initial SQL provided via URL param (already populated in UI by controller), maybe auto-run?
    // User didn't strictly ask for auto-run, but it's often expected. Let's stick to manual run for safety first.

    function updateUrl(sql) {
        const url = new URL(window.location);
        url.searchParams.set('sql', sql);
        window.history.pushState({path: url.href}, '', url.href);
    }

    function runQuery(page = 1) {
        const sql = $('#sqlInput').val();
        if (!sql.trim()) return;

        // Update URL on run
        updateUrl(sql);

        $('#btnRun').prop('disabled', true);
        $('#loadingIndicator').show();
        $('#errorAlert').hide();
        $('#resultCard').hide();
        
        // Reset table
        $('#tableHead').empty();
        $('#tableBody').empty();

        $.ajax({
            url: "{{ route('query-playground.run', [], false) }}",
            method: 'POST',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                sql: sql,
                page: page
            },
            success: function(response) {
                renderResults(response);
                currentPage = page;
                updatePagination(response);
                $('#resultCard').show();
            },
            error: function(xhr) {
                let msg = '發生錯誤';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    msg = xhr.responseJSON.error;
                } else if (xhr.status === 403) {
                    msg = '禁止的查詢 (403 Forbidden)';
                }
                const errorHtml = `<strong>Error:</strong> ${msg}`;
                $('#errorAlert').html(errorHtml).show();
            },
            complete: function() {
                $('#btnRun').prop('disabled', false);
                $('#loadingIndicator').hide();
            }
        });
    }

    function renderResults(data) {
        const rows = data.data;
        const columns = data.columns;

        if (!rows || rows.length === 0) {
            $('#noResults').show();
            $('#resultTable').hide();
            return;
        }

        $('#noResults').hide();
        $('#resultTable').show();

        // Render Head
        let theadHtml = '';
        columns.forEach(col => {
            theadHtml += `<th>${col}</th>`;
        });
        $('#tableHead').html(theadHtml);

        // Render Body
        let tbodyHtml = '';
        rows.forEach(row => {
            tbodyHtml += '<tr>';
            columns.forEach(col => {
                let val = row[col];
                let displayVal = '';

                if (val === null) {
                    displayVal = '<span class="text-muted">NULL</span>';
                } else {
                    // Basic XSS protection for display
                    displayVal = $('<div>').text(val).html(); 
                }

                tbodyHtml += `<td>${displayVal}</td>`;
            });
            tbodyHtml += '</tr>';
        });
        $('#tableBody').html(tbodyHtml);
    }

    function updatePagination(data) {
        $('#pageDisplay').text(`Page ${data.page}`);
        
        $('#btnPrev').prop('disabled', data.page <= 1);
        $('#btnNext').prop('disabled', !data.has_more);
        
        // Unbind previous clicks to avoid accumulation
        $('#btnPrev').off('click').on('click', function() {
            if (currentPage > 1) runQuery(currentPage - 1);
        });
        
        $('#btnNext').off('click').on('click', function() {
            if (data.has_more) runQuery(currentPage + 1);
        });
    }

    $('#btnRun').click(function() {
        runQuery(1);
    });

    $('#btnShare').click(function() {
        const sql = $('#sqlInput').val();
         updateUrl(sql);

        navigator.clipboard.writeText(window.location.href).then(function() {
            // Simple visual feedback
            const originalText = $('#btnShare').html();
            $('#btnShare').html('<i class="fas fa-check"></i> 已複製');
            setTimeout(() => {
                $('#btnShare').html(originalText);
            }, 2000);
        }, function(err) {
            console.error('Could not copy text: ', err);
            alert('複製失敗，請手動複製網址列');
        });
    });

    // Natural Language Query handlers
    // Enable/disable generate button based on consent checkbox
    $('#consentCheckbox').change(function() {
        const isChecked = $(this).is(':checked');
        $('#btnGenerate').prop('disabled', !isChecked);
    });

    // Reset consent checkbox when switching to NL tab
    $('#nl-tab').on('shown.bs.tab', function() {
        // Don't auto-reset checkbox - let user keep their choice during session
        // $('#consentCheckbox').prop('checked', false);
        // $('#btnGenerate').prop('disabled', true);
    });

    $('#btnGenerate').click(function() {
        const question = $('#nlInput').val().trim();
        if (!question) {
            alert('請輸入自然語言問題');
            return;
        }

        // Double-check consent
        if (!$('#consentCheckbox').is(':checked')) {
            alert('請先閱讀並同意數據收集與隱私條款');
            return;
        }

        $('#btnGenerate').prop('disabled', true);
        $('#nlLoadingIndicator').show();
        $('#generatedSqlContainer').hide();
        $('#errorAlert').hide();

        $.ajax({
            url: "{{ route('query-playground.generate-from-nl', [], false) }}",
            method: 'POST',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                question: question
            },
            success: function(response) {
                if (response.success && response.sql) {
                    generatedSqlText = response.sql;
                    $('#generatedSql').text(response.sql);

                    if (response.explanation) {
                        $('#sqlExplanation').html('<strong>說明：</strong>' + response.explanation);
                    } else {
                        $('#sqlExplanation').empty();
                    }

                    if (response.model) {
                        $('#generatedModel').html('使用模型：<code>' + response.model + '</code>');
                    } else {
                        $('#generatedModel').empty();
                    }

                    $('#generatedSqlContainer').show();
                } else {
                    const errorMsg = response.error || '生成 SQL 失敗';
                    $('#errorAlert').html('<strong>錯誤：</strong>' + errorMsg).show();
                }
            },
            error: function(xhr) {
                let msg = '發生錯誤';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    msg = xhr.responseJSON.error;
                }
                $('#errorAlert').html('<strong>錯誤：</strong>' + msg).show();
            },
            complete: function() {
                $('#btnGenerate').prop('disabled', false);
                $('#nlLoadingIndicator').hide();
            }
        });
    });

    $('#btnUseGenerated').click(function() {
        if (generatedSqlText) {
            $('#sqlInput').val(generatedSqlText);
            // Switch to SQL tab
            $('#sql-tab').tab('show');
            // Run the query
            setTimeout(() => {
                runQuery(1);
            }, 300);
        }
    });

    $('#btnCopyGenerated').click(function() {
        if (generatedSqlText) {
            $('#sqlInput').val(generatedSqlText);
            // Switch to SQL tab
            $('#sql-tab').tab('show');

            // Visual feedback
            const originalText = $(this).html();
            $(this).html('<i class="fas fa-check"></i> 已複製');
            setTimeout(() => {
                $(this).html(originalText);
            }, 2000);
        }
    });
});
</script>
@endsection
