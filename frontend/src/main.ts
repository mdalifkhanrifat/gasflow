import { createApp } from 'vue';
import { initializeTheme } from '@/composables/useTheme';
import { restoreLocale } from '@/composables/useLocale';
import router from '@/router';
import App from './App.vue';
import './styles/app.css';

initializeTheme();
void restoreLocale();
createApp(App).use(router).mount('#app');
