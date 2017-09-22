<template>
    <div class="form-group">
        <select2 :options="options" v-model="selected">
            <option disabled value="">请选择</option>
        </select2>
    </div>
</template>

<script>
    export default {
        data() {
            return {
                selected: 2,
                options: [
                    {id: 1, text: 'Hello'},
                    {id: 2, text: 'World'}
                ]
            }
        }
    }

</script>