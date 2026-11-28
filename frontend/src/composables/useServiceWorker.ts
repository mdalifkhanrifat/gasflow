import { registerSW } from 'virtual:pwa-register';
import { useLocale } from '@/composables/useLocale';
import { useToast } from '@/composables/useToast';

// Registers the service worker (production builds only). A new version waits
// until the user chooses to reload, so an update never interrupts their work.
export function registerServiceWorker(): void {
    const { t } = useLocale();
    const { notify } = useToast();
    const updateServiceWorker = registerSW({
        immediate: true,
        onNeedRefresh() {
            notify(t('pwa.update_ready'), 'info', {
                persistent: true,
                action: {
                    label: t('pwa.reload'),
                    run: () => void updateServiceWorker(true),
                },
            });
        },
    });
}
