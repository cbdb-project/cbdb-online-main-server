@extends('layouts.dashboard-v3')

@section('content')

@if(session('success'))
            <div class="alert alert-success alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                <h4><i class="icon fa fa-check"></i> 成功！</h4>
                {{ session('success') }}
            </div>
        @endif

        @if(session('token'))
            <div class="alert alert-info alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
                <h4><i class="icon fa fa-key"></i> API Token 已創建</h4>
                <p>請妥善保存以下 Token，它只會顯示一次：</p>
                <div class="input-group">
                    <input type="text" class="form-control" value="{{ session('token') }}" id="new-token" readonly>
                    <div class="input-group-append">
                        <button class="btn btn-outline-secondary" type="button" onclick="copyToken(event)">
                            <i class="fa fa-copy"></i> 複製
                        </button>
                    </div>
                </div>
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

<!-- Container for dynamic alerts -->
<div id="alerts-container"></div>

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

<!-- API Token 管理 -->
<div class="card card-info mt-3">
    <div class="card-header">
        <h3 class="card-title">API 訪問令牌管理</h3>
    </div>
    <div class="card-body">
        <p>API Token 可用於在外部應用程序或腳本中訪問 CBDB API。請妥善保管您的 Token。</p>

        <!-- 創建新 Token 表單 -->
        <div class="card card-default collapsed-card">
            <div class="card-header">
                <h3 class="card-title">創建新 Token</h3>
                <div class="card-tools">
                    <button type="button" class="btn btn-tool" data-card-widget="collapse">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <div class="card-body" style="display: none;">
                <form id="create-token-form">
                    <div class="form-group">
                        <label for="token-name">Token 名稱 <span class="text-red">*</span></label>
                        <input type="text" class="form-control" id="token-name" name="name"
                               placeholder="例如：我的 Python 腳本" required>
                        <small class="form-text text-muted">為這個 Token 取一個容易識別的名稱</small>
                    </div>

                    <div class="form-group">
                        <label for="token-expires">有效期限</label>
                        <select class="form-control" id="token-expires" name="expires_in">
                            <option value="">永久有效</option>
                            <option value="30">30 天</option>
                            <option value="90">90 天</option>
                            <option value="180">180 天</option>
                            <option value="365">1 年</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-key"></i> 創建 Token
                    </button>
                </form>
            </div>
        </div>

        <!-- Token 列表 -->
        <div class="mt-3">
            <h5>現有 Token</h5>
            <div id="tokens-list">
                <p class="text-muted">正在載入...</p>
            </div>
        </div>
    </div>
</div>

@endsection

@section('js')
<script>
// 時區相關變數與函數（參考 operations 頁面）
var userTimeZone = (Intl.DateTimeFormat().resolvedOptions().timeZone) || 'UTC';

function formatTimestamp(utcTimeString, targetTimeZone) {
    try {
        var utcDate = new Date(utcTimeString);
        if (isNaN(utcDate.getTime())) {
            console.warn('Invalid time:', utcTimeString);
            return utcTimeString;
        }

        var zone = targetTimeZone || userTimeZone;
        var parts = new Intl.DateTimeFormat(undefined, {
            timeZone: zone,
            timeZoneName: 'short'
        }).formatToParts(utcDate);
        var timeZoneName = '';
        for (var i = 0; i < parts.length; i++) {
            if (parts[i].type === 'timeZoneName') {
                timeZoneName = parts[i].value || '';
                break;
            }
        }

        var dateTimeWithoutTZ = utcDate.toLocaleString('sv-SE', {
            timeZone: zone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });

        return dateTimeWithoutTZ.replace(' ', ' ') + ' (' + timeZoneName + ')';
    } catch (e) {
        console.error('formatTimestamp error:', e);
        return utcTimeString;
    }
}

// Show inline alert message
function showMessage(message, type = 'success', autoDismiss = true) {
    const alertsContainer = document.getElementById('alerts-container');
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show`;
    alert.innerHTML = `
        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
        ${message}
    `;
    alertsContainer.appendChild(alert);

    // Auto dismiss after 5 seconds (unless disabled)
    if (autoDismiss) {
        setTimeout(() => {
            alert.classList.remove('show');
            setTimeout(() => alert.remove(), 150);
        }, 5000);
    }
}

// Copy token using modern Clipboard API
function copyToken(event) {
    const tokenInput = document.getElementById('new-token');
    if (!tokenInput) return;

    const text = tokenInput.value;
    const btn = event.target.closest('button');

    // Use modern Clipboard API with fallback
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text)
            .then(() => {
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fa fa-check"></i> 已複製';
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                }, 2000);
            })
            .catch(err => {
                console.error('複製失敗:', err);
                // Fallback to select
                tokenInput.select();
                showMessage('請使用 Ctrl+C / ⌘+C 手動複製', 'warning');
            });
    } else {
        // Fallback for older browsers
        tokenInput.select();
        try {
            document.execCommand('copy');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-check"></i> 已複製';
            setTimeout(() => {
                btn.innerHTML = originalHTML;
            }, 2000);
        } catch (err) {
            showMessage('請使用 Ctrl+C / ⌘+C 手動複製', 'warning');
        }
    }
}

// Load user's API tokens
function loadTokens() {
    axios.get('{{ route('api-tokens.index', [], false) }}')
        .then(response => {
            const tokens = response.data;
            const listDiv = document.getElementById('tokens-list');

            if (tokens.length === 0) {
                listDiv.innerHTML = '<p class="text-muted">尚未創建任何 Token</p>';
                return;
            }

            let html = '<div class="table-responsive"><table class="table table-bordered table-sm">';
            html += '<thead><tr><th>名稱</th><th>創建時間</th><th>最後使用</th><th>到期時間</th><th>操作</th></tr></thead><tbody>';

            tokens.forEach(token => {
                html += '<tr>';
                html += '<td>' + escapeHtml(token.name) + '</td>';
                html += '<td>' + formatDate(token.created_at) + '</td>';
                html += '<td>' + (token.last_used_at ? formatDate(token.last_used_at) : '從未使用') + '</td>';
                html += '<td>' + (token.expires_at ? formatDate(token.expires_at) : '永久') + '</td>';
                // Use data attributes to prevent XSS
                html += '<td><button class="btn btn-sm btn-danger revoke-token-btn" data-token-id="' + token.id + '" data-token-name="' + escapeHtml(token.name) + '"><i class="fa fa-trash"></i> 撤銷</button></td>';
                html += '</tr>';
            });

            html += '</tbody></table></div>';

            if (tokens.length > 1) {
                html += '<button class="btn btn-sm btn-warning mt-2" id="revoke-all-tokens-btn"><i class="fa fa-trash-alt"></i> 撤銷所有 Token</button>';
            }

            listDiv.innerHTML = html;

            // Attach event listeners after rendering (safer than inline onclick)
            attachTokenEventListeners();
        })
        .catch(error => {
            console.error('載入 Token 失敗:', error);
            document.getElementById('tokens-list').innerHTML = '<p class="text-danger">載入失敗</p>';
        });
}

// Attach event listeners to token buttons
function attachTokenEventListeners() {
    // Revoke individual token buttons
    const revokeButtons = document.querySelectorAll('.revoke-token-btn');
    revokeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const tokenId = this.getAttribute('data-token-id');
            const tokenName = this.getAttribute('data-token-name');
            revokeToken(tokenId, tokenName);
        });
    });

    // Revoke all tokens button
    const revokeAllButton = document.getElementById('revoke-all-tokens-btn');
    if (revokeAllButton) {
        revokeAllButton.addEventListener('click', revokeAllTokens);
    }
}

// Revoke a single token
function revokeToken(tokenId, tokenName) {
    // Validate tokenId is numeric
    if (!/^\d+$/.test(String(tokenId))) {
        console.error('無效的 Token ID:', tokenId);
        showMessage('<i class="fa fa-ban"></i> 撤銷失敗：無效的 Token ID', 'danger');
        return;
    }

    if (!confirm(`確定要撤銷 Token "${tokenName}" 嗎？此操作無法撤銷。`)) {
        return;
    }

    axios.delete(`{{ route('api-tokens.destroy', '', false) }}/${tokenId}`)
        .then(response => {
            showMessage('<i class="fa fa-check"></i> Token 已撤銷', 'success');
            loadTokens();
        })
        .catch(error => {
            console.error('撤銷 Token 失敗:', error);
            const errorMsg = error.response?.data?.message || error.message || '網絡錯誤，請稍後重試';
            showMessage(`<i class="fa fa-ban"></i> 撤銷失敗：${errorMsg}`, 'danger');
        });
}

// Revoke all tokens
function revokeAllTokens() {
    if (!confirm('確定要撤銷所有 Token 嗎？此操作無法撤銷。')) {
        return;
    }

    axios.delete('{{ route('api-tokens.destroy-all', [], false) }}')
        .then(response => {
            showMessage('<i class="fa fa-check"></i> 所有 Token 已撤銷', 'success');
            loadTokens();
        })
        .catch(error => {
            console.error('撤銷所有 Token 失敗:', error);
            const errorMsg = error.response?.data?.message || error.message || '網絡錯誤，請稍後重試';
            showMessage(`<i class="fa fa-ban"></i> 批量撤銷失敗：${errorMsg}`, 'danger');
        });
}

// Format date to localized string (使用 formatTimestamp 處理 UTC 時間)
function formatDate(dateString) {
    if (!dateString) return '';
    return formatTimestamp(dateString);
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 頁面載入時初始化
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOMContentLoaded - initializing token management');

    // 載入 Token 列表
    loadTokens();

    // 創建 Token 表單提交
    const createTokenForm = document.getElementById('create-token-form');
    console.log('Form element found:', createTokenForm);

    if (createTokenForm) {
        console.log('Attaching submit event listener to form');
        createTokenForm.addEventListener('submit', function(e) {
            console.log('Form submit event triggered');
            e.preventDefault();
            e.stopPropagation();

            const form = this;
            const formData = new FormData(form);
            const expiresIn = formData.get('expires_in');
            const data = {
                name: formData.get('name')
            };
            // 只有選擇了有效期限才發送 expires_in 字段
            if (expiresIn) {
                data.expires_in = parseInt(expiresIn, 10);
            }

            console.log('Submitting token creation:', data);

            axios.post('{{ route('api-tokens.store', [], false) }}', data)
                .then(response => {
                    console.log('Token creation success:', response);
                    // 重置表單
                    form.reset();

                    // 更新 Token 列表
                    loadTokens();

                    // 顯示成功訊息和新 Token
                    const tokenValue = response.data?.token?.plainTextToken || response.data?.plainTextToken;
                    let message = '<i class="fa fa-check"></i> <strong>Token 創建成功！</strong>';
                    if (tokenValue) {
                        message += '<br><br><strong class="text-danger">⚠️ 請立即複製並保存以下 Token，關閉此訊息後將無法再次查看：</strong><br>';
                        message += '<div class="mt-2 p-2" style="background: #f8f9fa; border: 2px solid #28a745; border-radius: 4px;"><code style="font-size: 14px; word-break: break-all; user-select: all;">' + escapeHtml(tokenValue) + '</code></div>';
                        // Token 消息不自動消失，需要用戶手動關閉
                        showMessage(message, 'success', false);

                        // 滾動到頁面頂部，讓用戶看到 Token
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    } else {
                        // 沒有 token 的普通成功消息可以自動消失
                        showMessage(message, 'success', true);
                    }

                    // 收起表單
                    const card = document.querySelector('.card.card-default.collapsed-card');
                    if (card && window.$ && $.fn.CardWidget) {
                        $(card).CardWidget('collapse');
                    }
                })
                .catch(error => {
                    console.error('創建 Token 失敗:', error);
                    console.error('Error response:', error.response);
                    console.error('Error status:', error.response?.status);
                    console.error('Error data:', error.response?.data);

                    let errorMsg = '網絡錯誤，請檢查輸入並重試';
                    if (error.response?.status === 401) {
                        errorMsg = '未授權：請刷新頁面重新登入';
                    } else if (error.response?.status === 419) {
                        errorMsg = 'CSRF Token 過期，請刷新頁面後重試';
                    } else if (error.response?.data?.message) {
                        errorMsg = error.response.data.message;
                    } else if (error.response?.data?.errors) {
                        errorMsg = Object.values(error.response.data.errors).flat().join(', ');
                    } else if (error.message) {
                        errorMsg = error.message;
                    }

                    showMessage(`<i class="fa fa-ban"></i> 創建失敗：${errorMsg}`, 'danger');
                });
        });
    } else {
        console.error('Form element not found!');
    }
});

// 也嘗試在 Vite ready 後再次綁定（以防萬一）
if (window.onViteReady) {
    window.onViteReady(function() {
        console.log('Vite ready - checking form again');
        const form = document.getElementById('create-token-form');
        if (form && !form.dataset.listenerAttached) {
            console.log('Attaching event listener via Vite ready');
            form.dataset.listenerAttached = 'true';
            form.addEventListener('submit', function(e) {
                console.log('Form submit via Vite ready handler');
                e.preventDefault();
                e.stopPropagation();

                const formData = new FormData(this);
                const expiresIn = formData.get('expires_in');
                const data = {
                    name: formData.get('name')
                };
                // 只有選擇了有效期限才發送 expires_in 字段
                if (expiresIn) {
                    data.expires_in = parseInt(expiresIn, 10);
                }

                console.log('Submitting token creation:', data);

                axios.post('{{ route('api-tokens.store', [], false) }}', data)
                    .then(response => {
                        console.log('Token creation success:', response);
                        this.reset();
                        loadTokens();

                        const tokenValue = response.data?.token?.plainTextToken || response.data?.plainTextToken;
                        let message = '<i class="fa fa-check"></i> <strong>Token 創建成功！</strong>';
                        if (tokenValue) {
                            message += '<br><br><strong class="text-danger">⚠️ 請立即複製並保存以下 Token，關閉此訊息後將無法再次查看：</strong><br>';
                            message += '<div class="mt-2 p-2" style="background: #f8f9fa; border: 2px solid #28a745; border-radius: 4px;"><code style="font-size: 14px; word-break: break-all; user-select: all;">' + escapeHtml(tokenValue) + '</code></div>';
                            // Token 消息不自動消失，需要用戶手動關閉
                            showMessage(message, 'success', false);

                            // 滾動到頁面頂部，讓用戶看到 Token
                            window.scrollTo({ top: 0, behavior: 'smooth' });
                        } else {
                            // 沒有 token 的普通成功消息可以自動消失
                            showMessage(message, 'success', true);
                        }

                        const card = document.querySelector('.card.card-default.collapsed-card');
                        if (card && window.$ && $.fn.CardWidget) {
                            $(card).CardWidget('collapse');
                        }
                    })
                    .catch(error => {
                        console.error('創建 Token 失敗:', error);
                        console.error('Error response:', error.response);
                        console.error('Error status:', error.response?.status);
                        console.error('Error data:', error.response?.data);

                        let errorMsg = '網絡錯誤，請檢查輸入並重試';
                        if (error.response?.status === 401) {
                            errorMsg = '未授權：請刷新頁面重新登入';
                        } else if (error.response?.status === 419) {
                            errorMsg = 'CSRF Token 過期，請刷新頁面後重試';
                        } else if (error.response?.data?.message) {
                            errorMsg = error.response.data.message;
                        } else if (error.response?.data?.errors) {
                            errorMsg = Object.values(error.response.data.errors).flat().join(', ');
                        } else if (error.message) {
                            errorMsg = error.message;
                        }

                        showMessage(`<i class="fa fa-ban"></i> 創建失敗：${errorMsg}`, 'danger');
                    });
            });
        }
    });
}

</script>
@endsection
