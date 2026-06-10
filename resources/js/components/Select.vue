<template>
    <select class="form-control select2" :id="elementId" v-bind:name="name" v-model="selectedid" :disabled="disabled">
        <!--<option disabled value="">请选择</option>-->
        <option value="">请选择</option>
        <option v-for="(item, index) in displayData" :key="id(item) ?? index" v-bind:value="id(item)">{{ normalization(item) }}</option>
    </select>
</template>

<script>
    // 全局缓存对象 - 存储已加载的数据
    const selectDataCache = {};
    // 正在进行的请求 - 避免并发重复请求
    const pendingRequests = {};

    // 不參與顯示的欄位（僅供過濾用）
    const hiddenFields = {
        nianhao: ['c_firstyear', 'c_lastyear'],
    };

    export default {
        props: ['name', 'model', 'selected', 'elementId', 'idKey', 'disabled'],
        data() {
          return {
              data: {},
              selectedid: this.selected,
              dynastyStart: null,
              dynastyEnd: null,
          }
        },
        watch: {
            // 選項以非同步方式（axios）載入，而 select2 是在 Blade 的 onViteReady
            // 中先行初始化，此時原生 <select> 通常只有 placeholder。select2 只會在
            // 每次按鍵時重新查詢目前的 <option>，因此使用者若在資料載入完成前展開並
            // 輸入關鍵字，會看到「No results / 沒有結果」且不會自動恢復。
            // 資料載入後重新整理 select2，確保選項與已選值同步。
            data() {
                this.$nextTick(() => this.syncSelect2());
            },
        },
        computed: {
            displayData() {
                if (this.model !== 'nianhao' || !Array.isArray(this.data) || this.data.length === 0) {
                    return this.data;
                }
                if (this.dynastyStart === null || this.dynastyEnd === null) {
                    return this.data;
                }

                const dyStart = this.dynastyStart;
                const dyEnd = this.dynastyEnd;

                // 判斷年號是否與朝代時間有交集（未詳視同匹配，排在前面）
                const isMatch = (item) => {
                    const fy = item.c_firstyear;
                    const ly = item.c_lastyear;
                    if (!fy || !ly || fy === 0 || ly === 0) return true;
                    return fy <= dyEnd && ly >= dyStart;
                };

                // 排序：匹配＋未詳在前，不匹配在後，各組內保持原始順序
                const matching = [];
                const rest = [];
                this.data.forEach(item => {
                    (isMatch(item) ? matching : rest).push(item);
                });

                // Fallback：若無任何有明確年份的匹配，不排序
                if (!matching.some(item => item.c_firstyear && item.c_lastyear && item.c_firstyear !== 0 && item.c_lastyear !== 0)) {
                    return this.data;
                }

                return [...matching, ...rest];
            }
        },
        created() {
            this.getData();
        },
        mounted() {
            this.readDynastyYears();
        },
        methods: {
            // 資料載入完成後重新整理 select2，使其反映最新的 <option> 與已選值。
            // 若 Blade 尚未初始化 select2（無 select2-hidden-accessible），則略過，
            // 由 Blade 之後初始化時即可帶入完整選項。
            syncSelect2() {
                const jq = window.jQuery || window.$;
                if (!jq || !jq.fn || !jq.fn.select2) return;
                const $el = jq(this.$el);
                if (!$el.hasClass('select2-hidden-accessible')) return;

                const wasOpen = $el.select2('isOpen');
                const val = this.selectedid;
                $el.select2('destroy').select2();
                if (val !== undefined && val !== null && val !== '') {
                    // change.select2 命名空間只通知 select2，避免誤觸表單 dirty 偵測
                    $el.val(String(val)).trigger('change.select2');
                }
                if (wasOpen) {
                    $el.select2('open');
                }
            },
            readDynastyYears() {
                if (this.model !== 'nianhao') return;
                const startEl = document.querySelector('.dynasty_start');
                const endEl = document.querySelector('.dynasty_end');
                if (startEl && startEl.value && endEl && endEl.value) {
                    const start = parseInt(startEl.value);
                    const end = parseInt(endEl.value);
                    if (!isNaN(start) && !isNaN(end)) {
                        this.dynastyStart = start;
                        this.dynastyEnd = end;
                    }
                }
            },
            async getData() {
                const model = this.model;

                // 1. 检查缓存
                if (selectDataCache[model]) {
                    this.data = selectDataCache[model];
                    return;
                }

                // 2. 检查是否有正在进行的请求
                if (pendingRequests[model]) {
                    try {
                        this.data = await pendingRequests[model];
                    } catch (error) {
                        console.error(`Failed to load select data for ${model}:`, error);
                        this.data = {};
                    }
                    return;
                }

                // 3. 发送新请求并缓存
                const requestPromise = axios.get('/api/select/' + model)
                    .then(response => {
                        selectDataCache[model] = response.data;
                        delete pendingRequests[model];
                        return response.data;
                    })
                    .catch(error => {
                        // 请求失败时清理待处理请求
                        delete pendingRequests[model];
                        throw error;
                    });

                pendingRequests[model] = requestPromise;
                try {
                    this.data = await requestPromise;
                } catch (error) {
                    console.error(`Failed to load select data for ${model}:`, error);
                    this.data = {};
                }
            },
            normalization(item) {
                const hidden = hiddenFields[this.model] || [];
                let str = '';
                for (let key in item) {
                    if (hidden.includes(key)) continue;
                    const val = item[key];
                    if (val === null || val === undefined || val === '') continue;
                    str += val + ' ';
                }
                return str.trim();
            },
            id(item) {
                const modelIdKeyMap = {
                    ethnicity: 'c_ethnicity_code',
                    choronym: 'c_choronym_code',
                    dynasty: 'c_dy',
                    nianhao: 'c_nianhao_id',
                    biogaddr: 'c_addr_type',
                    altcode: 'c_name_type_code',
                    role: 'c_role_id',
                    range: 'c_range_code',
                    ganzhi: 'c_ganzhi_code',
                    household: 'c_household_status_code',
                    appttype: 'c_appt_code',
                    assumeoffice: 'c_assume_office_code',
                    officecate: 'c_office_category_id',
                    parentstatus: 'c_parental_status_code',
                    measure: 'c_measure_code',
                    possact: 'c_possession_act_code',
                    birole: 'c_bi_role_code',
                    topic: 'c_topic_code',
                    occasion: 'c_occasion_code',
                };

                const preferredKey = this.idKey || modelIdKeyMap[this.model];
                if (preferredKey && Object.prototype.hasOwnProperty.call(item, preferredKey)) {
                    return item[preferredKey];
                }

                if (Object.prototype.hasOwnProperty.call(item, 'id')) {
                    return item.id;
                }

                const keys = Object.keys(item);
                if (keys.length === 1) {
                    return item[keys[0]];
                }

                const suffixPriority = ['_id', '_code'];
                for (const suffix of suffixPriority) {
                    const matches = keys.filter((key) => key.endsWith(suffix));
                    if (matches.length > 0) {
                        matches.sort();
                        return item[matches[0]];
                    }
                }

                keys.sort();
                return keys.length > 0 ? item[keys[0]] : '';
            }
        },
    }

</script>
