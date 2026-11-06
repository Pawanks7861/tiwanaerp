<script setup>
import PdfViewer from '@/Components/Pdf/PdfViewer.vue';
import Icon from '@/Components/UI/Icon.vue';
import { ref } from 'vue';

defineProps({
    viewUrl: { type: String, required: true },
    downloadUrl: { type: String, required: true },
    title: { type: String, default: 'PDF' },
    canView: { type: Boolean, default: true },
    canDownload: { type: Boolean, default: true },
    canPrint: { type: Boolean, default: true },
});

const open = ref(false);
const autoPrint = ref(false);

const buttonClass = 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-white px-2.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 sm:px-3';

function view() {
    autoPrint.value = false;
    open.value = true;
}

function print() {
    autoPrint.value = true;
    open.value = true;
}
</script>

<template>
    <div v-if="canView || canDownload || canPrint" class="inline-flex max-w-full flex-wrap gap-1.5">
        <button v-if="canView" type="button" :class="buttonClass" aria-label="View PDF" title="View PDF" @click="view">
            <Icon name="eye" :size="14" />
            <span class="hidden sm:inline">View PDF</span>
        </button>
        <a v-if="canDownload" :href="downloadUrl" :class="buttonClass" aria-label="Download PDF" title="Download PDF">
            <Icon name="download" :size="14" />
            <span class="hidden sm:inline">Download PDF</span>
        </a>
        <button v-if="canPrint" type="button" :class="buttonClass" aria-label="Print PDF" title="Print PDF" @click="print">
            <Icon name="printer" :size="14" />
            <span class="hidden sm:inline">Print</span>
        </button>
        <PdfViewer :show="open" :view-url="viewUrl" :download-url="downloadUrl" :title="title" :auto-print="autoPrint" @close="open = false" />
    </div>
</template>
