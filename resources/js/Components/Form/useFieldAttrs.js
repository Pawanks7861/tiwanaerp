import { computed, useAttrs } from 'vue';

/**
 * Input components render a wrapper (label, error) around the control: `class` goes on the
 * wrapper so grid utilities work, every other attribute goes on the control itself.
 */
export function useFieldAttrs() {
    const attrs = useAttrs();

    return {
        wrapperClass: computed(() => attrs.class),
        controlAttrs: computed(() => {
            // eslint-disable-next-line no-unused-vars
            const { class: _class, ...rest } = attrs;

            return rest;
        }),
    };
}
