<script setup>
import { computed, useId } from 'vue';
import FormField from './FormField.vue';
import { inputClasses } from './inputClasses';
import { useFieldAttrs } from './useFieldAttrs';

/**
 * Decimal entry kept as a string (never a JS float). Only digits and one decimal point
 * with at most `decimals` places are accepted; the server validates again.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    label: { type: String, default: null },
    error: { type: String, default: null },
    help: { type: String, default: null },
    required: { type: Boolean, default: false },
    decimals: { type: Number, default: 2 },
    prefix: { type: String, default: null },
    suffix: { type: String, default: null },
});

const model = defineModel({ type: [String, Number, null], default: null });
const id = useId();
const { wrapperClass, controlAttrs } = useFieldAttrs();
const pattern = computed(() => new RegExp(`^\\d*(\\.\\d{0,${props.decimals}})?$`));

function onInput(event) {
    const raw = event.target.value.replace(/[,\s₹]/g, '');
    if (raw === '' || pattern.value.test(raw)) {
        model.value = raw === '' ? null : raw;
    } else {
        event.target.value = model.value ?? '';
    }
}
</script>

<template>
    <FormField :class="wrapperClass" :label="label" :for="id" :error="error" :help="help" :required="required">
        <div class="relative">
            <span v-if="prefix" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-sm text-slate-500">
                {{ prefix }}
            </span>
            <input
                :id="id"
                v-bind="controlAttrs"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                :value="model ?? ''"
                :class="[inputClasses(error), 'text-right tabular', prefix ? 'pl-8' : '', suffix ? 'pr-12' : '']"
                :aria-invalid="!!error"
                @input="onInput"
            />
            <span v-if="suffix" class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-sm text-slate-500">
                {{ suffix }}
            </span>
        </div>
    </FormField>
</template>
