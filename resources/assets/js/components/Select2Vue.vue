<template>
    <div class="form-group">
        <select2 :options="options" v-model="selectedid" :name="name">
            <option disabled value="">请选择</option>
        </select2>
    </div>
</template>

<script>
    export default {
        props: ['name', 'model', 'selected'],
        data() {
            return {
                selectedid: this.selected,
                options: [
                    {id: 1, text: 'Hello'},
                    {id: 2, text: 'World'}
                ]
            }
        },
        methods: {
            getData() {
                axios.get('/api/select/'+this.model).then(response => {
                    this.data = response.data;
                });
            },
        }
    }

</script>