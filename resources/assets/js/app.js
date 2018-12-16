
/**
 * First we will load all of this project's JavaScript dependencies which
 * includes Vue and other libraries. It is a great starting point when
 * building robust, powerful web applications using Vue and Laravel.
 */

require('./bootstrap');

window.Vue = require('vue');

/**
 * Next, we will create a fresh Vue application instance and attach it to
 * the page. Then, you may begin adding components to this application
 * or customize the JavaScript scaffolding to fit your unique needs.
 */

Vue.component('example', require('./components/Example.vue'));
Vue.component('name-list', require('./components/NameList.vue'));
Vue.component('address-code-list', require('./components/AddrCodeList.vue'));
Vue.component('altname-code-list', require('./components/AltnameCodeList.vue'));
Vue.component('appoint-code-list', require('./components/AppointCodeList.vue'));
Vue.component('text-code-list', require('./components/TextCodeList.vue'));
Vue.component('addr-belongs-data-list', require('./components/AddrBelongsDataList.vue'));
Vue.component('codebox', require('./components/codebox.vue'));

Vue.component('select-vue', require('./components/Select.vue'));
Vue.component('select2-vue', require('./components/Select2Vue.vue'));
Vue.component('select2', require('./components/Select2.vue'));
Vue.component('select2-addr', require('./components/Select2Addr.vue'));

Vue.component(
    'passport-clients',
    require('./components/passport/Clients.vue')
);

Vue.component(
    'passport-authorized-clients',
    require('./components/passport/AuthorizedClients.vue')
);

Vue.component(
    'passport-personal-access-tokens',
    require('./components/passport/PersonalAccessTokens.vue')
);

const app = new Vue({
    el: '#app'
});
