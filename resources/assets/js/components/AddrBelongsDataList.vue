<template>
    <div class="">
        <div class="form-group">
            <div class="text-center">查询地址从属</div>
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
                <th>c_addr_id</th>
                <th>c_belongs_to</th>
                <th>c_firstyear</th>
                <th>c_lastyear</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="item in names.data">
                <td>{{item.c_addr_id}}</td>
                <td>{{item.c_belongs_to}}</td>
                <td>{{item.c_firstyear}}</td>
                <td>{{item.c_lastyear}}</td>
                <td>
                    <div class="btn-group">
                        <a type="button" class="btn btn-sm btn-info" :href="'/addrbelongsdata/'+item.c_addr_id+'-'+item.c_belongs_to+'-'+item.c_firstyear+'-'+item.c_lastyear+'/edit'">edit</a>
                        <button type="button" class="btn btn-sm btn-danger" data-toggle="modal" :data-target="'#myModal'+item.c_addr_id+'-'+item.c_belongs_to+'-'+item.c_firstyear+'-'+item.c_lastyear+''">Delete</button>
                    </div>
                    <!--Start-->
                    <div :id="'myModal'+item.c_addr_id+'-'+item.c_belongs_to+'-'+item.c_firstyear+'-'+item.c_lastyear+''" class="modal fade" role="dialog">
                      <div class="modal-dialog">
                        <!-- Modal content-->
                        <div class="modal-content">
                          <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">確認是否刪除？</h4>
                          </div>
                          <div class="modal-footer">
                            <a type="button" class="btn btn-sm btn-danger" :href="'/addrbelongsdata/'+item.c_addr_id+'-'+item.c_belongs_to+'-'+item.c_firstyear+'-'+item.c_lastyear+'/delete'">Confirm Delete</a>
                            <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                          </div>
                        </div>
                      </div>
                    </div>
                    <!--End-->
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
                axios.post('/api/addrbelongsdata', {'q': this.q, 'page': val}).then(response => {
                    this.names = response.data;
                    this.current_page = this.names.current_page;
                });
            },
            searchByNameLazy: _.debounce(function(val=1){
                axios.post('/api/addrbelongsdata', {'q': this.q, 'page': val}).then(response => {
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
