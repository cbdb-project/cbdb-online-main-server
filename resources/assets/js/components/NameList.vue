<template>
    <div class="form-group">
        <div class="text-center">查询人物</div>
        <div class="input-group">
            <input v-model="q" type="text" class="form-control search-key" placeholder="Search">
            <div class="input-group-btn">
                <button class="btn btn-default search-name" v-on:click="searchByName">
                    <i class="glyphicon glyphicon-search"></i>
                </button>
            </div>
        </div>
        <div v-for="item in names.data">
            <a v-bind:href="'/basicinformation/' + item.c_personid + '/edit'" target="_blank"> {{item.c_name_chn}}</a>
        </div>
    </div>
</template>

<script>
    export default {
        props:['user'],
        mounted() {
        },
        data() {
            return {
                names: {},
                q: ''
            }
        },
        computed: {

        },
        methods: {
            searchByName() {
                axios.post('/api/name', {'q': this.q}).then(response => {
                    this.names = response.data
                });
            }
        }
    }
</script>
