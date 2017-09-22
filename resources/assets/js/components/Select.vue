<template>
    <select class="form-control select2" v-bind:name="name" v-model="selectedid">
        <option disabled value="">请选择</option>
        <option v-for="item in data" v-bind:value="id(item)">{{ normalization(item) }}</option>
    </select>
</template>

<script>
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
            getData() {
                axios.get('/api/select/'+this.model).then(response => {
                    this.data = response.data;
                });
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