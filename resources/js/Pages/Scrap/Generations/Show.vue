<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import ApprovalActions from '@/Components/Procurement/ApprovalActions.vue';
import ScrapNav from '@/Components/Scrap/ScrapNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatQty } from '@/lib/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    generation: { type: Object, required: true },
    items: { type: Array, required: true },
    approval: { type: Object, default: null },
    attachments: { type: Array, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const confirming = ref(null);
const processing = ref(false);

function confirmAction() {
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    confirming.value === 'delete'
        ? router.delete(route('projects.scrap.generations.destroy', [props.project.id, props.generation.id]), options)
        : router.post(route('projects.scrap.generations.submit', [props.project.id, props.generation.id]), {}, options);
}
</script>

<template>
    <ProjectLayout :project="project" active="scrap" :title="generation.generation_number">
        <ScrapNav active="generations" scope="project" :project-id="project.id" />
        <AppCard :padded="false">
            <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <Link :href="route('projects.scrap.generations.index', project.id)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Scrap generations</Link>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 class="font-mono text-lg font-semibold">{{ generation.generation_number }}</h2>
                        <StatusBadge :status="generation.status" :label="generation.status_label" />
                    </div>
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                        <div><dt class="text-slate-500">Date</dt><dd>{{ formatDate(generation.generation_date) }}</dd></div>
                        <div><dt class="text-slate-500">Source</dt><dd>{{ generation.source_type_label }}</dd></div>
                        <div v-if="generation.department"><dt class="text-slate-500">Department</dt><dd>{{ generation.department }}</dd></div>
                        <div v-if="generation.location"><dt class="text-slate-500">Location</dt><dd>{{ generation.location }}</dd></div>
                    </dl>
                </div>
                <div class="flex flex-wrap gap-2">
                    <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('projects.scrap.generations.edit', [project.id, generation.id])">Edit</AppButton>
                    <AppButton v-if="can.submit" size="sm" @click="confirming = 'submit'">Submit</AppButton>
                    <AppButton v-if="can.approve" size="sm" @click="router.post(route('projects.scrap.generations.approve', [project.id, generation.id]), {}, { preserveScroll: true })">Approve</AppButton>
                    <AppButton v-if="can.delete" size="sm" variant="danger" @click="confirming = 'delete'">Delete</AppButton>
                </div>
            </div>
        </AppCard>
        <ApprovalActions v-if="approval" :approval="approval" class="mt-4" />
        <AppCard title="Lines" class="mt-4" :padded="false">
            <table class="w-full text-sm">
                <thead class="border-b border-line bg-slate-50 text-left text-xs text-slate-600">
                    <tr>
                        <th class="px-4 py-2">Type</th>
                        <th class="px-4 py-2">Description</th>
                        <th class="px-4 py-2 text-right">Qty</th>
                        <th class="px-4 py-2">Unit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="line in items" :key="line.id">
                        <td class="px-4 py-2">{{ line.scrap_type }}<span v-if="line.category" class="block text-xs text-slate-500">{{ line.category }}</span></td>
                        <td class="px-4 py-2">{{ line.description || '—' }}</td>
                        <td class="px-4 py-2 text-right tabular">{{ formatQty(line.quantity) }}</td>
                        <td class="px-4 py-2">{{ line.unit }}</td>
                    </tr>
                </tbody>
            </table>
        </AppCard>
        <AttachmentPanel v-if="can.attach || attachments.length" class="mt-4" attachable-type="scrap_generation" :attachable-id="generation.id" :attachments="attachments" :can-attach="can.attach" />
        <ConfirmDialog
            :show="!!confirming"
            :title="confirming === 'delete' ? 'Delete draft?' : 'Submit for approval?'"
            :confirm-label="confirming === 'delete' ? 'Delete' : 'Submit'"
            :danger="confirming === 'delete'"
            :processing="processing"
            @confirm="confirmAction"
            @close="confirming = null"
        />
    </ProjectLayout>
</template>
