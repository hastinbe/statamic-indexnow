import IndexNowUtility from './components/IndexNowUtility.vue'

Statamic.booting(() => {
    Statamic.component('indexnow-utility', IndexNowUtility)
})
