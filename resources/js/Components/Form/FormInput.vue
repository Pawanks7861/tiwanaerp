<script setup>
import { onMounted, ref, useId } from 'vue';
import FormField from './FormField.vue';
import { inputClasses } from './inputClasses';
import { useFieldAttrs } from './useFieldAttrs';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    label: { type: String, default: null },
    error: { type: String, default: null },
    help: { type: String, default: null },
    required: { type: Boolean, default: false },
    type: { type: String, default: 'text' },
    multiline: { type: Boolean, default: false },
    rows: { type: Number, default: 3 },
    uppercase: { type: Boolean, default: false },
    autofocus: { type: Boolean, default: false },
});

const model = defineModel({ type: [String, Number, null], default: '' });
const id = useId();
const input = ref(null);
const { wrapperClass, controlAttrs } = useFieldAttrs();

function onInput(event) {
    model.value = props.uppercase ? event.target.value.toUpperCase() : event.target.value;
}

onMounted(() => props.autofocus && input.value?.focus());

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <FormField :class="wrapperClass" :label="label" :for="id" :error="error" :help="help" :required="required">
        <textarea
            v-if="multiline"
            :id="id"
            ref="input"
            v-bind="controlAttrs"
            :rows="rows"
            :value="model ?? ''"
            :class="inputClasses(error)"
            :aria-invalid="!!error"
            @input="onInput"
        />
        <input
            v-else
            :id="id"
            ref="input"
            v-bind="controlAttrs"
            :type="type"
            :value="model ?? ''"
            :required="required"
            :class="[inputClasses(error), uppercase ? 'uppercase' : '']"
            :aria-invalid="!!error"
            @input="onInput"
        />
    </FormField>
</template>
