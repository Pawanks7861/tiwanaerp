<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import ApprovalActions from '@/Components/Procurement/ApprovalActions.vue';
import ScrapNav from '@/Components/Scrap/ScrapNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatQty } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    adjustment: { type: Object, required: true },
    items: { type: Array, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const confirming = ref(false);
const processing = ref(false);

function submitDoc() {
    processing.value = true;
    router.post(route('projects.scrap.adjustments.submit', [props.project.id, props.adjustment.id]), {}, {
        preserveScroll: true,
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <ProjectLayout :project="project" active="scrap" :title="adjustment.adjustment_number">
        <ScrapNav active="adjustments" scope="project" :project-id="project.id" />
        <AppCard>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link :href="route('projects.scrap.adjustments.index', project.id)" class="text-xs text-slate-500">Scrap adjustments</Link>
                    <div class="mt-1 flex items-center gap-2">
                        <h2 class="font-mono text-lg font-semibold">{{ adjustment.adjustment_number }}</h2>
                        <StatusBadge :status="adjustment.status" :label="adjustment.status_label" />
                    </div>
                    <p class="mt-1 text-sm">{{ adjustment.direction?.toUpperCase() }} · {{ formatDate(adjustment.adjustment_date) }}</p>
                    <p class="text-sm text-slate-700">{{ adjustment.reason }}</p>
                </div>
                <div class="flex gap-2">
                    <AppButton v-if="can.update" size="sm" variant="secondary" :href="route('projects.scrap.adjustments.edit', [project.id, adjustment.id])">Edit</AppButton>
                    <AppButton v-if="can.submit" size="sm" :loading="processing" @click="submitDoc">Submit</AppButton>
                </div>
            </div>
        </AppCard>
        <ApprovalActions v-if="approval" :approval="approval" class="mt-4" />
        <AppCard title="Lines" class="mt-4" :padded="false">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-line">
                    <tr v-for="(line, i) in items" :key="i">
                        <td class="px-4 py-2">{{ line.scrap_type }}</td>
                        <td class="px-4 py-2 text-right tabular">{{ formatQty(line.quantity) }} {{ line.unit }}</td>
                    </tr>
                </tbody>
            </table>
        </AppCard>
        <AttachmentPanel v-if="can.attach || attachments.length" class="mt-4" attachable-type="scrap_adjustment" :attachable-id="adjustment.id" :attachments="attachments" :can-attach="can.attach" />
    </ProjectLayout>
</template>
