<script setup>
import { useId } from 'vue';
import FormField from './FormField.vue';
import { inputClasses } from './inputClasses';
import { useFieldAttrs } from './useFieldAttrs';

defineOptions({ inheritAttrs: false });

defineProps({
    label: { type: String, default: null },
    error: { type: String, default: null },
    help: { type: String, default: null },
    required: { type: Boolean, default: false },
    options: { type: Array, default: () => [] }, // [{ value, label }]
    placeholder: { type: String, default: 'Select…' },
});

const model = defineModel({ type: [String, Number, null], default: null });
const id = useId();
const { wrapperClass, controlAttrs } = useFieldAttrs();
</script>

<template>
    <FormField :class="wrapperClass" :label="label" :for="id" :error="error" :help="help" :required="required">
        <select :id="id" v-model="model" v-bind="controlAttrs" :class="[inputClasses(error), 'pr-8']" :aria-invalid="!!error">
            <option :value="null">{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
    </FormField>
</template>
