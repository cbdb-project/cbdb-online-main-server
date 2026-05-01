@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">批次匯入書稿資料</h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                將作者 CBDB ID、書名、來源 <code>TEXT_ID</code> 貼在下方文字框，每行以 <code>Tab</code> 分隔三欄。
                範例：<code>12345[TAB]某某書名[TAB]67890</code>。系統會依序建立 <code>TEXT_CODES</code>，
                自動排定 <code>c_textid</code>、轉換拼音並標記書籍朝代，預設 <code>c_text_type_id</code> 為 <code>01</code>。
            </p>

            @if(!empty($batchId))
                <div class="alert alert-info">
                    本次批次編號：<code>{{ $batchId }}</code>
                </div>
            @endif

            @if(!empty($batchErrors))
                <div class="alert alert-danger">
                    <p class="text-bold">匯入失敗：</p>
                    <ul class="list-unstyled">
                        @foreach($batchErrors as $message)
                            <li>・{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="post" action="{{ route('admin.batch-load-book-titles.store') }}">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="entries">批次資料（以 Tab 分隔）</label>
                    <div class="entries-editor">
                        <pre id="entries-line-numbers" class="entries-line-numbers" aria-hidden="true">1</pre>
                        <textarea name="entries" id="entries" class="form-control entries-textarea @error('entries') is-invalid @enderror" rows="10" spellcheck="false" placeholder="作者ID[TAB]書名[TAB]來源TEXT_ID">{{ old('entries', $input) }}</textarea>
                    </div>
                    @error('entries')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-primary mr-2">送出匯入</button>
                <button type="submit" name="force" value="1" class="btn btn-warning mr-2"
                        onclick="return confirm('強制送出將略過拼音檢查（人和書的 ID 仍會檢查）。確定繼續？');">
                    強制送出匯入（略過拼音檢查）
                </button>
                <a href="{{ route('admin.batch-load-book-titles') }}" class="btn btn-default">清除重填</a>
            </form>

            @push('styles')
                <style>
                    .entries-editor {
                        display: flex;
                        align-items: stretch;
                        border: 1px solid #ced4da;
                        border-radius: .25rem;
                        background: #fff;
                        overflow: hidden;
                    }
                    .entries-editor.is-invalid { border-color: #dc3545; }
                    .entries-line-numbers {
                        margin: 0;
                        padding: .375rem .5rem;
                        background: #f1f3f5;
                        color: #6c757d;
                        text-align: right;
                        user-select: none;
                        border-right: 1px solid #e9ecef;
                        overflow: hidden;
                        min-width: 3em;
                        white-space: pre;
                        font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
                        font-size: 14px;
                        line-height: 1.5;
                    }
                    .entries-textarea.form-control {
                        border: 0;
                        border-radius: 0;
                        box-shadow: none;
                        resize: vertical;
                        font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
                        font-size: 14px;
                        line-height: 1.5;
                        flex: 1 1 auto;
                        min-width: 0;
                    }
                    .entries-textarea.form-control:focus {
                        box-shadow: none;
                    }
                </style>
            @endpush
            @push('scripts')
                <script>
                    (function () {
                        var ta = document.getElementById('entries');
                        var gutter = document.getElementById('entries-line-numbers');
                        if (!ta || !gutter) return;

                        function render() {
                            var lines = ta.value.split('\n').length;
                            if (lines < 1) lines = 1;
                            var out = '';
                            for (var i = 1; i <= lines; i++) {
                                out += (i === lines ? i : i + '\n');
                            }
                            gutter.textContent = out;
                            gutter.scrollTop = ta.scrollTop;
                        }

                        ta.addEventListener('input', render);
                        ta.addEventListener('scroll', function () { gutter.scrollTop = ta.scrollTop; });
                        render();
                    })();
                </script>
            @endpush

            @push('scripts')
                <script>
                    // Shared toast helper for short transient messages on this page.
                    // type: 'success' | 'error' | 'warning'
                    window.showBatchToast = function (msg, type) {
                        // Anchor to .content-wrapper (the main content area below the navbar)
                        // so the toast clears any card chrome and never overlaps the navbar
                        // user icon. We set position:relative on the anchor on first use so
                        // absolute positioning resolves against it without touching layout CSS.
                        var anchor = document.querySelector('.content-wrapper') || document.querySelector('.card-body') || document.body;
                        if (anchor && getComputedStyle(anchor).position === 'static') {
                            anchor.style.position = 'relative';
                        }
                        var toast = document.getElementById('batch-page-toast');
                        if (!toast) {
                            toast = document.createElement('div');
                            toast.id = 'batch-page-toast';
                            toast.style.cssText = [
                                'position:absolute', 'top:16px', 'right:24px',
                                'z-index:1050', 'padding:10px 18px',
                                'border-radius:4px', 'color:#fff',
                                'font-size:14px',
                                'box-shadow:0 2px 8px rgba(0,0,0,0.2)',
                                'opacity:0', 'transition:opacity 200ms ease',
                                'pointer-events:none', 'max-width:60%'
                            ].join(';');
                            anchor.appendChild(toast);
                        }
                        toast.textContent = msg;
                        var bg = '#28a745';
                        if (type === 'error') bg = '#dc3545';
                        else if (type === 'warning') bg = '#ffc107';
                        toast.style.background = bg;
                        toast.style.color = (type === 'warning') ? '#212529' : '#fff';
                        requestAnimationFrame(function () { toast.style.opacity = '1'; });
                        clearTimeout(toast._t);
                        toast._t = setTimeout(function () { toast.style.opacity = '0'; }, 3000);
                    };
                </script>
                @if(!empty($toast))
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            window.showBatchToast(@json($toast['msg']), @json($toast['type'] ?? 'success'));
                        });
                    </script>
                @endif
            @endpush

            @if(!empty($results))
                @php
                    $copyPayload = collect($results)
                        ->map(fn ($row) => $row['c_textid']."\t".$row['title'])
                        ->implode("\n");
                @endphp
                <div class="d-flex align-items-center" style="margin-top: 20px; gap: 8px;">
                    <button type="button" id="copy-textid-title" class="btn btn-outline-primary">
                        Copy textid and title
                    </button>
                    <textarea id="copy-textid-title-source" hidden>{{ $copyPayload }}</textarea>
                    @if(!empty($batchId))
                        <form method="post" action="{{ route('admin.batch-load-book-titles.undo') }}" class="m-0"
                              onsubmit="return confirm('將刪除此次批次新增的全部 TEXT_CODES 資料與對應 operations 紀錄。確定撤回？');">
                            {{ csrf_field() }}
                            <input type="hidden" name="batch_id" value="{{ $batchId }}">
                            <button type="submit" class="btn btn-outline-danger">撤回此次匯入</button>
                        </form>
                    @endif
                </div>
                <div class="table-responsive" style="margin-top: 20px;">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                        <tr>
                            <th>行號</th>
                            <th>作者 ID</th>
                            <th>書名（已清理）</th>
                            <th>書名拼音</th>
                            <th>來源 TEXT_ID</th>
                            <th>書籍朝代</th>
                            <th>文本類型</th>
                            <th>批次編號</th>
                            <th>建立者</th>
                            <th>建立日期</th>
                            <th>新 c_textid</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($results as $row)
                            <tr>
                                <td>{{ $row['line'] }}</td>
                                <td>{{ $row['author_id'] }}</td>
                                <td>{{ $row['title'] }}</td>
                                <td class="pinyin-cell" data-textid="{{ $row['c_textid'] }}" data-batch-id="{{ $batchId }}">
                                    <span class="pinyin-display">{{ $row['title_pinyin'] }}</span>
                                    <input type="text" class="form-control form-control-sm pinyin-input" value="{{ $row['title_pinyin'] }}" hidden>
                                    <div class="pinyin-actions mt-1">
                                        <button type="button" class="btn btn-xs btn-outline-secondary pinyin-edit-btn" title="編輯拼音" aria-label="編輯拼音"><i class="fa fa-edit" aria-hidden="true"></i></button>
                                        <button type="button" class="btn btn-xs btn-primary pinyin-save-btn" hidden>保存</button>
                                        <button type="button" class="btn btn-xs btn-default pinyin-cancel-btn" hidden>取消</button>
                                        <span class="pinyin-status text-muted small ml-2"></span>
                                    </div>
                                </td>
                                <td>{{ $row['source'] }}</td>
                                <td>{{ $row['dynasty'] ?? '—' }}</td>
                                <td>{{ $row['text_type'] }}</td>
                                <td>{{ $row['notes'] }}</td>
                                <td>{{ $row['created_by'] }}</td>
                                <td>{{ $row['created_date'] }}</td>
                                <td>{{ $row['c_textid'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @push('scripts')
                    <script>
                        // Inline pinyin editing for the result table. We POST one row at a
                        // time to update-pinyin, then SELECT-back the stored value from the
                        // server response so the cell reflects exactly what landed in DB.
                        (function () {
                            var endpoint = @json(route('admin.batch-load-book-titles.update-pinyin', [], false));
                            var tokenInput = document.querySelector('input[name="_token"]');
                            var csrfToken = tokenInput ? tokenInput.value : '';

                            function setBusy(cell, busy) {
                                var status = cell.querySelector('.pinyin-status');
                                if (status) status.textContent = busy ? '保存中…' : '';
                                cell.querySelectorAll('button').forEach(function (b) { b.disabled = busy; });
                            }

                            function enterEdit(cell) {
                                cell.querySelector('.pinyin-display').hidden = true;
                                var input = cell.querySelector('.pinyin-input');
                                input.hidden = false;
                                input.focus();
                                input.select();
                                cell.querySelector('.pinyin-edit-btn').hidden = true;
                                cell.querySelector('.pinyin-save-btn').hidden = false;
                                cell.querySelector('.pinyin-cancel-btn').hidden = false;
                            }

                            function leaveEdit(cell) {
                                cell.querySelector('.pinyin-display').hidden = false;
                                cell.querySelector('.pinyin-input').hidden = true;
                                cell.querySelector('.pinyin-edit-btn').hidden = false;
                                cell.querySelector('.pinyin-save-btn').hidden = true;
                                cell.querySelector('.pinyin-cancel-btn').hidden = true;
                            }

                            function applyStored(cell, storedValue) {
                                var display = cell.querySelector('.pinyin-display');
                                display.textContent = storedValue;
                                cell.querySelector('.pinyin-input').value = storedValue;
                                var status = cell.querySelector('.pinyin-status');
                                if (status) {
                                    status.textContent = '已寫入：' + storedValue;
                                    status.classList.remove('text-danger');
                                    status.classList.add('text-success');
                                    setTimeout(function () {
                                        status.textContent = '';
                                        status.classList.remove('text-success');
                                    }, 4000);
                                }
                            }

                            function showError(cell, msg) {
                                var status = cell.querySelector('.pinyin-status');
                                if (status) {
                                    status.textContent = msg;
                                    status.classList.remove('text-success');
                                    status.classList.add('text-danger');
                                }
                            }

                            document.addEventListener('click', function (ev) {
                                var cell = ev.target.closest('.pinyin-cell');
                                if (!cell) return;

                                if (ev.target.closest('.pinyin-edit-btn')) {
                                    enterEdit(cell);
                                    return;
                                }
                                if (ev.target.closest('.pinyin-cancel-btn')) {
                                    var display = cell.querySelector('.pinyin-display').textContent;
                                    cell.querySelector('.pinyin-input').value = display;
                                    leaveEdit(cell);
                                    var status = cell.querySelector('.pinyin-status');
                                    if (status) status.textContent = '';
                                    return;
                                }
                                if (ev.target.closest('.pinyin-save-btn')) {
                                    var input = cell.querySelector('.pinyin-input');
                                    var newValue = (input.value || '').trim();
                                    if (newValue === '') {
                                        showError(cell, '拼音不可為空');
                                        return;
                                    }
                                    setBusy(cell, true);
                                    var body = new FormData();
                                    body.append('_token', csrfToken);
                                    body.append('c_textid', cell.dataset.textid);
                                    body.append('batch_id', cell.dataset.batchId);
                                    body.append('pinyin', newValue);

                                    fetch(endpoint, {
                                        method: 'POST',
                                        body: body,
                                        credentials: 'same-origin',
                                        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
                                    })
                                    .then(function (resp) {
                                        return resp.json().then(function (json) { return { ok: resp.ok, json: json }; });
                                    })
                                    .then(function (result) {
                                        setBusy(cell, false);
                                        if (!result.ok || !result.json || result.json.ok === false) {
                                            var msg = (result.json && result.json.message) || '保存失敗';
                                            showError(cell, msg);
                                            window.showBatchToast && window.showBatchToast(msg, 'error');
                                            return;
                                        }
                                        applyStored(cell, result.json.c_title || '');
                                        leaveEdit(cell);
                                        window.showBatchToast && window.showBatchToast('已更新 c_textid ' + result.json.c_textid, 'success');
                                    })
                                    .catch(function () {
                                        setBusy(cell, false);
                                        showError(cell, '網路錯誤，請重試');
                                    });
                                }
                            });
                        })();
                    </script>
                @endpush

                @push('scripts')
                    <script>
                        // Event delegation on document survives DOM-replacement by browser
                        // extensions (e.g. inline page translators that clone form controls).
                        // Binding directly to #copy-textid-title would break in those cases
                        // because the listener stays attached to the orphaned original node.
                        document.addEventListener('click', function (ev) {
                            if (!ev.target.closest('#copy-textid-title')) return;
                            var source = document.getElementById('copy-textid-title-source');
                            if (!source) return;
                            var text = source.value || '';
                            if (!text) return;

                            function fallbackCopy(t) {
                                var ta = document.createElement('textarea');
                                ta.value = t;
                                ta.setAttribute('readonly', '');
                                ta.style.position = 'fixed';
                                ta.style.top = '0';
                                ta.style.left = '0';
                                ta.style.opacity = '0';
                                document.body.appendChild(ta);
                                ta.focus();
                                ta.select();
                                var ok = false;
                                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                                document.body.removeChild(ta);
                                return ok;
                            }

                            function done(ok) {
                                if (ok) {
                                    window.showBatchToast('已複製 ' + text.split('\n').length + ' 筆', 'success');
                                } else {
                                    source.removeAttribute('hidden');
                                    source.style.display = 'block';
                                    source.style.width = '100%';
                                    source.style.minHeight = '8em';
                                    source.style.marginTop = '8px';
                                    source.focus();
                                    source.select();
                                    window.showBatchToast('自動複製失敗，已展開下方文字框，請 Ctrl+C 手動複製', 'error');
                                }
                            }

                            if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(text)
                                    .then(function () { done(true); })
                                    .catch(function () { done(fallbackCopy(text)); });
                            } else {
                                done(fallbackCopy(text));
                            }
                        });
                    </script>
                @endpush
            @endif
        </div>
    </div>
@endsection
