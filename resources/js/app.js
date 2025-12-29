/**
 * Modern World Entry Point - Main Application
 *
 * This is for AdminLTE v3 + Vue 3 pages (Modern World)
 * Legacy World (AdminLTE 2) remains in resources/assets/js/
 */

// Import AdminLTE v3 CSS from NPM
import 'admin-lte/dist/css/adminlte.min.css';

// Import jQuery and expose globally before any plugins run
import $ from './jquery-global';

// Import Bootstrap 4 bundle (from AdminLTE vendor, includes Popper)
import 'admin-lte/plugins/bootstrap/js/bootstrap.bundle';

// Import AdminLTE v3 JS
import 'admin-lte';

// Select2 (jQuery plugin) for enhanced selects on dashboard-v3 pages
import 'select2/dist/css/select2.min.css';
import '@ttskch/select2-bootstrap4-theme/dist/select2-bootstrap4.min.css';
import select2 from 'select2';
select2(window, $);

// Import custom CSS overrides (must come after Select2 CSS to properly override)
import '../css/select2-overrides.css';
import '../css/mobile-responsive.css';

import { formatTimestamp, getUserOffsetMinutes, getUserTimeZone } from './utils/datetime';

// Set global defaults for all Select2 instances to use Bootstrap 4 theme
$.fn.select2.defaults.set('theme', 'bootstrap4');

// Person select2 helper (shared across dashboard-v3 pages)
const formatPersonLabel = (item) => {
    const parts = [];
    if (item.c_personid) {
        parts.push(item.c_personid);
    }
    if (item.c_name_chn) {
        parts.push(item.c_name_chn);
    }
    if (item.c_name) {
        parts.push(item.c_name);
    }
    const dynasty = item.c_dynasty_chn ? `（${item.c_dynasty_chn}）` : '';
    const zi = item.c_alt_name_chn_zi ? `，字：${item.c_alt_name_chn_zi}` : '';
    const hao = item.c_alt_name_chn_hao ? `，號：${item.c_alt_name_chn_hao}` : '';
    const addr = item.ADDR_c_name_chn ? `，籍：${item.ADDR_c_name_chn}` : '';
    return `${parts.join(' - ')}${dynasty}${zi}${hao}${addr}`.trim();
};

const fetchPersonOption = (id) => {
    if (!id) {
        return Promise.resolve(null);
    }
    return $.get('/api/name', { q: id, num: 1 }).then((data) => {
        const item = Array.isArray(data?.data) && data.data.length > 0 ? data.data[0] : null;
        if (!item) {
            return null;
        }
        return {
            id: item.c_personid,
            text: formatPersonLabel(item) || item.c_personid,
        };
    }).catch(() => null);
};

/**
 * 人物选择 Select2 初始化助手函数
 * 专门用于人物搜索，显示完整的人物信息（ID、姓名、朝代、字、号、籍贯）
 * 内部调用 window.initAjaxSelect 实现统一架构
 *
 * @param {jQuery} $el - jQuery 选择器对象
 * @param {object} options - 额外配置选项
 *
 * @example
 * // 基础用法
 * window.initPersonSelect($('.js-person-select'));
 *
 * @example
 * // 带初始值（通过 data-initial-id 属性）
 * // <select class="person-select" data-initial-id="12345"></select>
 * window.initPersonSelect($('.person-select'), {
 *     placeholder: '輸入姓名或 ID 搜尋人物'
 * });
 */
window.initPersonSelect = ($el, options = {}) => {
    const initialId = $el.data('initial-id');

    // 使用 initAjaxSelect 统一框架，传递人物特定配置
    window.initAjaxSelect($el, 'person', {
        ajax: {
            url: '/api/name',
            dataType: 'json',
            delay: 250,
            data: (params) => ({
                q: params.term,
                num: 20,
            }),
            processResults: (data) => {
                const rows = Array.isArray(data?.data) ? data.data : [];
                return {
                    results: rows.map((item) => ({
                        id: item.c_personid,
                        text: formatPersonLabel(item) || item.c_personid,
                    })),
                };
            },
        },
        ...options,
    });

    // 异步加载初始值（人物数据需要格式化）
    if (initialId) {
        fetchPersonOption(initialId).then((opt) => {
            if (opt) {
                const option = new Option(opt.text, opt.id, true, true);
                $el.append(option).trigger('change.select2');
            } else {
                $el.val(initialId).trigger('change.select2');
            }
        });
    }
};

window.formatPersonLabel = formatPersonLabel;
window.fetchPersonOption = fetchPersonOption;
window.formatTimestamp = formatTimestamp;
const detectedTimeZone = getUserTimeZone();
const detectedOffset = getUserOffsetMinutes();
window.getUserTimeZone = () => detectedTimeZone;
window.getUserOffsetMinutes = () => detectedOffset;

/**
 * 通用 AJAX 搜索 Select2 初始化助手函数
 * 用于统一管理所有使用 AJAX 搜索的 Select2 下拉框
 *
 * @param {jQuery} $el - jQuery 选择器对象
 * @param {string} model - API 模型名称 (office/text/addr/socialinstcode 等)
 * @param {object} options - 额外配置选项，会深度合并到默认配置中
 *
 * @example
 * // 基础用法
 * window.initAjaxSelect($(".c_office_id"), 'office');
 *
 * @example
 * // 带初始值（通过 data 属性）
 * // <select class="c_source" data-initial-id="123" data-initial-text="書名"></select>
 * window.initAjaxSelect($(".c_source"), 'text');
 *
 * @example
 * // 自定义配置
 * window.initAjaxSelect($(".c_addr"), 'addr', {
 *     placeholder: '請選擇地址',
 *     minimumInputLength: 2
 * });
 */
window.initAjaxSelect = function($el, model, options = {}) {
    const defaults = {
        ajax: {
            url: `/api/select/search/${model}`,
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    q: params.term,
                    page: params.page || 1,
                };
            },
            processResults: function (data, params) {
                params.page = params.page || 1;
                return {
                    results: data.data || [],
                    pagination: {
                        more: (params.page * 30) < (data.total || 0)
                    }
                };
            },
            cache: true
        },
        placeholder: '请搜索',
        minimumInputLength: 1,
        width: '100%',
        theme: 'bootstrap4',
        escapeMarkup: function (markup) { return markup; },
        templateResult: function(item) {
            if (item.loading) {
                return item.text;
            }
            return `<div class="select2-result-repository clearfix">
                <div class="select2-result-repository__meta">
                    <div class="select2-result-repository__title">${item.text}</div>
                </div>
            </div>`;
        },
        templateSelection: function(item) {
            return item.text || item.text;
        }
    };

    // 深度合并用户配置
    const config = $.extend(true, {}, defaults, options);

    $el.select2(config);

    // 处理初始值（如果有 data-initial-* 属性）
    const initialId = $el.data('initial-id') || $el.data('initial-value');
    const initialText = $el.data('initial-text');

    if (initialId && initialText) {
        const option = new Option(initialText, initialId, true, true);
        $el.append(option).trigger('change.select2');
    }
};

// Import Axios for HTTP requests
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.headers.common['Accept'] = 'application/json';
window.axios.defaults.headers.post['Content-Type'] = 'application/json';

// Import cn-era for Chinese era conversion
import { convertYear, Dynasty } from 'cn-era';

// Global modal focus management to avoid aria-hidden warnings when closing
const installModalFocusFix = () => {
    if (window.modalFocusFixInstalled) {
        return;
    }
    window.modalFocusFixInstalled = true;

    $(document).on('show.bs.modal', '.modal', function(event) {
        $(this).data('trigger-element', event ? event.relatedTarget || document.activeElement : document.activeElement);
    });

    $(document).on('hide.bs.modal', '.modal', function() {
        // Prevent Bootstrap from forcing focus back into a hiding modal
        $(document).off('focusin.bs.modal');

        const active = document.activeElement;
        if (active && this.contains(active)) {
            active.blur();
        }

        const hadBodyTabIndex = document.body.hasAttribute('tabindex');
        $(this).data('had-body-tabindex', hadBodyTabIndex);
        if (!hadBodyTabIndex) {
            document.body.setAttribute('tabindex', '-1');
        }
        document.body.focus({ preventScroll: true });
    });

    $(document).on('hidden.bs.modal', '.modal', function() {
        const trigger = $(this).data('trigger-element');
        if (trigger && typeof trigger.focus === 'function') {
            trigger.focus({ preventScroll: true });
        } else {
            document.body.focus({ preventScroll: true });
        }

        const hadBodyTabIndex = $(this).data('had-body-tabindex');
        if (!hadBodyTabIndex) {
            document.body.removeAttribute('tabindex');
        }
    });
};

const installMobileSidebarDismiss = () => {
    if (!$.fn?.PushMenu) {
        return;
    }

    const shouldClose = (event) => {
        if (window.innerWidth > 991) {
            return;
        }
        if (!document.body.classList.contains('sidebar-open')) {
            return;
        }
        if (event.target.closest('.main-sidebar')) {
            return;
        }
        if (event.target.closest('[data-widget="pushmenu"]')) {
            return;
        }

        $('[data-widget="pushmenu"]').PushMenu('collapse');
    };

    document.addEventListener('click', shouldClose, true);
    document.addEventListener('touchstart', shouldClose, { capture: true, passive: true });
};

// CSRF token setup
const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
} else {
    console.error('CSRF token not found');
}

// Vue 3 setup (for pages that need Vue components)
import { createApp } from 'vue';

// Import Vue components
import SelectVue from './components/Select.vue';

// Make createApp globally available for pages that need it
window.createVueApp = createApp;

// Lunar month/day validation helper for forms
window.initLunarValidation = function(scope = document) {
    const $scope = $(scope);
    const validateLunarInput = ($input, max) => {
        const value = $input.val().trim();
        if (value === '') {
            $input.removeClass('is-invalid');
            return;
        }
        const parsed = Number(value);
        const isInteger = Number.isInteger(parsed);
        const isValid = isInteger && parsed >= 1 && parsed <= max;
        $input.toggleClass('is-invalid', !isValid);
    };

    const bindInputs = (selector, max) => {
        const $inputs = $scope.find(selector);
        $inputs.each(function() {
            const $input = $(this);
            validateLunarInput($input, max);
        });
        $inputs.off('.lunarValidation').on('input.lunarValidation change.lunarValidation', function() {
            validateLunarInput($(this), max);
        });
    };

    bindInputs('.lunar-month', 12);
    bindInputs('.lunar-day', 30);
};

// Install global modal focus guard when DOM is ready
$(installModalFocusFix);
$(installMobileSidebarDismiss);

// Auto-mount Vue app if #app element exists, then signal readiness
$(function() {
    const appElement = document.getElementById('app');
    if (appElement) {
        const app = createApp({
            components: {
                'select-vue': SelectVue,
            }
        });
        app.mount('#app');

        // Store app instance globally for debugging
        window.vueApp = app;
    }

    // Initialize era conversion buttons
    initEraConversion();

    // Only mark Vite ready after DOM is ready and Vue (if any) has mounted.
    // This ensures onViteReady callbacks run after custom elements (e.g. <select-vue>) exist.
    window.viteReady = true;
    if (window.viteReadyCallbacks) {
        window.viteReadyCallbacks.forEach(fn => fn());
        window.viteReadyCallbacks = [];
    }
});

/**
 * 初始化年號轉換功能
 */
function initEraConversion() {
    // 啟用 Bootstrap tooltip
    $('[data-toggle="tooltip"]').tooltip();

    // 監聽轉換按鈕點擊事件
    $(document).on('click', '.era-convert-btn', async function(e) {
        e.preventDefault();
        const $btn = $(this);

        // 找到對應的公元年份輸入框
        const $container = $btn.closest('.d-flex').parent();
        const $yearInput = $container.find('.era-year-input').first();

        if (!$yearInput.length) {
            console.error('找不到公元年份輸入框');
            return;
        }

        const year = parseInt($yearInput.val(), 10);
        if (isNaN(year) || year === 0) {
            alert('請先輸入有效的公元年份');
            return;
        }

        // 獲取目標字段名稱
        const nhCodeName = $yearInput.data('nh-code-name');
        const nhYearName = $yearInput.data('nh-year-name');

        if (!nhCodeName || !nhYearName) {
            console.error('找不到年號字段配置');
            return;
        }

        try {
            // 獲取朝代信息
            const $dynastySelect = $('select[name="c_dy"]');
            const hasDynastyField = $dynastySelect.length > 0;
            const dynastyCode = hasDynastyField ? parseInt($dynastySelect.val(), 10) : null;

            // 先獲取所有可能的年號結果
            let allResults = convertYear(year, { mode: 'all' });

            if (!allResults || allResults.length === 0) {
                alert(`無法找到公元 ${year} 年對應的年號`);
                return;
            }

            let results = allResults;
            let shouldWarnDynastyMismatch = false;

            // 如果有朝代信息，檢查朝代匹配
            if (hasDynastyField && dynastyCode && !isNaN(dynastyCode) && dynastyCode > 0) {
                // 嘗試用朝代過濾
                const dynastyFilteredResults = allResults.filter(r => r.dynasty === dynastyCode);

                if (allResults.length > 1) {
                    // 多個結果：使用朝代作為 tie-breaker
                    if (dynastyFilteredResults.length === 0) {
                        // 朝代過濾後沒有結果：警告用戶所選朝代沒有對應年號
                        const dynastyName = $dynastySelect.find('option:selected').text().trim();
                        alert(`所選朝代「${dynastyName}」在公元 ${year} 年沒有對應的年號。\n\n請檢查朝代選擇是否正確，或查看所有可能的年號選項。`);
                        // 仍顯示所有結果供用戶參考，但標記為不匹配
                        shouldWarnDynastyMismatch = true;
                    } else if (dynastyFilteredResults.length === 1) {
                        // 朝代過濾後只有一個結果：使用它
                        results = dynastyFilteredResults;
                    } else {
                        // 朝代過濾後仍有多個結果：只顯示該朝代的選項
                        results = dynastyFilteredResults;
                    }
                } else if (allResults.length === 1) {
                    // 單一結果：檢查朝代是否匹配
                    if (dynastyFilteredResults.length === 0) {
                        // 朝代不匹配：顯示確認對話框
                        const dynastyName = $dynastySelect.find('option:selected').text().trim();
                        const eraResult = allResults[0];
                        const confirmed = confirm(
                            `所選朝代「${dynastyName}」與查詢結果不符。\n\n` +
                            `查詢結果：${eraResult.dynasty_name} ${eraResult.reign_title} ${eraResult.year_num}\n\n` +
                            `是否使用此結果？`
                        );
                        if (!confirmed) {
                            return; // 用戶取消，不進行轉換
                        }
                        // 用戶確認，繼續使用此結果
                    }
                    // 朝代匹配或用戶已確認，使用這個結果
                }
            }

            // 如果只有一個結果，直接使用
            if (results.length === 1) {
                const eraResult = results[0];
                await fillEraFields(eraResult, year, $container, nhCodeName, nhYearName, $btn);
            } else {
                // 有多個結果，顯示選擇對話框
                showEraSelectionDialog(
                    results,
                    year,
                    shouldWarnDynastyMismatch,
                    async (selectedResult) => {
                        await fillEraFields(selectedResult, year, $container, nhCodeName, nhYearName, $btn);
                    }
                );
            }

        } catch (error) {
            console.error('年號轉換錯誤:', error);
            alert(`轉換失敗：${error.message}`);
        }
    });

    // 監聽反向轉換按鈕點擊事件（年號 → 公元年份）
    $(document).on('click', '.era-reverse-convert-btn', function(e) {
        e.preventDefault();
        const $btn = $(this);

        // 找到對應的容器
        const $container = $btn.closest('.d-flex').parent();
        const $yearInput = $container.find('.era-year-input').first();

        // 獲取目標字段名稱
        const nhCodeName = $yearInput.data('nh-code-name');
        const nhYearName = $yearInput.data('nh-year-name');

        if (!nhCodeName || !nhYearName) {
            console.error('找不到年號字段配置');
            return;
        }

        // 獲取年號和年數
        const $nhSelect = $container.find(`select[name="${nhCodeName}"]`);
        const nianhaoId = $nhSelect.length ? $nhSelect.val() : null;
        const $nhYearInput = $container.find(`input[name="${nhYearName}"]`);
        const nhYear = $nhYearInput.length ? parseInt($nhYearInput.val(), 10) : null;

        if (!nianhaoId) {
            alert('請先選擇年號');
            return;
        }

        if (!nhYear || isNaN(nhYear) || nhYear <= 0) {
            alert('請輸入有效的年號年數');
            return;
        }

        try {
            // 直接使用年號 ID 進行轉換（使用 code 而非字串）
            convertNianhaoIdToYear(nianhaoId, nhYear).then(result => {
                if (result.success) {
                    // 填充公元年份
                    $yearInput.val(result.year);

                    // 顯示成功提示
                    showConversionSuccess($btn, `公元 ${result.year} 年`, '將年號轉換為公元年份');
                } else {
                    alert(result.message || '轉換失敗');
                }
            }).catch(error => {
                console.error('年號反向轉換錯誤:', error);
                alert(`轉換失敗：${error.message}`);
            });

        } catch (error) {
            console.error('年號反向轉換錯誤:', error);
            alert(`轉換失敗：${error.message}`);
        }
    });
}

/**
 * 填充年號欄位的輔助函數
 * 使用公元年份和年份範圍精確匹配年號 ID，避免字串匹配的歧義
 * @param {Object} eraResult - cn-era 返回的年號對象
 * @param {number} gregorianYear - 公元年份（用於精確匹配年份範圍）
 */
async function fillEraFields(eraResult, gregorianYear, $container, nhCodeName, nhYearName, $btn) {
    const reignTitle = eraResult.reign_title;
    const yearNum = eraResult.year;

    try {
        // 使用年號名稱和公元年份精確查找 ID
        let nianhaoId = await findNianhaoIdByNameAndYear(reignTitle, gregorianYear, yearNum);

        // 如果精確匹配失敗，嘗試模糊匹配作為降級方案
        if (!nianhaoId) {
            console.warn(`精確匹配失敗，嘗試模糊匹配: ${reignTitle}`);
            const fallbackResult = await findNianhaoIdByNameFallback(reignTitle, gregorianYear, yearNum);

            if (fallbackResult.found) {
                // 找到候選，詢問用戶是否使用
                const confirmed = confirm(
                    `年號「${reignTitle}」的資料庫記錄與 cn-era 數據存在差異。\n\n` +
                    `cn-era: ${eraResult.dynasty_name} ${reignTitle} ${eraResult.year_num} (公元 ${gregorianYear})\n` +
                    `資料庫: ${fallbackResult.dbInfo}\n\n` +
                    `是否使用資料庫中的記錄？\n` +
                    `（選擇「取消」將放棄轉換）`
                );

                if (confirmed) {
                    nianhaoId = fallbackResult.id;
                } else {
                    return; // 用戶取消
                }
            } else {
                // 完全找不到，提供手動選擇選項
                alert(
                    `找到年號「${reignTitle}」，但在資料庫中未找到對應記錄。\n\n` +
                    `轉換結果：${eraResult.dynasty_name} ${reignTitle} ${eraResult.year_num}\n\n` +
                    `請手動從年號下拉框中選擇，或聯繫系統管理員更新數據。`
                );
                return;
            }
        }

        // 填充年號選擇框
        const $nhSelect = $container.find(`select[name="${nhCodeName}"]`);
        if ($nhSelect.length) {
            $nhSelect.val(nianhaoId).trigger('change');
        }

        // 填充年號年數輸入框
        const $nhYearInput = $container.find(`input[name="${nhYearName}"]`);
        if ($nhYearInput.length) {
            $nhYearInput.val(yearNum);
        }

        // 顯示成功提示
        showConversionSuccess($btn, `${eraResult.dynasty_name} ${reignTitle} ${eraResult.year_num}`);

    } catch (error) {
        console.error('查找年號 ID 時發生錯誤:', error);
        alert('轉換失敗：無法查找年號資料');
    }
}

/**
 * 顯示年號選擇對話框
 * @param {Array} results - cn-era 返回的年號選項陣列
 * @param {number} gregorianYear - 公元年份
 * @param {boolean} isDynastyMismatch - 是否為朝代不匹配的情況
 * @param {Function} onSelect - 選擇回調函數
 */
function showEraSelectionDialog(results, gregorianYear, isDynastyMismatch, onSelect) {
    // 創建對話框 HTML
    let optionsHtml = results.map((result, index) => {
        return `
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="eraOption" id="eraOption${index}" value="${index}" ${index === 0 ? 'checked' : ''}>
                <label class="form-check-label" for="eraOption${index}">
                    <strong>${result.dynasty_name}</strong> ${result.reign_title} ${result.year_num}
                </label>
            </div>
        `;
    }).join('');

    // 根據是否朝代不匹配顯示不同的提示
    const promptText = isDynastyMismatch
        ? '<p class="text-warning"><i class="fas fa-exclamation-triangle"></i> 以下年號與所選朝代不符，請檢查朝代選擇或從中選擇：</p>'
        : '<p>找到多個符合的年號，請選擇：</p>';

    const dialogHtml = `
        <div class="modal fade" id="eraSelectionModal" tabindex="-1" role="dialog" aria-labelledby="eraSelectionModalLabel" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="eraSelectionModalLabel">選擇年號</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        ${promptText}
                        ${optionsHtml}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-primary" id="eraSelectionConfirm">確定</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    // 移除已存在的對話框
    $('#eraSelectionModal').remove();

    // 添加對話框到頁面
    $('body').append(dialogHtml);

    // 顯示對話框
    const $modal = $('#eraSelectionModal');
    $modal.modal('show');

    // 綁定確定按鈕事件
    $('#eraSelectionConfirm').on('click', function() {
        const selectedIndex = parseInt($('input[name="eraOption"]:checked').val(), 10);
        const selectedResult = results[selectedIndex];

        // 關閉對話框
        $modal.modal('hide');

        // 調用回調函數
        if (onSelect && selectedResult) {
            onSelect(selectedResult);
        }
    });

    // 對話框關閉後移除
    $modal.on('hidden.bs.modal', function() {
        $(this).remove();
    });
}

/**
 * 根據年號名稱和公元年份精確查找年號 ID
 * 使用年份範圍匹配，避免同名年號的歧義
 * @param {string} reignTitle - 年號名稱
 * @param {number} gregorianYear - 公元年份
 * @param {number} yearNum - 年號年數（用於驗證）
 */
async function findNianhaoIdByNameAndYear(reignTitle, gregorianYear, yearNum) {
    try {
        // 特殊 ID 映射：直接返回 CBDB 年號記錄 ID
        const specialIdMapping = {
            '至元 (世祖)': 623,
            '至元 (順帝)': 635,
        };

        // 檢查是否有直接 ID 映射
        if (specialIdMapping[reignTitle]) {
            return specialIdMapping[reignTitle];
        }

        // 特殊名稱映射：名稱轉換
        const specialNameMapping = {
            '民國': '中華民國',
        };

        const searchTitle = specialNameMapping[reignTitle] || reignTitle;

        // 獲取年號數據（使用緩存）
        const nianhaoData = await getNianhaoData();

        // 查找所有名稱匹配的年號
        const candidates = nianhaoData.filter(item => {
            return item.c_nianhao_chn === searchTitle;
        });

        if (candidates.length === 0) {
            return null;
        }

        // 無論單一或多個候選，都需驗證年份範圍
        for (const item of candidates) {
            // 驗證 c_str 字段存在且格式正確
            if (!item.c_str) {
                console.warn(`年號記錄 ${item.c_nianhao_chn} (ID: ${item.c_nianhao_id}) 缺少 c_str 字段，跳過`);
                continue;
            }

            // 從 c_str 解析年份範圍 "[1234]~[5678]"
            const rangeMatch = item.c_str.match(/\[(-?\d+)\]~\[(-?\d+)\]/);
            if (!rangeMatch) {
                console.warn(`年號記錄 ${item.c_nianhao_chn} (ID: ${item.c_nianhao_id}) 的 c_str 格式錯誤: ${item.c_str}，跳過`);
                continue;
            }

            const firstYear = parseInt(rangeMatch[1], 10);
            const lastYear = parseInt(rangeMatch[2], 10);

            // 檢查公元年份是否在範圍內
            if (gregorianYear >= firstYear && gregorianYear <= lastYear) {
                // 額外驗證：計算的年數是否匹配
                const calculatedYearNum = gregorianYear - firstYear + 1;
                if (calculatedYearNum === yearNum) {
                    return item.c_nianhao_id;
                }
            }
        }

        // 如果精確匹配失敗，返回 null 而非自動選擇第一個
        // 讓 fillEraFields 顯示錯誤訊息，或由上層邏輯顯示選擇對話框
        console.error(`年號「${searchTitle}」有 ${candidates.length} 個記錄，但無法通過年份範圍精確匹配 (年份: ${gregorianYear}, 年數: ${yearNum})`);
        console.error('候選記錄:', candidates.map(c => `ID=${c.c_nianhao_id}, 範圍=${c.c_str}`));
        return null;
    } catch (error) {
        console.error('查找年號資料時發生錯誤:', error);
        throw error;
    }
}

/**
 * 模糊匹配年號 ID（降級方案）
 * 當精確匹配失敗時，使用名稱匹配並返回最接近的候選
 * @param {string} reignTitle - 年號名稱
 * @param {number} gregorianYear - 公元年份（用於顯示資訊）
 * @param {number} yearNum - 年號年數（用於顯示資訊）
 * @returns {Promise<{found: boolean, id: number|null, dbInfo: string}>}
 */
async function findNianhaoIdByNameFallback(reignTitle, gregorianYear, yearNum) {
    try {
        // 特殊 ID 映射
        const specialIdMapping = {
            '至元 (世祖)': 623,
            '至元 (順帝)': 635,
        };

        if (specialIdMapping[reignTitle]) {
            return {
                found: true,
                id: specialIdMapping[reignTitle],
                dbInfo: `${reignTitle} (特殊映射)`
            };
        }

        // 特殊名稱映射
        const specialNameMapping = {
            '民國': '中華民國',
        };

        const searchTitle = specialNameMapping[reignTitle] || reignTitle;

        // 獲取年號數據（使用緩存）
        const nianhaoData = await getNianhaoData();

        // 查找所有名稱匹配的年號
        const candidates = nianhaoData.filter(item => {
            return item.c_nianhao_chn === searchTitle;
        });

        if (candidates.length === 0) {
            return { found: false, id: null, dbInfo: '' };
        }

        // 如果只有一個候選，直接返回
        if (candidates.length === 1) {
            const item = candidates[0];
            return {
                found: true,
                id: item.c_nianhao_id,
                dbInfo: `${item.c_nianhao_chn} ${item.c_str}`
            };
        }

        // 多個候選：找最接近的（年份範圍包含或最近的）
        let bestMatch = null;
        let minDistance = Infinity;

        for (const item of candidates) {
            // 驗證並解析範圍
            if (!item.c_str) continue;
            const rangeMatch = item.c_str.match(/\[(-?\d+)\]~\[(-?\d+)\]/);
            if (!rangeMatch) continue;

            const firstYear = parseInt(rangeMatch[1], 10);
            const lastYear = parseInt(rangeMatch[2], 10);

            // 計算距離
            let distance;
            if (gregorianYear >= firstYear && gregorianYear <= lastYear) {
                // 在範圍內，距離為 0（最優）
                distance = 0;
            } else if (gregorianYear < firstYear) {
                distance = firstYear - gregorianYear;
            } else {
                distance = gregorianYear - lastYear;
            }

            if (distance < minDistance) {
                minDistance = distance;
                bestMatch = item;
            }
        }

        if (bestMatch) {
            return {
                found: true,
                id: bestMatch.c_nianhao_id,
                dbInfo: `${bestMatch.c_nianhao_chn} ${bestMatch.c_str} (距離: ${minDistance === 0 ? '範圍內' : minDistance + '年'})`
            };
        }

        // 找不到合適的候選
        return { found: false, id: null, dbInfo: '' };

    } catch (error) {
        console.error('模糊匹配年號時發生錯誤:', error);
        return { found: false, id: null, dbInfo: '' };
    }
}

/**
 * 根據年號名稱查找對應的 NIANHAO_CODES ID
 * @deprecated 建議使用 findNianhaoIdByNameAndYear 以避免同名年號的歧義
 */
async function findNianhaoIdByName(reignTitle) {
    try {
        // 特殊 ID 映射：直接返回 CBDB 年號記錄 ID
        const specialIdMapping = {
            '至元 (世祖)': 623,
            '至元 (順帝)': 635,
        };

        // 檢查是否有直接 ID 映射
        if (specialIdMapping[reignTitle]) {
            return specialIdMapping[reignTitle];
        }

        // 特殊名稱映射：名稱轉換
        const specialNameMapping = {
            '民國': '中華民國',
        };

        const searchTitle = specialNameMapping[reignTitle] || reignTitle;

        // 獲取年號數據（使用緩存）
        const nianhaoData = await getNianhaoData();

        // 查找匹配的年號
        for (const item of nianhaoData) {
            const values = Object.values(item);
            if (values.includes(searchTitle)) {
                return Object.values(item)[0];
            }
        }

        return null;
    } catch (error) {
        console.error('查找年號資料時發生錯誤:', error);
        throw error;
    }
}

// 全局年號數據緩存
let nianhaoDataCache = null;

/**
 * 獲取年號數據（帶緩存）
 * 避免多次轉換時重複請求 API
 */
async function getNianhaoData() {
    if (nianhaoDataCache) {
        return nianhaoDataCache;
    }

    try {
        const response = await axios.get('/api/select/nianhao');
        nianhaoDataCache = response.data;
        return nianhaoDataCache;
    } catch (error) {
        console.error('獲取年號數據失敗:', error);
        throw error;
    }
}

// 全局朝代範圍數據緩存
let dynastyRangesCache = null;

/**
 * 獲取朝代年份範圍數據
 * 從 API 動態加載並緩存
 */
async function getDynastyRanges() {
    if (dynastyRangesCache) {
        return dynastyRangesCache;
    }

    try {
        const response = await axios.get('/api/select/dynasty');
        const dynasties = response.data;

        // 轉換為以朝代 ID 為鍵的對象
        const ranges = {};
        for (const dynasty of dynasties) {
            const values = Object.values(dynasty);
            // 假設格式為 [c_dy, ..., c_start, c_end, ...]
            // 需要找到 c_dy, c_start, c_end 的位置
            const dynastyId = dynasty.c_dy || values[0];
            const start = dynasty.c_start;
            const end = dynasty.c_end;

            if (dynastyId && start !== undefined && end !== undefined) {
                ranges[dynastyId] = { start, end };
            }
        }

        dynastyRangesCache = ranges;
        return ranges;
    } catch (error) {
        console.error('獲取朝代範圍數據失敗:', error);
        // 返回默認範圍
        return {};
    }
}

/**
 * 根據年號 ID 和年數轉換為公元年份
 * 直接使用資料庫的年號記錄進行計算，避免字串匹配的歧義
 * @param {string|number} nianhaoId - 年號 ID (c_nianhao_id)
 * @param {number} yearNum - 年號年數
 */
async function convertNianhaoIdToYear(nianhaoId, yearNum) {
    try {
        // 從 API 獲取年號資料（使用緩存）
        const nianhaoData = await getNianhaoData();

        // 根據 ID 查找年號記錄
        const nianhaoRecord = nianhaoData.find(item => String(item.c_nianhao_id) === String(nianhaoId));

        if (!nianhaoRecord) {
            return {
                success: false,
                message: `找不到年號 ID：${nianhaoId}`,
            };
        }

        // 驗證 c_str 字段是否存在
        if (!nianhaoRecord.c_str) {
            console.error(`年號記錄缺少 c_str 字段:`, nianhaoRecord);
            return {
                success: false,
                message: `年號 ${nianhaoRecord.c_nianhao_chn} (ID: ${nianhaoId}) 的資料不完整（缺少年份範圍）\n\n請聯繫系統管理員修復數據`,
            };
        }

        // 從 c_str 字段解析年份範圍 "[1234]~[5678]"
        const rangeMatch = nianhaoRecord.c_str.match(/\[(-?\d+)\]~\[(-?\d+)\]/);
        if (!rangeMatch) {
            console.error(`年號記錄的 c_str 格式錯誤:`, nianhaoRecord);
            return {
                success: false,
                message: `年號 ${nianhaoRecord.c_nianhao_chn} (ID: ${nianhaoId}) 的年份範圍格式錯誤\n格式: ${nianhaoRecord.c_str}\n\n請聯繫系統管理員修復數據`,
            };
        }

        const firstYear = parseInt(rangeMatch[1], 10);
        const lastYear = parseInt(rangeMatch[2], 10);
        const duration = lastYear - firstYear + 1;

        // 驗證年數是否在範圍內
        if (yearNum < 1 || yearNum > duration) {
            return {
                success: false,
                message: `年號 ${nianhaoRecord.c_nianhao_chn} 的年數應在 1-${duration} 之間\n（年號範圍：公元 ${firstYear}-${lastYear}）`,
            };
        }

        // 計算公元年份
        const year = firstYear + yearNum - 1;

        return {
            success: true,
            year: year,
            nianhaoName: nianhaoRecord.c_nianhao_chn,
        };

    } catch (error) {
        console.error('年號 ID 轉換時發生錯誤:', error);
        return {
            success: false,
            message: error.message || '轉換失敗',
        };
    }
}

/**
 * 根據年號名稱、年數和朝代反向轉換為公元年份
 * 使用二分搜索優化性能
 * @param {string} reignTitle - 年號名稱
 * @param {number} yearNum - 年號年數
 * @param {number|null} dynastyCode - 朝代代碼（可選，null 表示不限朝代）
 * @deprecated 建議使用 convertNianhaoIdToYear 以避免字串匹配的歧義
 */
async function convertReignToYear(reignTitle, yearNum, dynastyCode = null) {
    try {
        // 特殊名稱映射（反向）
        const specialNameMapping = {
            '中華民國': '民國',
        };

        const searchTitle = specialNameMapping[reignTitle] || reignTitle;

        // 獲取朝代搜索範圍（從 API 動態加載）
        const SEARCH_RANGES = await getDynastyRanges();

        // 獲取指定朝代的範圍，或使用默認範圍
        const range = (dynastyCode && SEARCH_RANGES[dynastyCode]) || { start: -200, end: 2020 };

        // 決定搜索模式
        const searchOptions = dynastyCode
            ? { dynasty: dynastyCode }  // 指定朝代搜索
            : { mode: 'all' };          // 全朝代搜索

        // 使用二分搜索優化
        // 先嘗試一個年份範圍內的採樣點
        const samplePoints = [];
        const step = Math.max(1, Math.floor((range.end - range.start) / 50)); // 採樣約50個點

        for (let year = range.start; year <= range.end; year += step) {
            if (year === 0) continue;
            samplePoints.push(year);
        }

        // 在採樣點中尋找年號的大致範圍
        let foundStart = null;
        let foundEnd = null;

        for (const year of samplePoints) {
            try {
                const results = convertYear(year, searchOptions);

                for (const result of results) {
                    const titleToMatch = result.reign_title.replace(/\s*\([^)]*\)/, '').trim();

                    if (titleToMatch === searchTitle) {
                        // 找到該年號，記錄範圍
                        if (foundStart === null || year < foundStart) {
                            foundStart = year - step;
                        }
                        if (foundEnd === null || year > foundEnd) {
                            foundEnd = year + step;
                        }
                    }
                }
            } catch (e) {
                continue;
            }
        }

        // 如果找到了年號的範圍，在該範圍內精確搜索
        if (foundStart !== null) {
            foundStart = Math.max(range.start, foundStart);
            foundEnd = Math.min(range.end, foundEnd);

            for (let year = foundStart; year <= foundEnd; year++) {
                if (year === 0) continue;

                try {
                    const results = convertYear(year, searchOptions);

                    for (const result of results) {
                        const titleToMatch = result.reign_title.replace(/\s*\([^)]*\)/, '').trim();

                        if (titleToMatch === searchTitle && result.year === yearNum) {
                            return {
                                success: true,
                                year: year,
                                dynastyName: result.dynasty_name,
                                reignTitle: result.reign_title,
                            };
                        }
                    }
                } catch (e) {
                    continue;
                }
            }
        }

        // 如果沒有找到，回退到完整搜索（以防萬一）
        for (let year = range.start; year <= range.end; year++) {
            if (year === 0) continue;

            try {
                const results = convertYear(year, searchOptions);

                for (const result of results) {
                    const titleToMatch = result.reign_title.replace(/\s*\([^)]*\)/, '').trim();

                    if (titleToMatch === searchTitle && result.year === yearNum) {
                        return {
                            success: true,
                            year: year,
                            dynastyName: result.dynasty_name,
                            reignTitle: result.reign_title,
                        };
                    }
                }
            } catch (e) {
                continue;
            }
        }

        const message = dynastyCode
            ? `在指定朝代中找不到「${reignTitle} ${yearNum}年」的對應公元年份`
            : `找不到「${reignTitle} ${yearNum}年」的對應公元年份`;

        return {
            success: false,
            message: message,
        };

    } catch (error) {
        console.error('反向轉換時發生錯誤:', error);
        return {
            success: false,
            message: error.message || '轉換失敗',
        };
    }
}

/**
 * 顯示轉換成功的提示
 */
function showConversionSuccess($btn, message, defaultTitle = null) {
    const $icon = $btn.find('i');
    const originalClass = $icon.attr('class');
    $icon.attr('class', 'fas fa-check text-success');

    const originalTitle = $btn.attr('data-original-title') || $btn.attr('title') || defaultTitle;
    $btn.attr('data-original-title', `轉換成功：${message}`)
        .tooltip('dispose')
        .tooltip()
        .tooltip('show');

    setTimeout(() => {
        $icon.attr('class', originalClass);
        $btn.attr('data-original-title', originalTitle)
            .tooltip('dispose')
            .tooltip();
    }, 2000);
}

// Import SQL Formatter utility
import './utils/sqlFormatter';
