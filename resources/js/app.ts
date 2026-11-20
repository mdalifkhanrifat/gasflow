import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';
import { watch } from 'vue';
import { initializeTheme } from '@/composables/useTheme';
import bangla from '../../lang/bn.json';
import english from '../../lang/en.json';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

if (typeof window !== 'undefined') {
    initializeTheme();
}

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    withApp: (app, { page, ssr }) => {
        app.use(i18nVue, {
            lang: page?.props.locale ?? 'bn',
            resolve: (locale: string) => (locale === 'bn' ? bangla : english),
            fallbackMissingTranslations: true,
            shared: !ssr,
        });

        if (!ssr) {
            const currentPage = usePage();
            const stop = watch(
                () => currentPage.props?.locale,
                (locale) => {
                    if (locale) {
                        document.documentElement.lang = locale;
                        void loadLanguageAsync(locale);
                    }
                },
            );
            app.onUnmount(stop);
        }

        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: 'var(--primary)',
    },
});
