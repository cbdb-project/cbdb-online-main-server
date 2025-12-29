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

                    <div class="form-group">
                        <label class="col-sm-2 control-label">頭像</label>
                        <div class="col-sm-10">
                            <!-- 當前頭像預覽 -->
                            <div class="current-avatar-preview">
                                <div class="preview-label">當前使用：</div>
                                <div class="preview-container">
                                    <img id="current-avatar-img" src="/images/avatar/{{ old('avatar', $user->avatar) }}" alt="當前頭像">
                                    <div class="preview-name" id="current-avatar-name">{{ old('avatar', $user->avatar) }}</div>
                                </div>
                            </div>

                            <!-- 選擇提示 -->
                            <div class="avatar-selection-hint">
                                <i class="fas fa-info-circle"></i>
                                點擊下方頭像進行選擇，選擇後請點擊頁面底部的「儲存變更」按鈕以保存設定
                            </div>

                            <input type="hidden" name="avatar" id="avatar-input" value="{{ old('avatar', $user->avatar) }}">

                            <!-- 頭像選擇網格 -->
                            <div class="avatar-grid">
                                <!-- CBDB Logo 作為默認頭像 -->
                                <div class="avatar-option {{ old('avatar', $user->avatar) === 'avatar0.png' ? 'selected' : '' }}"
                                     data-avatar="avatar0.png"
                                     title="點擊選擇 CBDB 默認頭像">
                                    <img src="/images/avatar/avatar0.png" alt="CBDB 默認頭像">
                                    <div class="avatar-number">默認</div>
                                </div>

                                @for ($i = 1; $i <= 18; $i++)
                                    <div class="avatar-option {{ old('avatar', $user->avatar) === 'avatar' . $i . '.png' ? 'selected' : '' }}"
                                         data-avatar="avatar{{ $i }}.png"
                                         title="點擊選擇頭像 {{ $i }}">
                                        <img src="/images/avatar/avatar{{ $i }}.png" alt="頭像 {{ $i }}">
                                        <div class="avatar-number">{{ $i }}</div>
                                    </div>
                                @endfor
                            </div>
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
@if(Route::has('api-tokens.index'))
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
@endif

@endsection

@push('styles')
<style>
/* 當前頭像預覽 */
.current-avatar-preview {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 25px;
    padding: 20px 25px;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: 12px;
    border: 1px solid #e3e6ea;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.preview-label {
    font-weight: 600;
    color: #2c3e50;
    font-size: 15px;
    letter-spacing: 0.3px;
}

.preview-container {
    display: flex;
    align-items: center;
    gap: 15px;
}

.preview-container img {
    width: 100px;
    height: 100px;
    border-radius: 12px;
    border: 3px solid #28a745;
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.25);
    object-fit: cover;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* CBDB Logo 特殊样式 - 使用 contain 保持完整显示，支持深色模式 */
.preview-container img[src*="avatar0.png"] {
    object-fit: contain;
    padding: 8px;
    box-sizing: border-box;  /* 确保 padding 不会增加元素总尺寸 */
}

/* 浅色模式：白色背景 */
body:not(.dark-mode) .preview-container img[src*="avatar0.png"] {
    background: #ffffff;
}

/* 深色模式：深灰色背景 */
body.dark-mode .preview-container img[src*="avatar0.png"] {
    background: #343a40;
}

.preview-name {
    font-size: 14px;
    color: #5a6c7d;
    font-weight: 500;
    background: #f1f3f5;
    padding: 6px 12px;
    border-radius: 6px;
}

/* 選擇提示 */
.avatar-selection-hint {
    background: linear-gradient(135deg, #e7f3ff 0%, #f0f7ff 100%);
    border-left: 4px solid #007bff;
    padding: 14px 18px;
    margin-bottom: 25px;
    border-radius: 8px;
    color: #004085;
    font-size: 13px;
    line-height: 1.6;
    box-shadow: 0 2px 6px rgba(0, 123, 255, 0.08);
}

.avatar-selection-hint i {
    margin-right: 10px;
    color: #007bff;
}

/* 頭像選擇網格 - 横向滾動 */
.avatar-grid {
    display: flex;
    gap: 16px;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 12px 4px;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}

/* 美化滾動條 */
.avatar-grid::-webkit-scrollbar {
    height: 8px;
}

.avatar-grid::-webkit-scrollbar-track {
    background: #f1f3f5;
    border-radius: 10px;
    margin: 0 4px;
}

.avatar-grid::-webkit-scrollbar-thumb {
    background: linear-gradient(90deg, #007bff, #0056b3);
    border-radius: 10px;
}

.avatar-grid::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(90deg, #0056b3, #004085);
}

.avatar-option {
    position: relative;
    cursor: pointer;
    width: 100px;
    height: 100px;
    min-width: 100px;
    min-height: 100px;
    border: 3px solid #e9ecef;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    background: white;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.avatar-option img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.3s ease;
}

/* CBDB Logo 在选择网格中的特殊样式，支持深色模式 */
.avatar-option[data-avatar="avatar0.png"] img {
    object-fit: contain;
    padding: 10px;
    box-sizing: border-box;  /* 确保 padding 不会增加元素总尺寸 */
}

/* 浅色模式：白色背景 */
body:not(.dark-mode) .avatar-option[data-avatar="avatar0.png"] {
    background: #ffffff;
}

/* 深色模式：深灰色背景 */
body.dark-mode .avatar-option[data-avatar="avatar0.png"] {
    background: #343a40;
}

.avatar-number {
    position: absolute;
    bottom: 4px;
    right: 4px;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    font-size: 11px;
    padding: 3px 7px;
    border-radius: 4px;
    font-weight: 600;
    backdrop-filter: blur(4px);
}

.avatar-option:hover {
    border-color: #007bff;
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0, 123, 255, 0.2);
}

.avatar-option:hover img {
    transform: scale(1.1);
}

.avatar-option.selected {
    border-color: #28a745;
    border-width: 4px;
    box-shadow: 0 6px 20px rgba(40, 167, 69, 0.3);
    transform: translateY(-2px);
}

.avatar-option.selected::before {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(40, 167, 69, 0.1);
    pointer-events: none;
}

.avatar-option.selected::after {
    content: '\2713';
    position: absolute;
    top: 6px;
    right: 6px;
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: bold;
    box-shadow: 0 3px 8px rgba(40, 167, 69, 0.4);
    animation: checkmark 0.3s ease;
}

@keyframes checkmark {
    0% {
        transform: scale(0) rotate(-45deg);
        opacity: 0;
    }
    50% {
        transform: scale(1.2) rotate(0deg);
    }
    100% {
        transform: scale(1) rotate(0deg);
        opacity: 1;
    }
}

/* 響應式調整 */
@media (max-width: 768px) {
    .current-avatar-preview {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
        padding: 15px;
    }

    .preview-container img {
        width: 80px;
        height: 80px;
    }

    .avatar-option {
        width: 90px;
        height: 90px;
        min-width: 90px;
        min-height: 90px;
    }
}

@media (max-width: 480px) {
    .avatar-option {
        width: 80px;
        height: 80px;
        min-width: 80px;
        min-height: 80px;
    }
}
</style>
@endpush

@push('scripts')
<script>
// 頭像選擇功能 - 等待 Vite 加載完成後初始化
onViteReady(function() {
    console.log('Initializing avatar selection...');

    const avatarOptions = document.querySelectorAll('.avatar-option');
    const avatarInput = document.getElementById('avatar-input');
    const currentAvatarImg = document.getElementById('current-avatar-img');
    const currentAvatarName = document.getElementById('current-avatar-name');

    console.log('Avatar options found:', avatarOptions.length);
    console.log('Avatar input:', avatarInput);
    console.log('Current avatar img:', currentAvatarImg);

    avatarOptions.forEach(option => {
        option.addEventListener('click', function() {
            console.log('Avatar clicked:', this.getAttribute('data-avatar'));

            // 移除所有選中狀態
            avatarOptions.forEach(opt => opt.classList.remove('selected'));

            // 添加選中狀態
            this.classList.add('selected');

            // 獲取選中的頭像名稱
            const avatarName = this.getAttribute('data-avatar');

            // 更新隱藏輸入框的值
            avatarInput.value = avatarName;

            // 更新預覽圖片
            currentAvatarImg.src = '/images/avatar/' + avatarName;

            // 更新預覽文件名
            currentAvatarName.textContent = avatarName;

            // 添加動畫效果
            currentAvatarImg.style.transform = 'scale(1.1)';
            setTimeout(() => {
                currentAvatarImg.style.transform = 'scale(1)';
            }, 200);
        });
    });

    console.log('Avatar selection initialized successfully!');
});
</script>

@if(Route::has('api-tokens.index'))
<script>
// 時區相關函數（動態取得，避免 Vite 模組尚未載入時抓不到）
function getFormatTimestampFn() {
    return typeof window.formatTimestamp === 'function'
        ? window.formatTimestamp
        : function(value) { return value; };
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
    return getFormatTimestampFn()(dateString);
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
@endif
@endpush
