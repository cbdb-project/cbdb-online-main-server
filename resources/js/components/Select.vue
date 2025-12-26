<template>
    <select class="form-control select2" :id="id" v-bind:name="name" v-model="selectedid">
        <!--<option disabled value="">请选择</option>-->
        <option value="">请选择</option>
        <option v-for="item in data" v-bind:value="id(item)">{{ normalization(item) }}</option>
    </select>
</template>

<script>
    // 全局缓存对象 - 存储已加载的数据
    const selectDataCache = {};
    // 正在进行的请求 - 避免并发重复请求
    const pendingRequests = {};

    export default {
        props: ['name', 'model', 'selected', 'id', 'idKey'],
        data() {
          return {
              data: {},
              selectedid: this.selected,
          }
        },
        created() {
            this.getData();
        },
        methods: {
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
                let str = '';
                for (let key in item) {
                    str += item[key]+' ';
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
