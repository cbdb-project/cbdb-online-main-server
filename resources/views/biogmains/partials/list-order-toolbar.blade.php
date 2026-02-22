@php
    $targetTableId = $targetTableId ?? null;
    $toolbarLabel = $toolbarLabel ?? '次序調整';
@endphp

@if(!empty($targetTableId))
    <div class="js-biog-sequence-toolbar mb-2" data-target-table="{{ $targetTableId }}" style="clear: both;">
        <div class="d-flex flex-wrap align-items-center">
            <label class="mb-0 mr-2 text-sm text-muted">{{ $toolbarLabel }}</label>
            <button type="button" class="btn btn-sm btn-outline-primary mr-2 mb-1" data-sequence-toggle>調整次序</button>
            <small class="text-muted mb-1 d-none" data-sequence-hint>顯示「新次序」欄位後，可逐條修改並提交。</small>
        </div>
    </div>
@endif

@once
    @push('scripts')
        <script>
            (function() {
                function setSequenceColumnVisible(table, visible) {
                    if (!table) {
                        return;
                    }

                    var cells = table.querySelectorAll('[data-sequence-demo-col]');
                    Array.prototype.forEach.call(cells, function(el) {
                        el.style.display = visible ? '' : 'none';
                    });
                }

                function initToolbar(toolbar) {
                    if (!toolbar || toolbar.dataset.sequenceToolbarInited === '1') {
                        return;
                    }

                    var tableId = toolbar.dataset.targetTable;
                    var table = tableId ? document.getElementById(tableId) : null;
                    var toggleBtn = toolbar.querySelector('[data-sequence-toggle]');
                    var hint = toolbar.querySelector('[data-sequence-hint]');

                    if (!table || !toggleBtn) {
                        return;
                    }

                    toolbar.dataset.sequenceToolbarInited = '1';
                    setSequenceColumnVisible(table, false);

                    toggleBtn.addEventListener('click', function(event) {
                        event.preventDefault();

                        var opened = toggleBtn.dataset.opened === '1';
                        var nextOpened = !opened;
                        toggleBtn.dataset.opened = nextOpened ? '1' : '0';
                        toggleBtn.textContent = nextOpened ? '收起次序調整' : '調整次序';

                        setSequenceColumnVisible(table, nextOpened);

                        if (hint) {
                            hint.classList.toggle('d-none', !nextOpened);
                        }
                    });
                }

                function initAll() {
                    var toolbars = document.querySelectorAll('.js-biog-sequence-toolbar');
                    Array.prototype.forEach.call(toolbars, initToolbar);
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initAll);
                } else {
                    initAll();
                }

                // 捕獲階段兜底：若頁面其他腳本攔截 click，仍可切換欄位
                document.addEventListener('click', function(event) {
                    var toggleBtn = event.target && event.target.closest ? event.target.closest('[data-sequence-toggle]') : null;
                    if (!toggleBtn) {
                        return;
                    }

                    var toolbar = toggleBtn.closest('.js-biog-sequence-toolbar');
                    if (!toolbar) {
                        return;
                    }

                    var tableId = toolbar.dataset.targetTable;
                    var table = tableId ? document.getElementById(tableId) : null;
                    if (!table) {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();

                    var opened = toggleBtn.dataset.opened === '1';
                    var nextOpened = !opened;
                    toggleBtn.dataset.opened = nextOpened ? '1' : '0';
                    toggleBtn.textContent = nextOpened ? '收起次序調整' : '調整次序';
                    setSequenceColumnVisible(table, nextOpened);

                    var hint = toolbar.querySelector('[data-sequence-hint]');
                    if (hint) {
                        hint.classList.toggle('d-none', !nextOpened);
                    }
                }, true);
            })();
        </script>
    @endpush
@endonce

