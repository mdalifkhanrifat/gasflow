import { nextTick, ref } from 'vue';
import { ApiValidationError } from '@/api/client';

// Field errors for one form: the first message per field from Laravel's 422,
// or set by the page for checks it makes itself.
export function useFormErrors() {
    const errors = ref<Record<string, string>>({});

    function clear(): void {
        errors.value = {};
    }

    function set(field: string, message: string): void {
        errors.value = { ...errors.value, [field]: message };
    }

    // Returns false for errors that are not validation errors, which the
    // caller still has to handle.
    function fromApi(error: unknown): boolean {
        if (!(error instanceof ApiValidationError)) {
            return false;
        }
        errors.value = Object.fromEntries(
            Object.entries(error.errors)
                .filter(([, messages]) => messages.length)
                .map(([field, messages]) => [field, messages[0] ?? '']),
        );
        return true;
    }

    // Move keyboard focus to the first invalid control in the form.
    async function focusFirstError(
        form?: HTMLFormElement | null,
    ): Promise<void> {
        await nextTick();
        form?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
    }

    return { errors, clear, set, fromApi, focusFirstError };
}
