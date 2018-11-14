<template>
    <div>
        <h1>Hello</h1>
        <p class="hello">Hi {{ name || '匿名' }}</p>
        <p>{{ msg }}</p>
    </div>
</template>
<script>
    export default {
        props: ['name'],
        data () {
            return {
                msg: 'This is a Laravel with Vue Demo.'
            }
        }
    }
</script>
<style>
.hello {
    font-size: 2em;
    color: green;
}
</style>
