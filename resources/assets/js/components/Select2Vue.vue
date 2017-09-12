<template>
    <div class="form-group">
        <span>{{ data }}</span>
        <select2 :options="options" v-model="selected">
            <option disabled value="0">Select one</option>
        </select2>
    </div>
</template>

<script>
    export default {
        data() {
            return {
                selected: 2,
                options: this.getData(),
                data: '',
            }

        },
        methods: {
            getData() {
                axios.get('/api/select/codes').then(response => {
                    let data = response.data;
                    let temp = [];
                    data.forEach(function (idx, value) {
                        temp.push({id: value, text: data[value]});
                    });
                    console.log(temp);
                    return temp;
                });
            },
        },
        watch: {

        },
    }

</script>