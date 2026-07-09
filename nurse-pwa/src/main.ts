import { createApp } from 'vue';
import { registerSW } from 'virtual:pwa-register';
import App from './App.vue';
import { i18n } from './i18n';

import './styles.css';

registerSW({ immediate: true });

createApp(App).use(i18n).mount('#app');
