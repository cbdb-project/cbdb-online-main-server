<template>

    <select>
        <slot></slot>
    </select>

</template>

<script>
    export default {
        props: ['options', 'value'],
        mounted: function () {
            let vm = this;
            console.log(this.options);
            $(this.$el)
            // init select2
                .select2({ data: vm.options })
                .val(vm.value)
                .trigger('change')
                // emit event on change.
                .on('change', function () {
                    vm.$emit('input', vm.value)
                })
        },
        watch: {
            value: function (value) {
                // update value
                console.log(value);
                $(this.$el).val(value).trigger('change');
            },
            options: function (options) {
                // update options
                $(this.$el).select2({ data: options })
            }
        },
        destroyed: function () {
            $(this.$el).off().select2('destroy')
        }
    }

</script>