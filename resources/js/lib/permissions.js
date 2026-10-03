import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Permission checks for showing or hiding UI. The server enforces every permission again;
 * hiding a button is a convenience, never a security control.
 */
export function usePermissions() {
    const page = usePage();
    const granted = computed(() => new Set(page.props.auth?.permissions ?? []));

    const can = (permission) => granted.value.has(permission);
    const canAny = (permissions) => permissions.some((p) => granted.value.has(p));

    return { can, canAny };
}
