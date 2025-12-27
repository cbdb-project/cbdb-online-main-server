/**
 * Modern World Entry Point - Main Application
 *
 * This is for AdminLTE v3 + Vue 3 pages (Modern World)
 * Legacy World (AdminLTE 2) remains in resources/assets/js/
 */

// Import AdminLTE v3 CSS from NPM
import 'admin-lte/dist/css/adminlte.min.css';

// Import custom CSS overrides
import '../css/select2-overrides.css';

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
    $(document).on('click', '.era-convert-btn', function(e) {
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
            // 嘗試從頁面獲取朝代信息以提高轉換精確度
            const $dynastySelect = $('select[name="c_dy"]');
            const dynastyCode = $dynastySelect.length ? parseInt($dynastySelect.val(), 10) : null;

            // 調用 cn-era 進行轉換
            let results;
            if (dynastyCode && !isNaN(dynastyCode) && dynastyCode > 0) {
                // 如果有朝代信息，使用朝代過濾
                results = convertYear(year, { dynasty: dynastyCode });

                // 如果指定朝代沒有結果，降級到 mainline 模式
                if (!results || results.length === 0) {
                    console.warn(`朝代 ${dynastyCode} 中沒有找到對應年號，嘗試使用主線朝代`);
                    results = convertYear(year, { mode: 'mainline' });
                }
            } else {
                // 沒有朝代信息，使用主線朝代
                results = convertYear(year, { mode: 'mainline' });
            }

            if (!results || results.length === 0) {
                alert(`無法找到公元 ${year} 年對應的年號`);
                return;
            }

            // 使用第一個結果
            const eraResult = results[0];
            const reignTitle = eraResult.reign_title;
            const yearNum = eraResult.year;

            // 查找年號對應的 ID
            findNianhaoIdByName(reignTitle).then(nianhaoId => {
                if (nianhaoId) {
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
                } else {
                    alert(`找到年號「${reignTitle}」，但在資料庫中未找到對應記錄\n轉換結果：${eraResult.dynasty_name} ${reignTitle} ${eraResult.year_num}`);
                }
            }).catch(error => {
                console.error('查找年號 ID 時發生錯誤:', error);
                alert('轉換失敗：無法查找年號資料');
            });

        } catch (error) {
            console.error('年號轉換錯誤:', error);
            alert(`轉換失敗：${error.message}`);
        }
    });
}

/**
 * 根據年號名稱查找對應的 NIANHAO_CODES ID
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

        // 獲取年號數據
        const response = await axios.get('/api/select/nianhao');
        const nianhaoData = response.data;

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

/**
 * 顯示轉換成功的提示
 */
function showConversionSuccess($btn, message) {
    const $icon = $btn.find('i');
    const originalClass = $icon.attr('class');
    $icon.attr('class', 'fas fa-check text-success');

    const originalTitle = $btn.attr('data-original-title') || $btn.attr('title');
    $btn.attr('data-original-title', `轉換成功：${message}`)
        .tooltip('dispose')
        .tooltip()
        .tooltip('show');

    setTimeout(() => {
        $icon.attr('class', originalClass);
        $btn.attr('data-original-title', originalTitle || '將公元年份轉換為年號')
            .tooltip('dispose')
            .tooltip();
    }, 2000);
}
