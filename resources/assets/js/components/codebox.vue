<template>
    <div class="box">
        <div class="box-header with-border">
            <h3 class="box-title">Bordered Table</h3>
        </div>
        <!-- /.box-header -->
        <div class="box-body">
            <table class="table table-bordered">
                <tr>
                    <th style="width: 10px">#</th>
                    <th>Task</th>
                    <th>Progress</th>
                    <th style="width: 40px">Label</th>
                </tr>
                <tr>
                    <td>1.</td>
                    <td>Update software</td>
                    <td>
                        <div class="progress progress-xs">
                            <div class="progress-bar progress-bar-danger" style="width: 55%"></div>
                        </div>
                    </td>
                    <td><span class="badge bg-red">55%</span></td>
                </tr>
                <tr>
                    <td>2.</td>
                    <td>Clean database</td>
                    <td>
                        <div class="progress progress-xs">
                            <div class="progress-bar progress-bar-yellow" style="width: 70%"></div>
                        </div>
                    </td>
                    <td><span class="badge bg-yellow">70%</span></td>
                </tr>
                <tr>
                    <td>3.</td>
                    <td>Cron job running</td>
                    <td>
                        <div class="progress progress-xs progress-striped active">
                            <div class="progress-bar progress-bar-primary" style="width: 30%"></div>
                        </div>
                    </td>
                    <td><span class="badge bg-light-blue">30%</span></td>
                </tr>
                <tr>
                    <td>4.</td>
                    <td>Fix and squish bugs</td>
                    <td>
                        <div class="progress progress-xs progress-striped active">
                            <div class="progress-bar progress-bar-success" style="width: 90%"></div>
                        </div>
                    </td>
                    <td><span class="badge bg-green">90%</span></td>
                </tr>
            </table>
        </div>
        <!-- /.box-body -->
        <div class="box-footer clearfix">
            <ul class="pagination pagination-sm no-margin pull-right">
                <li><a href="#">&laquo;</a></li>
                <li><a href="#">1</a></li>
                <li><a href="#">2</a></li>
                <li><a href="#">3</a></li>
                <li><a href="#">&raquo;</a></li>
            </ul>
        </div>
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
                axios.post('/api/addresscode', {'q': this.q, 'page': val}).then(response => {
                    this.names = response.data;
                    this.current_page = this.names.current_page;
                });
            },
            searchByNameLazy: _.debounce(function(val=1){
                axios.post('/api/addresscode', {'q': this.q, 'page': val}).then(response => {
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
