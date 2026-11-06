<script setup>
import PdfActions from '@/Components/Pdf/PdfActions.vue';
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import AuditTrail from '@/Components/Audit/AuditTrail.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatDateTime, formatQty } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    project: { type: Object, required: true },
    pass: { type: Object, required: true },
    items: { type: Array, required: true },
    weighbridge: { type: Object, default: null },
    attachments: { type: Array, required: true },
    audit: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

function post(name) {
    router.post(route(name, [props.project.id, props.pass.id]), {}, { preserveScroll: true });
}
</script>

<template>
    <ProjectLayout :project="project" active="gate" :title="pass.pass_number">
        <AppCard :padded="false">
            <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                <div>
                    <Link :href="route('projects.gate-passes.index', project.id)" class="text-xs text-slate-500">Gate passes</Link>
                    <h2 class="font-mono text-lg font-semibold">{{ pass.pass_number }}</h2>
                    <p class="text-sm text-slate-600">{{ pass.direction_label }} · {{ formatDateTime(pass.passed_at) }} · {{ pass.purpose }}</p>
                    <p class="text-xs text-slate-500">{{ pass.party_name }}<template v-if="pass.vehicle_no"> · {{ pass.vehicle_no }}</template><template v-if="pass.driver_name"> · {{ pass.driver_name }}</template></p>
                    <p v-if="pass.returnable" class="text-xs text-amber-800">Returnable<template v-if="pass.expected_return_date"> · expected {{ formatDate(pass.expected_return_date) }}</template><template v-if="pass.returned_at"> · returned {{ formatDateTime(pass.returned_at) }}</template></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <StatusBadge :status="pass.status" :label="pass.status_label" />
                    <PdfActions
                        :title="`${pass.pass_number}.pdf`"
                        :view-url="route('projects.gate-passes.pdf.view', [project.id, pass.id])"
                        :download-url="route('projects.gate-passes.pdf', [project.id, pass.id])"
                    />
                    <AppButton v-if="can.update" size="sm" variant="secondary" :href="route('projects.gate-passes.edit', [project.id, pass.id])">Edit</AppButton>
                    <AppButton v-if="can.submit" size="sm" @click="post('projects.gate-passes.submit')">Submit</AppButton>
                    <AppButton v-if="can.approve" size="sm" @click="post('projects.gate-passes.approve')">Approve</AppButton>
                    <AppButton v-if="can.verify" size="sm" @click="post('projects.gate-passes.verify')">Gate verify</AppButton>
                    <AppButton v-if="can.return" size="sm" @click="post('projects.gate-passes.returned')">Mark returned</AppButton>
                </div>
            </div>
        </AppCard>
        <AppCard title="Lines" class="mt-4" :padded="false">
            <ul class="divide-y divide-line text-sm">
                <li v-for="item in items" :key="item.id" class="px-4 py-3">
                    <div class="font-medium">{{ item.description }}</div>
                    <div class="text-xs text-slate-500">{{ item.item_type_label }}<template v-if="item.material"> · {{ item.material }}</template><template v-if="item.equipment"> · {{ item.equipment }}</template><template v-if="item.scrap_type"> · {{ item.scrap_type }}</template></div>
                    <div class="mt-1 tabular">{{ formatQty(item.quantity) }} {{ item.unit }}<template v-if="item.weight"> · {{ formatQty(item.weight) }}</template><template v-if="item.destination"> · {{ item.destination }}</template></div>
                </li>
            </ul>
        </AppCard>
        <AppCard v-if="weighbridge" title="Weighbridge" class="mt-4">
            <p class="text-sm">Gross {{ formatQty(weighbridge.gross_weight) }} · Tare {{ formatQty(weighbridge.tare_weight) }} · Net <strong class="tabular">{{ formatQty(weighbridge.net_weight) }} {{ weighbridge.weight_unit }}</strong></p>
            <p v-if="weighbridge.slip_no" class="text-xs text-slate-500">Slip {{ weighbridge.slip_no }}</p>
        </AppCard>
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <AttachmentPanel :attachments="attachments" attachable-type="gate_pass" :attachable-id="pass.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Challan, photo, authority letter…" />
            <AttachmentPanel v-if="weighbridge" :attachments="weighbridge.attachments || []" attachable-type="weighbridge_receipt" :attachable-id="weighbridge.id" :can-upload="can.attach" :can-delete="can.attach" title="Weighbridge slip" />
            <AuditTrail :entries="audit" />
        </div>
    </ProjectLayout>
</template>
