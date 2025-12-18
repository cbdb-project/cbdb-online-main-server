<template>
    <select class="form-control select2" v-bind:name="name" v-model="selectedid">
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
        props: ['name', 'model', 'selected'],
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
                for (let key in item) {
//                    console.log(item[key]);
                    return item[key];
                }
            }
        },
    }

</script>
