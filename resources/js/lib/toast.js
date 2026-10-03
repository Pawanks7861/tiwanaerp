import { reactive } from 'vue';

export const toasts = reactive([]);
let nextId = 1;

export function dismissToast(id) {
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) {
        toasts.splice(index, 1);
    }
}

function push(type, message) {
    if (!message) {
        return;
    }
    const id = nextId++;
    toasts.push({ id, type, message });
    setTimeout(() => dismissToast(id), type === 'error' ? 8000 : 4000);
}

export const toast = {
    success: (message) => push('success', message),
    error: (message) => push('error', message),
    /** Shows the first validation message of an Inertia error bag. */
    errors: (errors) => push('error', Object.values(errors ?? {})[0]),
};
