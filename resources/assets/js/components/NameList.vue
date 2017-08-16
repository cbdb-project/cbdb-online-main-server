<template>
    <div class="form-group">
        <div class="text-center">查询人物</div>
        <div class="input-group">
            <input type="text" class="form-control search-key" placeholder="Search">
            <div class="input-group-btn">
                <button class="btn btn-default search-name" @click="searchByName">
                    <i class="glyphicon glyphicon-search"></i>
                </button>
            </div>
        </div>
    </div>
</template>

<script>
    export default {
        mounted() {
            axios.post('/api/name', {}).then(response => {
                console.log(response.data)
            });
        },
        computed: {

        },
        methods: {
            searchByName(e) {
                console.log('search');
            }
        }
    }
</script>
