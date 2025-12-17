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
    </div>
</div>

<!-- API Token 管理 -->
<div class="card card-info">
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
// Show inline alert message
function showMessage(message, type = 'success') {
    const alertsContainer = document.getElementById('alerts-container');
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show`;
    alert.innerHTML = `
        <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
        ${message}
    `;
    alertsContainer.appendChild(alert);

    // Auto dismiss after 5 seconds
    setTimeout(() => {
        alert.classList.remove('show');
        setTimeout(() => alert.remove(), 150);
    }, 5000);
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
    axios.get('{{ route('api-tokens.index') }}')
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

    axios.delete(`{{ route('api-tokens.destroy', '') }}/${tokenId}`)
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

    axios.delete('{{ route('api-tokens.destroy-all') }}')
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

// Format date to localized string
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('zh-TW', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// 創建 Token 表單提交
document.getElementById('create-token-form').addEventListener('submit', function(e) {
    e.preventDefault();

    const form = this;
    const formData = new FormData(form);
    const data = {
        name: formData.get('name'),
        expires_in: formData.get('expires_in') || null
    };

    axios.post('{{ route('api-tokens.store') }}', data)
        .then(response => {
            // 重置表單
            form.reset();

            // 更新 Token 列表
            loadTokens();

            // 顯示成功訊息和新 Token
            const tokenValue = response.data?.token?.plainTextToken || response.data?.plainTextToken;
            let message = '<i class="fa fa-check"></i> <strong>Token 創建成功！</strong>';
            if (tokenValue) {
                message += '<br><br>請妥善保存以下 Token，它只會顯示一次：<br>';
                message += '<div class="mt-2"><code style="font-size: 14px; word-break: break-all;">' + escapeHtml(tokenValue) + '</code></div>';
            }
            showMessage(message, 'success');

            // 收起表單
            const card = document.querySelector('.card.card-default.collapsed-card');
            if (card && window.$ && $.fn.CardWidget) {
                $(card).CardWidget('collapse');
            }
        })
        .catch(error => {
            console.error('創建 Token 失敗:', error);
            const errorMsg = error.response?.data?.message || error.message || '網絡錯誤，請檢查輸入並重試';
            showMessage(`<i class="fa fa-ban"></i> 創建失敗：${errorMsg}`, 'danger');
        });
});

// 頁面載入時載入 Token 列表
document.addEventListener('DOMContentLoaded', function() {
    loadTokens();
});
</script>
@endsection
