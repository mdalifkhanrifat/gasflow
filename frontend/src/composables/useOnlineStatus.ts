import { readonly, ref } from 'vue';

// The browser's connection state, shared by the whole app.
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
if (typeof window !== 'undefined') {
    window.addEventListener('online', () => (online.value = true));
    window.addEventListener('offline', () => (online.value = false));
}

export function useOnlineStatus() {
    return { online: readonly(online) };
}
