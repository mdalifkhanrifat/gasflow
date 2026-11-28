import { readonly, ref } from 'vue';

export type ToastTone = 'success' | 'info' | 'warning' | 'danger';

export interface Toast {
    id: number;
    message: string;
    tone: ToastTone;
}

// Short confirmations shown by ToastRegion. Errors that block a form stay
// next to the form; toasts only report what already happened.
const visibleLimit = 3;
const duration = 5000;
const toasts = ref<Toast[]>([]);
const timers = new Map<number, ReturnType<typeof setTimeout>>();
let nextId = 0;
let paused = false;

function startTimer(id: number): void {
    clearTimeout(timers.get(id));
    timers.set(
        id,
        setTimeout(() => dismiss(id), duration),
    );
}

function dismiss(id: number): void {
    clearTimeout(timers.get(id));
    timers.delete(id);
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

function notify(message: string, tone: ToastTone = 'success'): void {
    const toast = { id: ++nextId, message, tone };
    toasts.value = [...toasts.value, toast];
    toasts.value.slice(0, -visibleLimit).forEach(({ id }) => dismiss(id));
    if (!paused) {
        startTimer(toast.id);
    }
}

// Pointer or keyboard focus inside the region keeps every toast open.
function pause(): void {
    paused = true;
    timers.forEach((timer) => clearTimeout(timer));
    timers.clear();
}

function resume(): void {
    paused = false;
    toasts.value.forEach(({ id }) => startTimer(id));
}

export function useToast() {
    return { toasts: readonly(toasts), notify, dismiss, pause, resume };
}
