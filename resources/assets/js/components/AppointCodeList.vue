<template>
    <div class="">
        <div class="form-group">
            <div class="text-center">查询任命类型</div>
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
            <caption>共查询到{{names.total}}条记录</caption>
            <thead>
            <tr>
                <th>c_appt_type_code</th>
                <th>c_appt_type_desc_chn</th>
                <th>c_appt_type_desc</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="item in names.data">
                <td>{{item.c_appt_type_code}}</td>
                <td>{{item.c_appt_type_desc_chn}}</td>
                <td>{{item.c_appt_type_desc}}</td>
                <td>
                    <div class="btn-group">
                        <a type="button" class="btn btn-sm btn-info" :href="'/appointcodes/'+item.c_appt_type_code+'/edit'">edit</a>
                        <a type="button" class="btn btn-sm btn-danger">delete</a>
                    </div>
                </td>
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
        props:[],
        created() {
            this.notes = '正在查询，请稍后';
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
                axios.post('/api/appointcode', {'q': this.q, 'page': val}).then(response => {
                    this.names = response.data;
                    this.current_page = this.names.current_page;
                });
            },
            searchByNameLazy: _.debounce(function(val=1){
                axios.post('/api/appointcode', {'q': this.q, 'page': val}).then(response => {
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
                this.notes = '正在查询，请稍后';
                this.searchByNameLazy();
                this.notes = '';
            }
        }
    }
</script>
