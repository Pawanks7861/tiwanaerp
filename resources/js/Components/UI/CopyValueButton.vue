<script setup>
import Icon from '@/Components/UI/Icon.vue';
import { copyText } from '@/lib/clipboard';
import { toast } from '@/lib/toast';
import { ref } from 'vue';

const props = defineProps({
    value: { type: [String, Number], default: '' },
    title: { type: String, default: 'Copy' },
    successMessage: { type: String, default: 'Copied' },
    emptyMessage: { type: String, default: 'Nothing to copy' },
    label: { type: String, default: null },
    size: { type: Number, default: 14 },
});

const busy = ref(false);

async function copy() {
    if (busy.value) {
        return;
    }

    const text = props.value == null ? '' : String(props.value).trim();
    if (text === '') {
        toast.error(props.emptyMessage);

        return;
    }

    busy.value = true;
    try {
        const ok = await copyText(text);
        if (ok) {
            toast.success(props.successMessage);
        } else {
            toast.error('Could not copy to clipboard');
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <button
        type="button"
        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 disabled:opacity-50"
        :title="title"
        :aria-label="title"
        :disabled="busy"
        @click.stop="copy"
    >
        <Icon name="clipboard" :size="size" class="shrink-0" />
        <span v-if="label" class="whitespace-nowrap">{{ label }}</span>
    </button>
</template>
