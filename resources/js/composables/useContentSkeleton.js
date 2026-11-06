import { computed, inject } from 'vue';

/**
 * Section-level skeleton flag provided by AppLayout during Inertia visits.
 * Full page navigations use the layout overlay; preserveState visits set "section".
 */
export function useContentSkeleton() {
    const mode = inject('contentSkeleton', computed(() => false));

    return computed(() => mode.value === 'section' || mode.value === 'page');
}
