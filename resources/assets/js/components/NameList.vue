<template>
    <div class="">
        <div class="form-group">
            <div class="text-center">查詢人物</div>
            <div class="input-group">
                <input v-model="q" type="text" class="form-control search-key" placeholder="Search">
                <div class="input-group-btn">
                    <button class="btn btn-default search-name" @click="searchByName">
                        <i class="glyphicon glyphicon-search"></i>
                    </button>
                </div>
            </div>
        </div>
        <table class="table table-hover table-condensed">
            <caption>共查詢到{{names.total}}條記錄</caption>
            <thead>
            <tr>
                <th>c_personid</th>
                <th>c_name_chn</th>
                <th>c_name</th>
                <th>dynasty</th>
                <th>index year</th>
                <th>index address</th>
                <th>zi</th>
                <th>hao</th>
            </tr>
            </thead>
            <tbody>
                <tr v-for="item in names.data">
                    <td><a v-bind:href="'/basicinformation/'+item.c_personid+'/edit'" target="_blank">{{item.c_personid}}</a></td>
                    <td><a v-bind:href="'/basicinformation/'+item.c_personid+'/edit'" target="_blank">{{item.c_name_chn}}</a></td>
                    <td><a v-bind:href="'/basicinformation/'+item.c_personid+'/edit'" target="_blank">{{item.c_name}}</a></td>
                    <td><a v-bind:href="'/basicinformation/'+item.c_personid+'/edit'" target="_blank">{{item.c_dynasty_chn}}</a></td>
                    <td><a v-bind:href="'/basicinformation/'+item.c_personid+'/edit'" target="_blank">{{item.c_index_year}}</a></td>
                    <td><a v-bind:href="'/basicinformation/'+item.c_personid+'/edit'" target="_blank">{{item.ADDR_c_name_chn}}</a></td>
                    <td><a v-bind:href="'/basicinformation/'+item.c_personid+'/edit'" target="_blank">{{item.c_alt_name_chn_zi}}</a></td>
                    <td><a v-bind:href="'/basicinformation/'+item.c_personid+'/edit'" target="_blank">{{item.c_alt_name_chn_hao}}</a></td>
                </tr>
            </tbody>
        </table>
        <nav class="pull-right" aria-label="Page navigation">
            <ul class="pagination">
                <li v-if="showFirst"><a href="javascript:" @click="current_page--">«</a></li>
                <li v-for="index in indexes" :class="{ 'active': current_page == index}">
                    <a @click="btnClick(index)" href="javascript:">{{ index }}</a>
                </li>
                <li v-if="showLast"><a @click="current_page++" href="javascript:">»</a></li>
                <li><a>共<i>{{names.last_page}}</i>页</a></li>
            </ul>
        </nav>
    </div>
</template>

<script>
    export default {
        props:['user'],
        created() {
            this.notes = '正在查詢，請稍候';
            this.searchByName();
            this.notes = '';
        },
        data() {
            return {
                names: {},
                q: '',
                current_page: '',
                page_num: 7,
                notes: '',
            }
        },
        computed: {
            indexes : function(){
                let list = [];
                //计算左右页码
                let mid = parseInt(this.page_num / 2);//中间值
                let left = Math.max(this.current_page - mid,1);
                let right = Math.max(this.current_page + this.page_num - mid -1,this.page_num);
                if (right > this.names.last_page ) { right = this.names.last_page}
                while (left <= right){
                    list.push(left);
                    left ++;
                }
                return list;
            },
            showLast: function(){
                return this.current_page !== this.names.last_page;
            },
            showFirst: function(){
                return this.current_page !== 1;
            },

        },
        methods: {
            searchByName(val = 1) {
                axios.post('/api/name', {'q': this.q, 'page': val}).then(response => {
                    this.names = response.data;
                    this.current_page = this.names.current_page;
                });
            },
            searchByNameLazy: _.debounce(function(val=1){
                axios.post('/api/name', {'q': this.q, 'page': val}).then(response => {
                    this.names = response.data;
                    this.current_page = this.names.current_page;
                });
            }, 500),
            btnClick: function(index){
                if(index !== this.current_page){
                    this.current_page = index;
                }
            }
        },
        watch:{
            "current_page" : function(val,oldVal) {
                this.searchByName(val);
            },
            "q": function () {
                this.notes = '正在查詢，請稍候';
                this.searchByNameLazy();
                this.notes = '';
            }
        }
    }
</script>
