import { registerSW } from 'virtual:pwa-register';
import { createApp } from 'vue';
import { initializeTheme } from '@/composables/useTheme';
import { restoreLocale } from '@/composables/useLocale';
import { loadStatusCatalogue } from '@/composables/useStatusCatalogue';
import router from '@/router';
import App from './App.vue';
import './styles/app.css';

initializeTheme();
void restoreLocale();
void loadStatusCatalogue();
createApp(App).use(router).mount('#app');
void registerSW({ immediate: true });
