<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import MoneyInput from '@/Components/Form/MoneyInput.vue';
import QtyInput from '@/Components/Form/QtyInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import { computed } from 'vue';

/**
 * Renders a master's form from its server-side field definition.
 * The server re-validates and derives values (codes, GST splits, state from GSTIN).
 */
const props = defineProps({
    definition: { type: Object, required: true },
    form: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
    editing: { type: Boolean, default: false },
});

const INPUT_TYPES = { text: 'text', email: 'email', tel: 'tel', number: 'number', date: 'date' };

const visibleFields = computed(() =>
    props.definition.fields.filter((field) => {
        if (!field.showWhen) {
            return true;
        }

        return Object.entries(field.showWhen).every(([key, value]) => props.form[key] === value);
    }),
);

const sections = computed(() => {
    const groups = new Map();
    for (const field of visibleFields.value) {
        const name = field.section ?? '';
        if (!groups.has(name)) {
            groups.set(name, []);
        }
        groups.get(name).push(field);
    }

    return [...groups.entries()].map(([title, fields]) => ({ title, fields }));
});

function optionsFor(field) {
    const list = props.options[field.options] ?? [];
    if (!field.filterBy) {
        return list;
    }
    const parent = props.form[field.filterBy];

    return parent ? list.filter((o) => o[field.filterBy] === parent) : [];
}

function helpFor(field) {
    if (field.name === 'code' && props.definition.autoCode && !props.editing) {
        return 'Leave blank to generate automatically.';
    }

    return field.help ?? null;
}

// Codes are optional only when creating a master that numbers itself.
const isRequired = (field) => !!field.required || (field.name === 'code' && (props.editing || !props.definition.autoCode));
</script>

<template>
    <div class="space-y-6">
        <fieldset v-for="section in sections" :key="section.title">
            <legend v-if="section.title" class="mb-3 text-xs font-semibold tracking-wider text-slate-500 uppercase">{{ section.title }}</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <template v-for="field in section.fields" :key="field.name">
                    <div v-if="field.type === 'switch'" class="flex items-center sm:col-span-2">
                        <FormSwitch v-model="form[field.name]" :label="field.label" />
                    </div>

                    <SearchSelect
                        v-else-if="field.type === 'select' && optionsFor(field).length > 12"
                        v-model="form[field.name]"
                        :class="field.span === 'full' ? 'sm:col-span-2' : ''"
                        :label="field.label"
                        :options="optionsFor(field)"
                        :required="field.required"
                        :error="form.errors[field.name]"
                        :help="helpFor(field)"
                        :placeholder="`Select ${field.label.toLowerCase()}`"
                    />
                    <FormSelect
                        v-else-if="field.type === 'select'"
                        v-model="form[field.name]"
                        :class="field.span === 'full' ? 'sm:col-span-2' : ''"
                        :label="field.label"
                        :options="optionsFor(field)"
                        :required="field.required"
                        :error="form.errors[field.name]"
                        :help="helpFor(field)"
                    />

                    <MoneyInput
                        v-else-if="field.type === 'money'"
                        v-model="form[field.name]"
                        :label="field.label"
                        :required="field.required"
                        :error="form.errors[field.name]"
                        :help="helpFor(field)"
                    />
                    <DecimalInput
                        v-else-if="field.type === 'rate'"
                        v-model="form[field.name]"
                        :decimals="4"
                        prefix="₹"
                        :label="field.label"
                        :required="field.required"
                        :error="form.errors[field.name]"
                        :help="helpFor(field)"
                    />
                    <DecimalInput
                        v-else-if="field.type === 'percent'"
                        v-model="form[field.name]"
                        :decimals="4"
                        suffix="%"
                        :label="field.label"
                        :required="field.required"
                        :error="form.errors[field.name]"
                        :help="helpFor(field)"
                    />
                    <QtyInput
                        v-else-if="field.type === 'qty'"
                        v-model="form[field.name]"
                        :label="field.label"
                        :required="field.required"
                        :error="form.errors[field.name]"
                        :help="helpFor(field)"
                    />

                    <FormInput
                        v-else
                        v-model="form[field.name]"
                        :class="field.span === 'full' || field.type === 'textarea' ? 'sm:col-span-2' : ''"
                        :label="field.label"
                        :type="INPUT_TYPES[field.type] ?? 'text'"
                        :multiline="field.type === 'textarea'"
                        :rows="2"
                        :required="isRequired(field)"
                        :uppercase="!!field.uppercase"
                        :maxlength="field.maxlength"
                        :placeholder="field.placeholder"
                        :inputmode="field.type === 'number' ? 'numeric' : undefined"
                        :error="form.errors[field.name]"
                        :help="helpFor(field)"
                    />
                </template>
            </div>
        </fieldset>
    </div>
</template>
