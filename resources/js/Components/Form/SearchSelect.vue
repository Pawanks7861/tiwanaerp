<script setup>
import Icon from '@/Components/UI/Icon.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import FormField from './FormField.vue';
import { inputClasses } from './inputClasses';

/**
 * Searchable single select for longer lists (users, clients, items).
 * options: [{ value, label, description? }]
 * Dropdown is teleported to body so overflow parents (tables, drawers) do not clip it.
 */
const props = defineProps({
    label: { type: String, default: null },
    error: { type: String, default: null },
    help: { type: String, default: null },
    required: { type: Boolean, default: false },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Search…' },
    clearable: { type: Boolean, default: true },
    disabled: { type: Boolean, default: false },
});

const model = defineModel({ type: [String, Number, null], default: null });
const id = useId();
const root = ref(null);
const trigger = ref(null);
const search = ref(null);
const open = ref(false);
const query = ref('');
const highlighted = ref(0);
const panelStyle = ref({});

// Loose equality: Inertia often sends numeric ids while v-model may coerce to string.
const selected = computed(() => props.options.find((o) => o.value == model.value) ?? null);
const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    const list = q
        ? props.options.filter((o) => `${o.label} ${o.description ?? ''}`.toLowerCase().includes(q))
        : props.options;

    return list.slice(0, 100);
});

function positionPanel() {
    const el = trigger.value;
    if (! el) {
        return;
    }
    const rect = el.getBoundingClientRect();
    const width = Math.max(rect.width, 220);
    const left = Math.min(rect.left, window.innerWidth - width - 8);
    panelStyle.value = {
        position: 'fixed',
        top: `${Math.round(rect.bottom + 4)}px`,
        left: `${Math.round(Math.max(8, left))}px`,
        width: `${Math.round(width)}px`,
        zIndex: 80,
    };
}

async function toggle() {
    if (props.disabled) {
        return;
    }
    open.value = ! open.value;
    if (open.value) {
        query.value = '';
        highlighted.value = Math.max(0, filtered.value.findIndex((o) => o.value == model.value));
        positionPanel();
        await nextTick();
        search.value?.focus();
    }
}

function choose(option) {
    model.value = option.value;
    open.value = false;
}

function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        highlighted.value = Math.min(filtered.value.length - 1, highlighted.value + 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        highlighted.value = Math.max(0, highlighted.value - 1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const option = filtered.value[highlighted.value];
        option && choose(option);
    } else if (event.key === 'Escape') {
        open.value = false;
    }
}

const onClickOutside = (e) => {
    const inTrigger = root.value?.contains(e.target);
    const inPanel = e.target.closest?.('[data-search-select-panel]');
    if (! inTrigger && ! inPanel) {
        open.value = false;
    }
};

function onReposition() {
    if (open.value) {
        positionPanel();
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        window.addEventListener('scroll', onReposition, true);
        window.addEventListener('resize', onReposition);
    } else {
        window.removeEventListener('scroll', onReposition, true);
        window.removeEventListener('resize', onReposition);
    }
});

onMounted(() => document.addEventListener('mousedown', onClickOutside));
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onClickOutside);
    window.removeEventListener('scroll', onReposition, true);
    window.removeEventListener('resize', onReposition);
});
</script>

<template>
    <FormField :label="label" :for="id" :error="error" :help="help" :required="required">
        <div ref="root" class="relative">
            <button
                :id="id"
                ref="trigger"
                type="button"
                :disabled="disabled"
                :class="[inputClasses(error), 'flex items-center justify-between gap-2 text-left']"
                :aria-expanded="open"
                aria-haspopup="listbox"
                @click="toggle"
            >
                <span class="truncate" :class="selected ? 'text-slate-900' : 'text-slate-400'">
                    {{ selected?.label ?? placeholder }}
                </span>
                <span class="flex items-center gap-1 text-slate-400">
                    <span
                        v-if="clearable && selected && !disabled"
                        role="button"
                        tabindex="-1"
                        class="rounded p-0.5 hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Clear"
                        @click.stop="model = null"
                    >
                        <Icon name="close" :size="14" />
                    </span>
                    <Icon name="chevron-down" :size="16" />
                </span>
            </button>

            <Teleport to="body">
                <div
                    v-if="open"
                    data-search-select-panel
                    class="overflow-hidden rounded-lg border border-line bg-white shadow-lg"
                    :style="panelStyle"
                >
                    <div class="border-b border-line p-2">
                        <input
                            ref="search"
                            v-model="query"
                            type="text"
                            class="block w-full rounded-md border-slate-300 px-2.5 py-1.5 text-sm focus:border-brand-500 focus:ring-brand-200"
                            placeholder="Type to search…"
                            @keydown="onKeydown"
                            @input="highlighted = 0"
                        />
                    </div>
                    <ul class="max-h-60 overflow-y-auto py-1" role="listbox">
                        <li
                            v-for="(option, index) in filtered"
                            :key="option.value"
                            role="option"
                            :aria-selected="option.value == model"
                            class="cursor-pointer px-3 py-2 text-sm text-slate-800"
                            :class="index === highlighted ? 'bg-brand-50 text-brand-900' : 'hover:bg-slate-50'"
                            @mouseenter="highlighted = index"
                            @mousedown.prevent="choose(option)"
                        >
                            <div class="font-medium">{{ option.label }}</div>
                            <div v-if="option.description" class="text-xs text-slate-500">{{ option.description }}</div>
                        </li>
                        <li v-if="filtered.length === 0" class="px-3 py-3 text-sm text-slate-500">No matches</li>
                    </ul>
                </div>
            </Teleport>
        </div>
    </FormField>
</template>
