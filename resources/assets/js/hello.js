require('./bootstrap');
// 匯入 Hello.vue 檔，不需加副檔名
import Hello from './components/Hello'
new Vue({
    // 找到 hello.blade.php 中指定的掛載點元素
    el: '#app',
  
    // 使用我們建立的 Hello(.vue) 元件
    components: { Hello }
})
