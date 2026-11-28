import { computed, readonly, ref, shallowRef } from 'vue';

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

function isStandalone(): boolean {
    return (
        matchMedia('(display-mode: standalone)').matches ||
        ('standalone' in navigator && navigator.standalone === true)
    );
}

// Chromium browsers announce an install with `beforeinstallprompt`; the app
// keeps the event to show its own button. iPhone and iPad Safari have no such
// event, so people add the app from the Share menu instead. Imported early in
// main.ts so the event is never missed.
const deferred = shallowRef<BeforeInstallPromptEvent>();
const installed = ref(isStandalone());
const isIos =
    /iPhone|iPad|iPod/.test(navigator.userAgent) ||
    (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferred.value = event as BeforeInstallPromptEvent;
});
window.addEventListener('appinstalled', () => {
    installed.value = true;
    deferred.value = undefined;
});

export function useInstallPrompt() {
    const canPrompt = computed(() => !installed.value && !!deferred.value);
    const needsIosSteps = computed(() => !installed.value && isIos);

    // Returns true when the user accepted the browser's install dialog.
    async function install(): Promise<boolean> {
        const event = deferred.value;
        if (!event) {
            return false;
        }
        deferred.value = undefined;
        await event.prompt();
        return (await event.userChoice).outcome === 'accepted';
    }

    return {
        canPrompt,
        needsIosSteps,
        installed: readonly(installed),
        install,
    };
}
