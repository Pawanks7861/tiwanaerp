<script setup>
import Icon from '@/Components/UI/Icon.vue';
import { ref } from 'vue';

/**
 * Drop zone / picker. Emits the chosen File; uploading is done by the parent form.
 * On phones `capture` lets site staff take a photo directly.
 */
const props = defineProps({
    accept: { type: String, default: '.pdf,.jpg,.jpeg,.png,.webp,.xls,.xlsx,.csv,.doc,.docx,.dwg,.dxf' },
    maxMb: { type: Number, default: 25 },
    error: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['select']);
const input = ref(null);
const dragging = ref(false);
const localError = ref(null);

function pick(files) {
    localError.value = null;
    const file = files?.[0];
    if (!file) {
        return;
    }
    if (file.size > props.maxMb * 1024 * 1024) {
        localError.value = `The file is larger than ${props.maxMb} MB.`;
        return;
    }
    emit('select', file);
    if (input.value) {
        input.value.value = '';
    }
}

function onDrop(event) {
    dragging.value = false;
    if (!props.disabled) {
        pick(event.dataTransfer?.files);
    }
}
</script>

<template>
    <div>
        <button
            type="button"
            :disabled="disabled"
            class="flex w-full flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-4 py-5 text-center transition disabled:opacity-60"
            :class="dragging ? 'border-brand-400 bg-brand-50' : 'border-slate-300 hover:border-slate-400 hover:bg-slate-50'"
            @click="input?.click()"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <Icon name="paperclip" class="text-slate-400" />
            <span class="text-sm font-medium text-slate-700">Choose a file or drop it here</span>
            <span class="text-xs text-slate-500">PDF, images, Excel, Word or drawings · up to {{ maxMb }} MB</span>
        </button>
        <input ref="input" type="file" class="hidden" :accept="accept" @change="pick($event.target.files)" />
        <p v-if="localError || error" class="mt-1 text-xs text-red-600">{{ localError || error }}</p>
    </div>
</template>
