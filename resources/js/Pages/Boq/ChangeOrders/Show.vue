<script setup>
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatDateTime } from '@/lib/format';
import { Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    boq: { type: Object, required: true },
    change_order: { type: Object, required: true },
    comparison: { type: Object, required: true },
    can: { type: Object, required: true },
});

const rejectOpen = ref(false);
const rejectForm = useForm({ rejection_reason: '' });

function submit() {
    router.post(route('projects.boqs.change-orders.submit', [props.project.id, props.boq.id, props.change_order.id]));
}
function approve() {
    router.post(route('projects.boqs.change-orders.approve', [props.project.id, props.boq.id, props.change_order.id]));
}
function reject() {
    rejectForm.post(route('projects.boqs.change-orders.reject', [props.project.id, props.boq.id, props.change_order.id]), {
        onSuccess: () => (rejectOpen.value = false),
    });
}
function cancel() {
    router.post(route('projects.boqs.change-orders.cancel', [props.project.id, props.boq.id, props.change_order.id]));
}

const summary = props.comparison?.summary ?? {};
</script>

<template>
    <ProjectLayout :project="project" :title="change_order.change_order_number" active="boqs">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">{{ change_order.change_order_number }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ change_order.title }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <StatusBadge :status="change_order.status" :label="change_order.status_label" />
                    <span class="text-xs text-slate-500">v{{ change_order.from_version }} → v{{ change_order.to_version }}</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link :href="route('projects.boqs.change-orders.index', [project.id, boq.id])" class="text-sm font-medium text-brand-700 hover:underline">All change orders</Link>
                <a v-if="can.client_pdf" :href="route('projects.boqs.change-orders.pdf.client', [project.id, boq.id, change_order.id])" class="text-sm font-medium text-slate-700 hover:underline">Client PDF</a>
                <a v-if="can.internal_pdf" :href="route('projects.boqs.change-orders.pdf.internal', [project.id, boq.id, change_order.id])" class="text-sm font-medium text-slate-700 hover:underline">Internal PDF</a>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            <AppButton v-if="can.submit" size="sm" @click="submit">Submit</AppButton>
            <AppButton v-if="can.approve" size="sm" @click="approve">Approve</AppButton>
            <AppButton v-if="can.reject" size="sm" variant="secondary" @click="rejectOpen = true">Reject</AppButton>
            <AppButton v-if="can.cancel" size="sm" variant="ghost" class="text-red-600" @click="cancel">Cancel</AppButton>
            <Link
                :href="route('projects.boqs.revisions.compare', [project.id, boq.id]) + `?from=${change_order.from_boq_id}&to=${change_order.to_boq_id}`"
                class="text-sm font-medium text-brand-700"
            >Open full comparison</Link>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <AppCard title="Commercial Δ"><p class="text-lg font-semibold tabular">{{ formatMoney(summary.commercial_variance) }}</p></AppCard>
            <AppCard v-if="can.view_costs" title="Internal Δ"><p class="text-lg font-semibold tabular">{{ formatMoney(summary.internal_variance) }}</p></AppCard>
            <AppCard v-if="can.view_costs" title="Margin Δ"><p class="text-lg font-semibold tabular">{{ formatMoney(summary.margin_variance) }}</p></AppCard>
            <AppCard title="Line changes"><p class="text-lg font-semibold">{{ summary.modified ?? 0 }} modified</p></AppCard>
        </div>

        <AppCard title="Details">
            <p v-if="change_order.description" class="text-sm text-slate-700">{{ change_order.description }}</p>
            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Revision reason</dt><dd>{{ change_order.revision_reason || '—' }}</dd></div>
                <div><dt class="text-slate-500">Submitted</dt><dd>{{ change_order.submitted_at ? formatDateTime(change_order.submitted_at) : '—' }}</dd></div>
                <div><dt class="text-slate-500">Approved</dt><dd>{{ change_order.approved_at ? formatDateTime(change_order.approved_at) : '—' }}</dd></div>
            </dl>
        </AppCard>

        <div v-if="rejectOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-4 shadow-lg">
                <h3 class="text-base font-semibold">Reject change order</h3>
                <textarea v-model="rejectForm.rejection_reason" rows="3" class="mt-3 w-full rounded border border-line px-3 py-2 text-sm" />
                <p v-if="rejectForm.errors.rejection_reason" class="mt-1 text-xs text-red-600">{{ rejectForm.errors.rejection_reason }}</p>
                <div class="mt-4 flex justify-end gap-2">
                    <AppButton variant="secondary" @click="rejectOpen = false">Close</AppButton>
                    <AppButton :loading="rejectForm.processing" variant="danger" @click="reject">Reject</AppButton>
                </div>
            </div>
        </div>
    </ProjectLayout>
</template>