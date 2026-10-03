<script setup>
import { computed } from 'vue';

/**
 * Compact grid cell for decimals, kept as a string (never a JS float). Same rules as DecimalInput.
 */
const props = defineProps({
    decimals: { type: Number, default: 4 },
    error: { type: Boolean, default: false },
    placeholder: { type: String, default: null },
    allowNegative: { type: Boolean, default: false },
});

const model = defineModel({ type: [String, Number, null], default: null });
const pattern = computed(() => new RegExp(`^${props.allowNegative ? '-?' : ''}\\d*(\\.\\d{0,${props.decimals}})?$`));

function onInput(event) {
    const raw = event.target.value.replace(/[,\s₹%]/g, '');
    if (raw === '' || pattern.value.test(raw)) {
        model.value = raw === '' ? null : raw;
    } else {
        event.target.value = model.value ?? '';
    }
}
</script>

<template>
    <input
        type="text"
        inputmode="decimal"
        autocomplete="off"
        :value="model ?? ''"
        :placeholder="placeholder"
        class="block w-full min-w-[5.5rem] rounded border px-1.5 py-1 text-right text-xs tabular focus:border-brand-500 focus:ring-1 focus:ring-brand-200"
        :class="error ? 'border-red-400 bg-red-50' : 'border-slate-200'"
        @input="onInput"
    />
</template>
