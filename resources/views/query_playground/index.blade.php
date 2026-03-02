@extends('layouts.dashboard-v3')

@push('styles')
<style>
.qbe-column-list {
    max-height: 420px;
    overflow-y: auto;
}
.qbe-dropzone {
    min-height: 52px;
    border: 2px dashed #ced4da;
    border-radius: 6px;
    padding: 8px;
    background: #f8f9fa;
}
.qbe-dropzone.is-dragover {
    border-color: #007bff;
    background: #e8f2ff;
}
.qbe-chip {
    display: inline-flex;
    align-items: center;
    margin: 4px 6px 4px 0;
    padding: 4px 8px;
    background: #e9ecef;
    border: 1px solid #ced4da;
    border-radius: 16px;
    font-size: 12px;
    cursor: default;
}
.qbe-chip .qbe-remove {
    border: 0;
    background: transparent;
    color: #dc3545;
    padding: 0 0 0 8px;
    line-height: 1;
}
.qbe-column-item {
    cursor: grab;
    user-select: none;
}
.qbe-column-item:active {
    cursor: grabbing;
}
</style>
@endpush

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
                    <li class="nav-item">
                        <a class="nav-link" id="qbe-tab" data-toggle="tab" href="#qbePanel" role="tab">
                            <i class="fas fa-project-diagram"></i> 查詢設計
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
                                <button class="btn btn-secondary ml-2" id="btnFormat" title="格式化 SQL 查詢">
                                    <i class="fas fa-magic"></i> 格式化
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

                    <!-- QBE Panel -->
                    <div class="tab-pane fade" id="qbePanel" role="tabpanel">
                        <div class="alert alert-secondary py-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            拖拽欄位到「選取欄位 / Group By / 排序」區塊，可用 Join + 條件組合建立複雜查詢，最後自動產生 SQL。
                        </div>

                        <div class="form-row mb-3">
                            <div class="col-md-5">
                                <label for="qbeBaseTable">主表</label>
                                <select id="qbeBaseTable" class="form-control">
                                    <option value="">請選擇主表</option>
                                    @foreach(($qbe_tables ?? []) as $table)
                                        <option value="{{ $table['name'] }}">{{ $table['name'] }} @if($table['description'])- {{ $table['description'] }}@endif</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-7">
                                <label>Join 設定</label>
                                <div class="d-flex">
                                    <button class="btn btn-outline-primary btn-sm" id="btnAddJoin" type="button">
                                        <i class="fas fa-plus"></i> 新增 Join
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm ml-2" id="btnQbeReset" type="button">
                                        <i class="fas fa-undo"></i> 重設設計器
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div id="qbeJoinRows" class="mb-3"></div>

                        <div class="row">
                            <div class="col-lg-4">
                                <div class="card card-outline card-info">
                                    <div class="card-header py-2">
                                        <h6 class="mb-0">可用欄位（可拖拽）</h6>
                                    </div>
                                    <div class="card-body qbe-column-list" id="qbeColumnsPalette">
                                        <div class="text-muted">請先選擇主表</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-8">
                                <div class="form-group">
                                    <label>SELECT 欄位</label>
                                    <div class="qbe-dropzone" id="qbeSelectDrop"></div>
                                </div>

                                <div class="form-group">
                                    <label>WHERE 條件</label>
                                    <div id="qbeWhereRows" class="mb-2"></div>
                                    <button class="btn btn-outline-primary btn-sm" type="button" id="btnAddWhere">
                                        <i class="fas fa-plus"></i> 新增條件
                                    </button>
                                </div>

                                <div class="form-group">
                                    <label>GROUP BY</label>
                                    <div class="qbe-dropzone" id="qbeGroupByDrop"></div>
                                </div>

                                <div class="form-group">
                                    <label>ORDER BY</label>
                                    <div class="qbe-dropzone" id="qbeOrderByDrop"></div>
                                </div>

                                <div class="form-row align-items-end">
                                    <div class="col-sm-3">
                                        <div class="custom-control custom-checkbox mb-2">
                                            <input type="checkbox" class="custom-control-input" id="qbeDistinct">
                                            <label class="custom-control-label" for="qbeDistinct">DISTINCT</label>
                                        </div>
                                    </div>
                                    <div class="col-sm-5">
                                        <label for="qbeLimit">LIMIT（可留空）</label>
                                        <input type="number" min="1" class="form-control" id="qbeLimit" placeholder="例如 100">
                                    </div>
                                    <div class="col-sm-4 text-right">
                                        <button class="btn btn-primary" id="btnBuildQbeSql" type="button">
                                            <i class="fas fa-cogs"></i> 產生 SQL
                                        </button>
                                        <button class="btn btn-success ml-1" id="btnBuildAndRunQbeSql" type="button">
                                            <i class="fas fa-play"></i> 產生並執行
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <label for="qbeSqlPreview">QBE 產生 SQL（可手修）</label>
                                    <textarea id="qbeSqlPreview" class="form-control" rows="6" style="font-family: monospace;"></textarea>
                                </div>
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

                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="useToolsCheckbox">
                                <label class="custom-control-label" for="useToolsCheckbox">
                                    工具使用（測試功能）
                                </label>
                            </div>
                            <small class="text-muted">未勾選時將使用最初提示詞與單次調用流程（提示詞包含所有表格的所有欄位定義）。</small>
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

                        <!-- Tool Calls Process Display -->
                        <div id="toolCallsContainer" style="display:none;">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-cogs"></i> LLM 工具調用過程：</h6>
                                <div id="toolCallsContent" class="mt-2" style="font-size: 0.9em;"></div>
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
    const qbeTables = @json($qbe_tables ?? []);
    const qbeSchemaCache = {};
    const qbeState = {
        baseTable: '',
        joins: [],
        selectFields: [],
        where: [],
        groupBy: [],
        orderBy: [],
    };

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

    $('#btnFormat').click(function() {
        const sql = $('#sqlInput').val();
        if (!sql.trim()) {
            return;
        }

        try {
            // 使用全局的 formatSql 函數（由 sqlFormatter.js 暴露）
            const formatted = window.formatSql(sql, {
                language: 'mysql',
                tabWidth: 2,
                keywordCase: 'upper',
                indentStyle: 'standard'
            });

            $('#sqlInput').val(formatted);

            // 視覺反饋
            const originalText = $(this).html();
            $(this).html('<i class="fas fa-check"></i> 已格式化');
            setTimeout(() => {
                $(this).html(originalText);
            }, 2000);
        } catch (error) {
            console.error('SQL 格式化失敗:', error);
            alert('SQL 格式化失敗，請檢查語法是否正確');
        }
    });

    function quoteIdentifier(expression) {
        return expression
            .split('.')
            .map(part => `\`${part.replace(/`/g, '')}\``)
            .join('.');
    }

    function loadQbeSchema(tableNames) {
        const tablesToLoad = (tableNames || []).filter(tableName => tableName && !qbeSchemaCache[tableName]);
        if (tablesToLoad.length === 0) {
            return Promise.resolve();
        }

        return $.ajax({
            url: "{{ route('query-playground.schema', [], false) }}",
            method: 'POST',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                tables: tablesToLoad,
            },
        }).then(function(response) {
            const tablePayload = response.tables || {};
            Object.keys(tablePayload).forEach(function(tableName) {
                qbeSchemaCache[tableName] = tablePayload[tableName];
            });
        });
    }

    function getQbeActiveTables() {
        const tables = [];
        if (qbeState.baseTable) {
            tables.push(qbeState.baseTable);
        }
        qbeState.joins.forEach(function(joinItem) {
            if (joinItem.table) {
                tables.push(joinItem.table);
            }
        });

        return [...new Set(tables)];
    }

    function renderQbeColumnsPalette() {
        const activeTables = getQbeActiveTables();
        const $palette = $('#qbeColumnsPalette');
        if (activeTables.length === 0) {
            $palette.html('<div class="text-muted">請先選擇主表</div>');

            return;
        }

        let html = '';
        activeTables.forEach(function(tableName) {
            const schema = qbeSchemaCache[tableName];
            if (!schema) {
                return;
            }
            const columns = schema.columns || [];
            html += `<div class="mb-2"><strong>${escapeHtml(tableName)}</strong>`;
            if (schema.description) {
                html += ` <small class="text-muted">${escapeHtml(schema.description)}</small>`;
            }
            html += '</div>';
            if (columns.length === 0) {
                html += '<div class="text-muted small mb-3">尚無可用欄位</div>';

                return;
            }
            html += '<div class="mb-3">';
            columns.forEach(function(column) {
                const expression = `${tableName}.${column.name}`;
                const label = column.type ? `${column.name} (${column.type})` : column.name;
                html += `<span class="badge badge-light border mr-1 mb-1 qbe-column-item" draggable="true" data-expression="${escapeHtml(expression)}">${escapeHtml(label)}</span>`;
            });
            html += '</div>';
        });

        $palette.html(html);
        $('.qbe-column-item').off('dragstart').on('dragstart', function(event) {
            const expression = String($(this).data('expression') || '');
            const nativeEvent = event.originalEvent || event;
            if (!nativeEvent.dataTransfer) {
                return;
            }
            nativeEvent.dataTransfer.effectAllowed = 'copy';
            // Set both mime types for broader browser compatibility.
            nativeEvent.dataTransfer.setData('text/plain', expression);
            nativeEvent.dataTransfer.setData('text', expression);
        });
    }

    function renderQbeJoins() {
        const activeTables = getQbeActiveTables();
        const options = ['<option value="">請選擇表格</option>'].concat(
            qbeTables.map(item => `<option value="${item.name}">${item.name}</option>`)
        ).join('');
        const fieldOptions = ['<option value="">請先選擇欄位</option>'];
        activeTables.forEach(function(tableName) {
            const schema = qbeSchemaCache[tableName];
            const schemaColumns = schema && schema.columns ? schema.columns : [];
            schemaColumns.forEach(function(column) {
                const expr = `${tableName}.${column.name}`;
                fieldOptions.push(`<option value="${expr}">${expr}</option>`);
            });
        });
        const fieldOptionHtml = fieldOptions.join('');

        let html = '';
        qbeState.joins.forEach(function(joinItem, index) {
            html += '<div class="border rounded p-2 mb-2">';
            html += '<div class="form-row">';
            html += '<div class="col-md-2">';
            html += `<select class="form-control form-control-sm qbe-join-type" data-index="${index}">
                        <option value="INNER JOIN" ${joinItem.type === 'INNER JOIN' ? 'selected' : ''}>INNER</option>
                        <option value="LEFT JOIN" ${joinItem.type === 'LEFT JOIN' ? 'selected' : ''}>LEFT</option>
                        <option value="RIGHT JOIN" ${joinItem.type === 'RIGHT JOIN' ? 'selected' : ''}>RIGHT</option>
                    </select>`;
            html += '</div>';
            html += `<div class="col-md-3"><select class="form-control form-control-sm qbe-join-table" data-index="${index}">${options}</select></div>`;
            html += `<div class="col-md-3"><select class="form-control form-control-sm qbe-join-left" data-index="${index}">${fieldOptionHtml}</select></div>`;
            html += `<div class="col-md-3"><select class="form-control form-control-sm qbe-join-right" data-index="${index}">${fieldOptionHtml}</select></div>`;
            html += `<div class="col-md-1 text-right"><button type="button" class="btn btn-sm btn-outline-danger qbe-remove-join" data-index="${index}"><i class="fas fa-times"></i></button></div>`;
            html += '</div>';
            html += '</div>';
        });

        $('#qbeJoinRows').html(html);
        qbeState.joins.forEach(function(joinItem, index) {
            $(`#qbeJoinRows .qbe-join-table[data-index="${index}"]`).val(joinItem.table || '');
            $(`#qbeJoinRows .qbe-join-left[data-index="${index}"]`).val(joinItem.left || '');
            $(`#qbeJoinRows .qbe-join-right[data-index="${index}"]`).val(joinItem.right || '');
        });
    }

    function createQbeChip(expression, type, direction = 'ASC') {
        const $chip = $('<span class="qbe-chip"></span>');
        $chip.attr('data-expression', expression);
        $chip.attr('data-type', type);
        $chip.text(expression);
        if (type === 'order') {
            const $direction = $('<select class="form-control form-control-sm ml-2" style="width:auto;display:inline-block;"></select>');
            $direction.append(`<option value="ASC" ${direction === 'ASC' ? 'selected' : ''}>ASC</option>`);
            $direction.append(`<option value="DESC" ${direction === 'DESC' ? 'selected' : ''}>DESC</option>`);
            $chip.append($direction);
        }
        $chip.append('<button type="button" class="qbe-remove" title="移除">×</button>');

        return $chip;
    }

    function syncQbeDropState() {
        qbeState.selectFields = [];
        $('#qbeSelectDrop .qbe-chip').each(function() {
            qbeState.selectFields.push({expression: $(this).data('expression')});
        });
        qbeState.groupBy = [];
        $('#qbeGroupByDrop .qbe-chip').each(function() {
            qbeState.groupBy.push({expression: $(this).data('expression')});
        });
        qbeState.orderBy = [];
        $('#qbeOrderByDrop .qbe-chip').each(function() {
            const direction = $(this).find('select').val() || 'ASC';
            qbeState.orderBy.push({expression: $(this).data('expression'), direction});
        });
    }

    function bindQbeDropzones() {
        ['#qbeSelectDrop', '#qbeGroupByDrop', '#qbeOrderByDrop'].forEach(function(selector) {
            const $zone = $(selector);
            $zone.off('dragover dragleave drop');
            $zone.on('dragover', function(event) {
                event.preventDefault();
                $(this).addClass('is-dragover');
            });
            $zone.on('dragleave', function() {
                $(this).removeClass('is-dragover');
            });
            $zone.on('drop', function(event) {
                event.preventDefault();
                $(this).removeClass('is-dragover');
                const nativeEvent = event.originalEvent || event;
                const dataTransfer = nativeEvent.dataTransfer;
                if (!dataTransfer) {
                    return;
                }
                const expression = dataTransfer.getData('text/plain') || dataTransfer.getData('text');
                if (!expression) {
                    return;
                }

                const type = selector === '#qbeSelectDrop' ? 'select' : (selector === '#qbeGroupByDrop' ? 'group' : 'order');
                const hasSameExpression = $(this).find('.qbe-chip').filter(function() {
                    return String($(this).data('expression')) === expression;
                }).length > 0;
                if (hasSameExpression) {
                    return;
                }
                const $chip = createQbeChip(expression, type);
                $(this).append($chip);
                bindQbeChipEvents();
                syncQbeDropState();
            });
        });
    }

    function bindQbeChipEvents() {
        $('.qbe-chip .qbe-remove').off('click').on('click', function() {
            $(this).closest('.qbe-chip').remove();
            syncQbeDropState();
        });
        $('#qbeOrderByDrop select').off('change').on('change', function() {
            syncQbeDropState();
        });
    }

    function renderQbeWhereRows() {
        const fieldOptions = ['<option value="">請選擇欄位</option>'];
        getQbeActiveTables().forEach(function(tableName) {
            const tableSchema = qbeSchemaCache[tableName];
            const tableColumns = tableSchema && tableSchema.columns ? tableSchema.columns : [];
            tableColumns.forEach(function(column) {
                const expression = `${tableName}.${column.name}`;
                fieldOptions.push(`<option value="${expression}">${expression}</option>`);
            });
        });
        const optionHtml = fieldOptions.join('');

        let html = '';
        qbeState.where.forEach(function(row, index) {
            html += '<div class="form-row mb-2">';
            html += '<div class="col-md-2">';
            if (index === 0) {
                html += '<input class="form-control form-control-sm" value="WHERE" disabled>';
            } else {
                html += `<select class="form-control form-control-sm qbe-where-logic" data-index="${index}">
                            <option value="AND" ${row.logic === 'AND' ? 'selected' : ''}>AND</option>
                            <option value="OR" ${row.logic === 'OR' ? 'selected' : ''}>OR</option>
                        </select>`;
            }
            html += '</div>';
            html += `<div class="col-md-3"><select class="form-control form-control-sm qbe-where-left" data-index="${index}">${optionHtml}</select></div>`;
            html += `<div class="col-md-2">
                        <select class="form-control form-control-sm qbe-where-op" data-index="${index}">
                            <option value="=" ${row.operator === '=' ? 'selected' : ''}>=</option>
                            <option value="!=" ${row.operator === '!=' ? 'selected' : ''}>!=</option>
                            <option value=">" ${row.operator === '>' ? 'selected' : ''}>></option>
                            <option value="<" ${row.operator === '<' ? 'selected' : ''}>&lt;</option>
                            <option value=">=" ${row.operator === '>=' ? 'selected' : ''}>>=</option>
                            <option value="<=" ${row.operator === '<=' ? 'selected' : ''}>&lt;=</option>
                            <option value="LIKE" ${row.operator === 'LIKE' ? 'selected' : ''}>LIKE</option>
                            <option value="IN" ${row.operator === 'IN' ? 'selected' : ''}>IN</option>
                        </select>
                    </div>`;
            html += `<div class="col-md-4"><input type="text" class="form-control form-control-sm qbe-where-right" data-index="${index}" placeholder="例如: 宋 或 15 或 ('A','B')"></div>`;
            html += `<div class="col-md-1 text-right"><button type="button" class="btn btn-sm btn-outline-danger qbe-remove-where" data-index="${index}"><i class="fas fa-times"></i></button></div>`;
            html += '</div>';
        });
        $('#qbeWhereRows').html(html);
        qbeState.where.forEach(function(row, index) {
            $(`#qbeWhereRows .qbe-where-left[data-index="${index}"]`).val(row.left || '');
            $(`#qbeWhereRows .qbe-where-right[data-index="${index}"]`).val(row.right || '');
        });
    }

    function bindQbeFormEvents() {
        $('#qbeJoinRows').off('change').on('change', '.qbe-join-type, .qbe-join-table, .qbe-join-left, .qbe-join-right', function() {
            const index = parseInt($(this).data('index'), 10);
            const $row = $(this).closest('.border');
            qbeState.joins[index] = {
                type: $row.find('.qbe-join-type').val(),
                table: $row.find('.qbe-join-table').val(),
                left: $row.find('.qbe-join-left').val(),
                right: $row.find('.qbe-join-right').val(),
            };

            const selectedTable = qbeState.joins[index].table;
            loadQbeSchema(selectedTable ? [selectedTable] : []).then(function() {
                renderQbeColumnsPalette();
                renderQbeJoins();
                renderQbeWhereRows();
            });
        });
        $('#qbeJoinRows').on('click', '.qbe-remove-join', function() {
            const index = parseInt($(this).data('index'), 10);
            qbeState.joins.splice(index, 1);
            renderQbeJoins();
            renderQbeColumnsPalette();
            renderQbeWhereRows();
        });

        $('#qbeWhereRows').off('change keyup').on('change keyup', '.qbe-where-logic, .qbe-where-left, .qbe-where-op, .qbe-where-right', function() {
            const index = parseInt($(this).data('index'), 10);
            const $row = $(this).closest('.form-row');
            qbeState.where[index] = {
                logic: $row.find('.qbe-where-logic').val() || 'AND',
                left: $row.find('.qbe-where-left').val() || '',
                operator: $row.find('.qbe-where-op').val() || '=',
                right: $row.find('.qbe-where-right').val() || '',
            };
        });
        $('#qbeWhereRows').on('click', '.qbe-remove-where', function() {
            const index = parseInt($(this).data('index'), 10);
            qbeState.where.splice(index, 1);
            renderQbeWhereRows();
        });
    }

    function formatSqlLiteral(rawValue, operator) {
        const value = (rawValue || '').trim();
        if (!value) {
            return '';
        }
        if (operator === 'IN') {
            return value.startsWith('(') ? value : `(${value})`;
        }
        if (/^[-]?\d+(\.\d+)?$/.test(value)) {
            return value;
        }
        if (/^(NULL)$/i.test(value)) {
            return 'NULL';
        }
        if ((value.startsWith("'") && value.endsWith("'")) || (value.startsWith('"') && value.endsWith('"'))) {
            return value;
        }

        return `'${value.replace(/'/g, "''")}'`;
    }

    function buildSqlFromQbeState() {
        if (!qbeState.baseTable) {
            return '';
        }

        const selectClause = qbeState.selectFields.length > 0
            ? qbeState.selectFields.map(item => quoteIdentifier(item.expression)).join(', ')
            : `${quoteIdentifier(qbeState.baseTable)}.*`;

        let sql = `SELECT ${$('#qbeDistinct').is(':checked') ? 'DISTINCT ' : ''}${selectClause}\n`;
        sql += `FROM ${quoteIdentifier(qbeState.baseTable)}\n`;

        qbeState.joins.forEach(function(joinItem) {
            if (!joinItem.table || !joinItem.left || !joinItem.right) {
                return;
            }
            sql += `${joinItem.type} ${quoteIdentifier(joinItem.table)} ON ${quoteIdentifier(joinItem.left)} = ${quoteIdentifier(joinItem.right)}\n`;
        });

        const whereParts = qbeState.where
            .filter(item => item.left && item.operator && item.right)
            .map(function(item, index) {
                const leftExpr = quoteIdentifier(item.left);
                const rightExpr = formatSqlLiteral(item.right, item.operator);
                if (!rightExpr) {
                    return '';
                }

                return `${index === 0 ? '' : (item.logic || 'AND') + ' '}${leftExpr} ${item.operator} ${rightExpr}`;
            })
            .filter(Boolean);
        if (whereParts.length > 0) {
            sql += `WHERE ${whereParts.join(' ')}\n`;
        }

        if (qbeState.groupBy.length > 0) {
            sql += `GROUP BY ${qbeState.groupBy.map(item => quoteIdentifier(item.expression)).join(', ')}\n`;
        }

        if (qbeState.orderBy.length > 0) {
            sql += `ORDER BY ${qbeState.orderBy.map(item => `${quoteIdentifier(item.expression)} ${item.direction || 'ASC'}`).join(', ')}\n`;
        }

        const limit = parseInt($('#qbeLimit').val(), 10);
        if (!Number.isNaN(limit) && limit > 0) {
            sql += `LIMIT ${limit}\n`;
        }

        return sql.trim();
    }

    function resetQbeDesigner() {
        qbeState.baseTable = '';
        qbeState.joins = [];
        qbeState.selectFields = [];
        qbeState.where = [];
        qbeState.groupBy = [];
        qbeState.orderBy = [];
        $('#qbeBaseTable').val('');
        $('#qbeDistinct').prop('checked', false);
        $('#qbeLimit').val('');
        $('#qbeSqlPreview').val('');
        $('#qbeSelectDrop').empty();
        $('#qbeGroupByDrop').empty();
        $('#qbeOrderByDrop').empty();
        renderQbeJoins();
        renderQbeWhereRows();
        renderQbeColumnsPalette();
    }

    $('#qbeBaseTable').on('change', function() {
        qbeState.baseTable = $(this).val();
        const tablesToLoad = getQbeActiveTables();
        loadQbeSchema(tablesToLoad).then(function() {
            renderQbeColumnsPalette();
            renderQbeJoins();
            renderQbeWhereRows();
        });
    });

    $('#btnAddJoin').on('click', function() {
        qbeState.joins.push({
            type: 'INNER JOIN',
            table: '',
            left: '',
            right: '',
        });
        renderQbeJoins();
    });

    $('#btnAddWhere').on('click', function() {
        qbeState.where.push({
            logic: 'AND',
            left: '',
            operator: '=',
            right: '',
        });
        renderQbeWhereRows();
    });

    $('#btnQbeReset').on('click', function() {
        resetQbeDesigner();
    });

    $('#btnBuildQbeSql').on('click', function() {
        syncQbeDropState();
        const sql = buildSqlFromQbeState();
        if (!sql) {
            alert('請先選擇主表與欄位');

            return;
        }
        $('#qbeSqlPreview').val(sql);
    });

    $('#btnBuildAndRunQbeSql').on('click', function() {
        syncQbeDropState();
        const sql = buildSqlFromQbeState();
        if (!sql) {
            alert('請先選擇主表與欄位');

            return;
        }
        $('#qbeSqlPreview').val(sql);
        $('#sqlInput').val(sql);
        $('#sql-tab').tab('show');
        setTimeout(() => {
            runQuery(1);
        }, 200);
    });

    bindQbeDropzones();
    bindQbeChipEvents();
    bindQbeFormEvents();
    renderQbeJoins();
    renderQbeWhereRows();
    renderQbeColumnsPalette();

    // Function to display tool calls in a user-friendly format
    function displayToolCalls(toolCalls) {
        let html = '<div class="timeline">';

        // Step 1: First LLM call discovers need for tools
        html += '<div class="time-label"><span class="bg-info">LLM 多步驟調用流程</span></div>';
        html += '<div>';
        html += '<i class="fas fa-robot bg-success"></i>';
        html += '<div class="timeline-item">';
        html += '<span class="time"><i class="far fa-clock"></i> 步驟 1</span>';
        html += '<h3 class="timeline-header"><i class="fas fa-search"></i> 第一次 LLM 調用</h3>';
        html += '<div class="timeline-body">';
        html += '<p class="mb-1">LLM 分析問題後發現需要額外資訊，請求調用工具獲取表格樣例數據。</p>';
        html += `<span class="badge badge-info">發現 ${toolCalls.length} 個工具調用需求</span>`;
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // Step 2: Tool executions
        toolCalls.forEach((call, index) => {
            html += '<div>';
            html += '<i class="fas fa-cog bg-primary"></i>';
            html += '<div class="timeline-item">';
            html += `<span class="time"><i class="far fa-clock"></i> 步驟 ${index + 2}</span>`;
            html += `<h3 class="timeline-header"><i class="fas fa-tools"></i> 執行工具: <code>${call.tool_name}</code></h3>`;
            html += '<div class="timeline-body">';

            // Display arguments
            html += '<p class="mb-1"><strong>調用參數：</strong></p>';
            html += '<pre style="background: #f9f9f9; padding: 8px; border-radius: 4px; font-size: 0.85em;">';
            html += escapeHtml(JSON.stringify(call.arguments, null, 2));
            html += '</pre>';

            // Display result
            html += '<p class="mt-2 mb-1"><strong>返回結果：</strong></p>';
            if (call.result.success) {
                const dataCount = call.result.data ? call.result.data.length : 0;
                html += '<span class="badge badge-success">成功</span> ';
                html += `<span class="text-muted">返回 ${dataCount} 筆數據</span>`;

                // Show sample data (first 3 records)
                if (call.result.data && call.result.data.length > 0) {
                    html += '<div class="mt-2"><small class="text-muted">樣例數據（前 3 筆）：</small></div>';
                    html += '<pre style="background: #f9f9f9; padding: 8px; border-radius: 4px; font-size: 0.85em; max-height: 200px; overflow-y: auto;">';
                    const sampleData = call.result.data.slice(0, 3);
                    html += escapeHtml(JSON.stringify(sampleData, null, 2));
                    html += '</pre>';
                }
            } else {
                html += '<span class="badge badge-danger">失敗</span> ';
                html += `<span class="text-danger">${escapeHtml(call.result.error || '未知錯誤')}</span>`;
            }

            html += '</div>';
            html += '</div>';
            html += '</div>';
        });

        // Step 3: Final LLM call generates SQL
        const finalStep = toolCalls.length + 2;
        html += '<div>';
        html += '<i class="fas fa-robot bg-success"></i>';
        html += '<div class="timeline-item">';
        html += `<span class="time"><i class="far fa-clock"></i> 步驟 ${finalStep}</span>`;
        html += '<h3 class="timeline-header"><i class="fas fa-check-circle"></i> 最終 LLM 調用</h3>';
        html += '<div class="timeline-body">';
        html += '<p class="mb-1">LLM 基於工具返回的樣例數據和資料庫 schema，生成最終的 SQL 查詢語句。</p>';
        html += '<span class="badge badge-success">SQL 生成完成</span>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        html += '<div><i class="far fa-clock bg-gray"></i></div>';
        html += '</div>';

        $('#toolCallsContent').html(html);
        $('#toolCallsContainer').show();
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Natural Language Query handlers
    // Enable/disable generate button based on consent checkbox
    $('#consentCheckbox').change(function() {
        const isChecked = $(this).is(':checked');
        $('#btnGenerate').prop('disabled', !isChecked);
    });

    function syncTabWithHash() {
        const hash = window.location.hash;
        const $tab = $(`#queryTabs a[href="${hash}"]`);
        if ($tab.length) {
            $tab.tab('show');
        }
    }

    $('#queryTabs a[data-toggle="tab"]').on('shown.bs.tab', function(event) {
        const target = $(event.target).attr('href');
        if (!target) {
            return;
        }

        if (history && history.replaceState) {
            history.replaceState(null, '', target);
        } else {
            window.location.hash = target;
        }
    });

    syncTabWithHash();
    window.addEventListener('hashchange', syncTabWithHash);

    // Reset consent checkbox when switching to NL tab
    $('#nl-tab').on('shown.bs.tab', function() {
        // Don't auto-reset checkbox - let user keep their choice during session
        // $('#consentCheckbox').prop('checked', false);
        // $('#btnGenerate').prop('disabled', true);
    });

    // EventSource 实例（用于 SSE）
    let eventSource = null;
    let realtimeStepCounter = 0;
    let realtimeToolResults = [];
    let toolExecutionSteps = {};

    // 初始化实时进度时间线
    function initializeRealtimeTimeline() {
        realtimeStepCounter = 0;
        realtimeToolResults = [];
        toolExecutionSteps = {};
        let html = '<div class="timeline" id="realtimeTimeline">';
        html += '<div class="time-label"><span class="bg-info">LLM 多步驟調用流程（實時）</span></div>';
        html += '</div>';
        $('#toolCallsContent').html(html);
        $('#toolCallsContainer').show();
    }

    // 添加时间线步骤
    function addTimelineStep(icon, bgClass, title, body) {
        realtimeStepCounter++;
        const $step = $('<div></div>');
        $step.append(`<i class="${icon} ${bgClass}"></i>`);
        const $item = $('<div class="timeline-item"></div>');
        $item.append(`<span class="time"><i class="far fa-clock"></i> 步驟 ${realtimeStepCounter}</span>`);
        $item.append(`<h3 class="timeline-header">${title}</h3>`);
        $item.append(`<div class="timeline-body">${body}</div>`);
        $step.append($item);

        // 在结束标记之前插入
        const $timeline = $('#realtimeTimeline');
        const $endMarker = $timeline.find('.fa-clock.bg-gray').parent();
        if ($endMarker.length > 0) {
            $endMarker.before($step);
        } else {
            $timeline.append($step);
        }

        return $step;
    }

    // 完成时间线
    function finalizeTimeline() {
        $('#realtimeTimeline').append('<div><i class="far fa-clock bg-gray"></i></div>');
    }

    // 处理 SSE 事件
    function handleSSEEvent(event, dataStr) {
        try {
            const data = JSON.parse(dataStr);
            console.log('SSE Event:', event, data);

            switch(event) {
                case 'llm_call_complete':
                    if (data.round === 1) {
                        addTimelineStep(
                            'fas fa-robot',
                            'bg-success',
                            '<i class="fas fa-search"></i> 第一次 LLM 調用',
                            `<p class="mb-1">${data.message || 'LLM 分析問題完成'}</p>`
                        );
                    } else {
                        const roundLabel = `第${data.round}次 LLM 調用`;
                        addTimelineStep(
                            'fas fa-robot',
                            'bg-success',
                            `<i class="fas fa-robot"></i> ${roundLabel}`,
                            `<p class="mb-1">${data.message || 'LLM 基於工具結果繼續推理'}</p>`
                        );
                    }
                    break;

                case 'tool_calls_requested':
                    const toolCount = data.tool_calls ? data.tool_calls.length : 0;
                    let toolsHtml = `<p class="mb-1">${data.message || 'LLM 請求調用工具'}</p>`;
                    toolsHtml += `<span class="badge badge-info">發現 ${toolCount} 個工具調用需求</span>`;
                    if (data.tool_calls) {
                        toolsHtml += '<ul class="mt-2 mb-0">';
                        data.tool_calls.forEach(tc => {
                            toolsHtml += `<li><code>${tc.name}</code>`;
                            if (tc.arguments && tc.arguments.table_name) {
                                toolsHtml += ` - 表格: ${tc.arguments.table_name}`;
                            }
                            toolsHtml += '</li>';
                        });
                        toolsHtml += '</ul>';
                    }
                    addTimelineStep(
                        'fas fa-tools',
                        'bg-warning',
                        '<i class="fas fa-cog"></i> 工具調用請求',
                        toolsHtml
                    );
                    break;

                case 'tool_execution_start':
                    const startArguments = data.arguments || {};
                    let startHtml = '<p class="mb-1">準備執行工具調用。</p>';
                    startHtml += '<p class="mb-1"><strong>調用參數：</strong></p>';
                    startHtml += '<pre style="background: #f9f9f9; padding: 8px; border-radius: 4px; font-size: 0.85em;">';
                    startHtml += escapeHtml(JSON.stringify(startArguments, null, 2));
                    startHtml += '</pre>';
                    const stepKey = data.tool_call_id || `${data.tool_name || 'tool'}-${data.tool_index || realtimeStepCounter + 1}`;
                    toolExecutionSteps[stepKey] = addTimelineStep(
                        'fas fa-cog',
                        'bg-secondary',
                        `<i class="fas fa-tools"></i> 開始執行工具: <code>${data.tool_name}</code>`,
                        startHtml
                    );
                    break;

                case 'tool_execution_complete':
                    const toolArguments = data.arguments || (data.result ? data.result.arguments : null) || {};
                    const toolResult = data.result || {};
                    let toolHtml = `<p class="mb-1"><strong>調用參數：</strong></p>`;
                    toolHtml += '<pre style="background: #f9f9f9; padding: 8px; border-radius: 4px; font-size: 0.85em;">';
                    toolHtml += escapeHtml(JSON.stringify(toolArguments, null, 2));
                    toolHtml += '</pre>';

                    toolHtml += '<p class="mt-2 mb-1"><strong>返回結果：</strong></p>';
                    if (data.success && toolResult.success) {
                        const resultSchema = toolResult.schema || null;
                        const resultData = toolResult.data || null;
                        toolHtml += '<span class="badge badge-success">成功</span> ';
                        if (resultSchema) {
                            const columnCount = resultSchema.columns ? resultSchema.columns.length : 0;
                            toolHtml += `<span class="text-muted">返回 ${columnCount} 個欄位</span>`;

                            const schemaSample = resultSchema.columns ? resultSchema.columns.slice(0, 12) : resultSchema;
                            toolHtml += '<div class="mt-2"><small class="text-muted">結構樣例（前 12 欄位）：</small></div>';
                            toolHtml += '<pre style="background: #f9f9f9; padding: 8px; border-radius: 4px; font-size: 0.85em; max-height: 200px; overflow-y: auto;">';
                            toolHtml += escapeHtml(JSON.stringify(schemaSample, null, 2));
                            toolHtml += '</pre>';
                        } else {
                            const dataCount = resultData ? resultData.length : 0;
                            toolHtml += `<span class="text-muted">返回 ${dataCount} 筆數據</span>`;

                            if (resultData && resultData.length > 0) {
                                toolHtml += '<div class="mt-2"><small class="text-muted">樣例數據（前 3 筆）：</small></div>';
                                toolHtml += '<pre style="background: #f9f9f9; padding: 8px; border-radius: 4px; font-size: 0.85em; max-height: 200px; overflow-y: auto;">';
                                const sampleData = resultData.slice(0, 3);
                                toolHtml += escapeHtml(JSON.stringify(sampleData, null, 2));
                                toolHtml += '</pre>';
                            }
                        }
                    } else {
                        toolHtml += '<span class="badge badge-danger">失敗</span> ';
                        toolHtml += `<span class="text-danger">${escapeHtml(toolResult.error || '未知錯誤')}</span>`;
                    }

                    const completeKey = data.tool_call_id || `${data.tool_name || 'tool'}-${data.tool_index || realtimeStepCounter + 1}`;
                    const $existingStep = toolExecutionSteps[completeKey];
                    if ($existingStep && $existingStep.length) {
                        $existingStep.children('i').attr('class', 'fas fa-cog bg-primary');
                        $existingStep.find('.timeline-header').html(`<i class="fas fa-tools"></i> 執行工具: <code>${data.tool_name}</code>`);
                        $existingStep.find('.timeline-body').html(toolHtml);
                    } else {
                        addTimelineStep(
                            'fas fa-cog',
                            'bg-primary',
                            `<i class="fas fa-tools"></i> 執行工具: <code>${data.tool_name}</code>`,
                            toolHtml
                        );
                    }

                    // 保存工具结果
                    realtimeToolResults.push(data);
                    break;

                case 'complete':
                    addTimelineStep(
                        'fas fa-check-circle',
                        'bg-success',
                        '<i class="fas fa-check-circle"></i> SQL 生成完成',
                        '<p class="mb-1">已完成 SQL 生成，請查看結果區塊。</p>'
                    );
                    finalizeTimeline();
                    if (data.success && data.sql) {
                        generatedSqlText = data.sql;
                        $('#generatedSql').text(data.sql);

                        if (data.explanation) {
                            $('#sqlExplanation').html('<strong>說明：</strong>' + data.explanation);
                        } else {
                            $('#sqlExplanation').empty();
                        }

                        if (data.model) {
                            $('#generatedModel').html('使用模型：<code>' + data.model + '</code>');
                        } else {
                            $('#generatedModel').empty();
                        }

                        $('#generatedSqlContainer').show();
                    } else {
                        const errorMsg = data.error || '生成 SQL 失敗';
                        $('#errorAlert').html('<strong>錯誤：</strong>' + errorMsg).show();
                    }
                    $('#btnGenerate').prop('disabled', false);
                    $('#nlLoadingIndicator').hide();
                    break;

                case 'error':
                    const isToolLimitError = (data.error || '').includes('工具調用次數超過上限');
                    const errorHint = isToolLimitError
                        ? '<p class="mb-0 text-muted">建議縮小查詢範圍或提供更明確的問題。</p>'
                        : '';
                    addTimelineStep(
                        'fas fa-times-circle',
                        'bg-danger',
                        '<i class="fas fa-times-circle"></i> 生成失敗',
                        `<p class="mb-1">${escapeHtml(data.error || '發生錯誤')}</p>${errorHint}`
                    );
                    finalizeTimeline();
                    const errorMsg = data.error || '發生錯誤';
                    $('#errorAlert').html('<strong>錯誤：</strong>' + errorMsg).show();
                    $('#btnGenerate').prop('disabled', false);
                    $('#nlLoadingIndicator').hide();
                    break;
            }
        } catch (e) {
            console.error('Error handling SSE event:', e);
        }
    }

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

        // 关闭之前的 EventSource（如果存在）
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }

        $('#btnGenerate').prop('disabled', true);
        $('#nlLoadingIndicator').show();
        $('#generatedSqlContainer').hide();
        $('#toolCallsContainer').hide();
        $('#errorAlert').hide();

        // 初始化实时进度时间线
        initializeRealtimeTimeline();

        // 准备查询参数（使用 URLSearchParams）
        const params = new URLSearchParams({
            _token: $('meta[name="csrf-token"]').attr('content'),
            question: question
        });
        params.append('use_tools', $('#useToolsCheckbox').is(':checked') ? '1' : '0');

        // 创建 EventSource（使用 POST 模拟：通过带参数的 URL）
        // 注意：浏览器原生 EventSource 不支持 POST，需要特殊处理
        // 这里我们使用 fetch + ReadableStream 来实现
        fetch("{{ route('query-playground.generate-from-nl-stream', [], false) }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                'Accept': 'text/event-stream'
            },
            body: params.toString()
        }).then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            function processStream() {
                reader.read().then(({done, value}) => {
                    if (done) {
                        $('#btnGenerate').prop('disabled', false);
                        $('#nlLoadingIndicator').hide();
                        return;
                    }

                    buffer += decoder.decode(value, {stream: true});
                    const lines = buffer.split('\n');
                    buffer = lines.pop(); // 保留不完整的行

                    let currentEvent = null;
                    let currentData = '';

                    lines.forEach(line => {
                        if (line.startsWith('event: ')) {
                            currentEvent = line.substring(7).trim();
                        } else if (line.startsWith('data: ')) {
                            currentData = line.substring(6).trim();
                        } else if (line === '' && currentEvent) {
                            // 完整的事件消息
                            handleSSEEvent(currentEvent, currentData);
                            currentEvent = null;
                            currentData = '';
                        }
                    });

                    processStream();
                }).catch(error => {
                    console.error('Stream reading error:', error);
                    $('#errorAlert').html('<strong>錯誤：</strong>連接中斷').show();
                    $('#btnGenerate').prop('disabled', false);
                    $('#nlLoadingIndicator').hide();
                });
            }

            processStream();
        }).catch(error => {
            console.error('Fetch error:', error);
            $('#errorAlert').html('<strong>錯誤：</strong>無法連接到服務器').show();
            $('#btnGenerate').prop('disabled', false);
            $('#nlLoadingIndicator').hide();
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
