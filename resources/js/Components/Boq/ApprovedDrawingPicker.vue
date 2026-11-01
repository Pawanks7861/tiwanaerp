<script setup>
import EmptyState from '@/Components/UI/EmptyState.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import { formatDate } from '@/lib/format';
import { computed, ref } from 'vue';

const props = defineProps({
    drawings: { type: Array, required: true },
    modelValue: { type: Array, required: true },
    error: { type: String, default: null },
    errors: { type: Object, default: () => ({}) },
    emptyTitle: { type: String, default: 'No approved drawings' },
    emptyDescription: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const search = ref('');
const selected = computed(() => new Set(props.modelValue.map(Number)));
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) {
        return props.drawings;
    }

    return props.drawings.filter((drawing) => [drawing.drawing_number, drawing.title, drawing.revision_code, drawing.approved_by]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
        .includes(q));
});

function toggle(id) {
    const next = [...props.modelValue.map(Number)];
    const index = next.indexOf(Number(id));
    if (index >= 0) {
        next.splice(index, 1);
    } else {
        next.push(Number(id));
    }
    emit('update:modelValue', next);
}
</script>

<template>
    <div>
        <div v-if="drawings.length" class="mb-2">
            <input
                v-model="search"
                type="search"
                placeholder="Search drawing number, title or revision…"
                class="w-full rounded-lg border-line text-sm"
            >
        </div>
        <p v-if="error" class="mb-2 text-xs text-red-600">{{ error }}</p>
        <p v-for="(message, key) in errors" :key="key" class="mb-2 text-xs text-red-600">{{ message }}</p>
        <EmptyState
            v-if="!drawings.length"
            icon="document"
            :title="emptyTitle"
            :description="emptyDescription"
        >
            <slot name="empty" />
        </EmptyState>
        <EmptyState
            v-else-if="!filtered.length"
            icon="document"
            title="No matching drawings"
            description="Try a different drawing number or title."
        />
        <div v-else class="max-h-64 overflow-y-auto rounded-lg border border-line">
            <label
                v-for="drawing in filtered"
                :key="drawing.id"
                class="flex cursor-pointer items-start gap-3 border-b border-line px-3 py-2.5 last:border-b-0 hover:bg-slate-50"
            >
                <input
                    type="checkbox"
                    class="mt-1 rounded border-slate-300 text-brand-600"
                    :checked="selected.has(Number(drawing.id))"
                    @change="toggle(drawing.id)"
                >
                <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-semibold text-slate-900">{{ drawing.drawing_number }}</span>
                        <StatusBadge :status="drawing.status" :label="drawing.status_label" />
                        <span class="text-[11px] font-medium text-slate-500">{{ drawing.revision_code }}</span>
                    </span>
                    <span class="mt-0.5 block truncate text-sm text-slate-800">{{ drawing.title }}</span>
                    <span class="mt-0.5 block text-xs text-slate-500">
                        Approved
                        <template v-if="drawing.approved_at"> {{ formatDate(drawing.approved_at) }}</template>
                        <template v-if="drawing.approved_by"> by {{ drawing.approved_by }}</template>
                    </span>
                </span>
            </label>
        </div>
        <p class="mt-2 text-xs text-slate-500">{{ modelValue.length }} selected. A BOQ may use several related drawings.</p>
    </div>
</template>
