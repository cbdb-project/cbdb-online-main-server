@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">按入仕查詢</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <!-- 左側：入仕類型 -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">入仕類型</h5>
                        </div>
                        <div class="card-body p-0">
                            <div id="entry-types-tree" style="height: 400px; overflow-y: auto; border: 1px solid #dee2e6;">
                                <div class="text-center p-3">
                                    <div class="spinner-border spinner-border-sm" role="status">
                                        <span class="sr-only">載入中...</span>
                                    </div>
                                    載入中...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 中間：入仕代碼 -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">入仕代碼</h5>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="btn-select-all">全選</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-deselect-all">取消全選</button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div id="entry-codes-list" style="height: 400px; overflow-y: auto; border: 1px solid #dee2e6;">
                                <div class="text-muted p-3">請先選擇入仕類型</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 右側：搜尋條件 -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">搜尋條件</h5>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="{{ route('search-by.entry.search', [], false) }}" id="search-form">
                                <!-- 已選擇的入仕代碼 -->
                                <div class="form-group">
                                    <label>已選擇的入仕代碼：</label>
                                    <div id="selected-codes-display" class="small text-muted">
                                        尚未選擇
                                    </div>
                                    <div id="entry_codes_inputs"></div>
                                </div>

                                <hr>

                                <!-- 年份範圍 -->
                                <div class="form-group">
                                    <label for="year_from">年份範圍</label>
                                    <div class="row">
                                        <div class="col-6">
                                            <input type="number" class="form-control form-control-sm" id="year_from" name="year_from" placeholder="起始年">
                                        </div>
                                        <div class="col-6">
                                            <input type="number" class="form-control form-control-sm" id="year_to" name="year_to" placeholder="結束年">
                                        </div>
                                    </div>
                                    <small class="form-text text-muted">可留空表示不限制</small>
                                </div>

                                <!-- 地址 ID -->
                                <div class="form-group">
                                    <label for="addr_id">入仕地址 ID</label>
                                    <input type="number" class="form-control form-control-sm" id="addr_id" name="addr_id" placeholder="請輸入地址 ID">
                                    <small class="form-text text-muted">可留空表示不限制</small>
                                </div>

                                <hr>

                                <!-- 搜尋按鈕 -->
                                <div class="form-group mb-0">
                                    <button type="submit" class="btn btn-primary btn-block">
                                        <i class="fas fa-search"></i> 執行搜尋
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
onViteReady(function() {
    $(document).ready(function() {
        let entryTypes = [];
        let entryCodes = [];
        let selectedCodes = new Set();
        let currentTypeId = null;

        // 載入入仕類型
        function loadEntryTypes() {
        $.ajax({
            url: "{{ route('search-by.entry.types', [], false) }}",
            method: 'GET',
            success: function(response) {
                if (response.success) {
                    entryTypes = response.data;
                    renderEntryTypesTree();
                }
            },
            error: function() {
                $('#entry-types-tree').html('<div class="alert alert-danger m-3">載入失敗</div>');
            }
        });
    }

    // 渲染入仕類型樹狀結構
    function renderEntryTypesTree() {
        const $tree = $('#entry-types-tree');
        $tree.empty();

        // 構建樹狀結構
        const typeMap = {};
        const rootTypes = [];

        entryTypes.forEach(type => {
            typeMap[type.c_entry_type] = {
                ...type,
                children: []
            };
        });

        entryTypes.forEach(type => {
            if (type.c_entry_type_parent_id && typeMap[type.c_entry_type_parent_id]) {
                typeMap[type.c_entry_type_parent_id].children.push(typeMap[type.c_entry_type]);
            } else {
                rootTypes.push(typeMap[type.c_entry_type]);
            }
        });

        // 渲染樹
        function renderNode(node, level = 0) {
            const $node = $('<div>')
                .addClass('entry-type-node')
                .css('padding-left', (level * 20 + 10) + 'px')
                .css('padding-top', '5px')
                .css('padding-bottom', '5px')
                .css('cursor', 'pointer')
                .css('border-bottom', '1px solid #f0f0f0')
                .attr('data-type-id', node.c_entry_type)
                .text(node.c_entry_type_desc_chn || node.c_entry_type_desc || node.c_entry_type)
                .on('click', function() {
                    $('.entry-type-node').removeClass('bg-primary text-white');
                    $(this).addClass('bg-primary text-white');
                    loadEntryCodes(node.c_entry_type);
                })
                .on('mouseenter', function() {
                    if (!$(this).hasClass('bg-primary')) {
                        $(this).addClass('bg-light');
                    }
                })
                .on('mouseleave', function() {
                    $(this).removeClass('bg-light');
                });

            $tree.append($node);

            node.children.forEach(child => {
                renderNode(child, level + 1);
            });
        }

        rootTypes.forEach(root => renderNode(root));
    }

    // 載入入仕代碼
    function loadEntryCodes(typeId) {
        currentTypeId = typeId;
        const $list = $('#entry-codes-list');
        $list.html('<div class="text-center p-3"><div class="spinner-border spinner-border-sm"></div> 載入中...</div>');

        $.ajax({
            url: "{{ route('search-by.entry.codes', [], false) }}",
            method: 'GET',
            data: { type_id: typeId },
            success: function(response) {
                if (response.success) {
                    entryCodes = response.data;
                    renderEntryCodes();
                }
            },
            error: function() {
                $list.html('<div class="alert alert-danger m-3">載入失敗</div>');
            }
        });
    }

    // 渲染入仕代碼列表
    function renderEntryCodes() {
        const $list = $('#entry-codes-list');
        $list.empty();

        if (entryCodes.length === 0) {
            $list.html('<div class="text-muted p-3">此類型無入仕代碼</div>');
            return;
        }

        entryCodes.forEach(code => {
            const isChecked = selectedCodes.has(code.c_entry_code);
            const $item = $('<div>')
                .addClass('entry-code-item')
                .css('padding', '8px 10px')
                .css('border-bottom', '1px solid #f0f0f0')
                .css('cursor', 'pointer')
                .on('click', function(e) {
                    // 如果点击的是复选框本身，让浏览器处理
                    if (e.target.type === 'checkbox') return;

                    // 否则切换复选框状态
                    $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
                })
                .on('mouseenter', function() {
                    $(this).css('background-color', '#f8f9fa');
                })
                .on('mouseleave', function() {
                    $(this).css('background-color', '');
                });

            const $checkbox = $('<input>')
                .attr('type', 'checkbox')
                .addClass('mr-2')
                .prop('checked', isChecked)
                .on('change', function(e) {
                    e.stopPropagation();
                    if (this.checked) {
                        selectedCodes.add(code.c_entry_code);
                    } else {
                        selectedCodes.delete(code.c_entry_code);
                    }
                    updateSelectedCodesDisplay();
                });

            const $label = $('<span>')
                .text(code.c_entry_desc_chn || code.c_entry_desc || code.c_entry_code);

            $item.append($checkbox).append($label);
            $list.append($item);
        });
    }

    // 更新已選擇代碼顯示
    function updateSelectedCodesDisplay() {
        const $display = $('#selected-codes-display');
        const $inputs = $('#entry_codes_inputs');

        // 清空之前的隐藏字段
        $inputs.empty();

        if (selectedCodes.size === 0) {
            $display.html('<span class="text-muted">尚未選擇</span>');
        } else {
            const codesArray = Array.from(selectedCodes);
            $display.html('<span class="badge badge-primary mr-1 mb-1">' + codesArray.join('</span> <span class="badge badge-primary mr-1 mb-1">') + '</span>');

            // 为每个选中的代码创建一个隐藏字段
            codesArray.forEach(code => {
                $('<input>')
                    .attr('type', 'hidden')
                    .attr('name', 'entry_codes[]')
                    .val(code)
                    .appendTo($inputs);
            });
        }
    }

    // 全選/取消全選按鈕
    $('#btn-select-all').on('click', function() {
        entryCodes.forEach(code => {
            selectedCodes.add(code.c_entry_code);
        });
        renderEntryCodes();
        updateSelectedCodesDisplay();
    });

    $('#btn-deselect-all').on('click', function() {
        entryCodes.forEach(code => {
            selectedCodes.delete(code.c_entry_code);
        });
        renderEntryCodes();
        updateSelectedCodesDisplay();
    });

    // 表單提交驗證
    $('#search-form').on('submit', function(e) {
        if (selectedCodes.size === 0) {
            e.preventDefault();
            alert('請至少選擇一個入仕代碼');
            return false;
        }
    });

        // 初始化
        loadEntryTypes();
    });
});
</script>
@endpush
