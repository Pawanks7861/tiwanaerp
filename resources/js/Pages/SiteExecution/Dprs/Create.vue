<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatQty } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    date: { type: String, required: true },
    today: { type: String, required: true },
    existing: { type: Object, default: null },
    diaries: { type: Array, required: true },
    preview: { type: Object, required: true },
    members: { type: Array, required: true },
    defaultEngineer: { type: Number, default: null },
});

const form = useForm({ dpr_date: props.date, engineer_id: props.defaultEngineer, remarks: '' });
const approved = computed(() => props.diaries.filter((d) => d.status === 'approved'));
const pending = computed(() => props.diaries.filter((d) => d.status !== 'approved'));

function changeDate(value) {
    form.dpr_date = value;
    if (value) {
        router.get(route('projects.dprs.create', props.project.id), { date: value }, { preserveState: true, preserveScroll: true, replace: true, only: ['date', 'existing', 'diaries', 'preview'] });
    }
}

const submit = () => form.post(route('projects.dprs.store', props.project.id));
</script>

<template>
    <ProjectLayout :project="project" active="dprs" title="New DPR">
        <form class="space-y-4" @submit.prevent="submit">
            <AppCard title="New daily progress report" subtitle="The DPR is assembled from the approved diaries of the date. You can adjust lines afterwards while it is a draft.">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormInput :model-value="form.dpr_date" type="date" label="DPR date" required :max="today" :error="form.errors.dpr_date" @update:model-value="changeDate" />
                    <SearchSelect v-model="form.engineer_id" label="Site engineer" :options="members" :error="form.errors.engineer_id" />
                    <FormInput v-model="form.remarks" label="Remarks" maxlength="5000" class="sm:col-span-2 lg:col-span-1" :error="form.errors.remarks" />
                </div>
                <div v-if="existing" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    <Link :href="route('projects.dprs.show', [project.id, existing.id])" class="font-mono font-medium underline">{{ existing.dpr_number }}</Link> already covers {{ formatDate(date) }}. Open it to refresh or correct it.
                </div>
            </AppCard>

            <AppCard :title="`Site diaries of ${formatDate(date)}`" :padded="false">
                <ul v-if="diaries.length" class="divide-y divide-line text-sm">
                    <li v-for="d in diaries" :key="d.id" class="flex items-center justify-between gap-2 px-4 py-2">
                        <div>
                            <Link :href="route('projects.site-diaries.show', [project.id, d.id])" class="font-medium text-brand-700 hover:underline">{{ d.site ?? 'Diary #' + d.id }}</Link>
                            <div class="text-xs text-slate-500">{{ d.created_by }} · {{ d.work_items_count }} work line(s)</div>
                        </div>
                        <StatusBadge :status="d.status" :label="d.status_label" />
                    </li>
                </ul>
                <p v-else class="px-4 py-3 text-sm text-slate-500">No site diary was recorded for this date.</p>
                <p v-if="pending.length" class="border-t border-line bg-slate-50 px-4 py-2 text-xs text-slate-600">
                    {{ pending.length }} diary(ies) not approved yet are left out. Approve them first, or refresh the DPR later.
                </p>
            </AppCard>

            <AppCard v-if="approved.length" title="Preview" :subtitle="`${preview.items.length} work line(s) · ${preview.headcount} labour headcount · ${preview.equipment_count} equipment · ${preview.material_count} material line(s)`" :padded="false">
                <ul class="divide-y divide-line text-sm">
                    <li v-for="(item, i) in preview.items" :key="i" class="flex items-center justify-between gap-2 px-4 py-2">
                        <div class="min-w-0">
                            <div class="truncate text-slate-900">{{ item.description }}</div>
                            <div v-if="!item.linked" class="text-xs text-amber-700">Not linked to a task or BOQ item; it will not post progress.</div>
                        </div>
                        <div class="whitespace-nowrap tabular">{{ formatQty(item.executed_qty) }} {{ item.unit }}</div>
                    </li>
                </ul>
                <div v-if="preview.weather || preview.site_issues" class="space-y-1 border-t border-line px-4 py-3 text-xs text-slate-600">
                    <p v-if="preview.weather"><span class="font-medium">Weather:</span> {{ preview.weather }}</p>
                    <p v-if="preview.site_issues" class="whitespace-pre-line"><span class="font-medium">Issues:</span> {{ preview.site_issues }}</p>
                </div>
            </AppCard>

            <div class="flex justify-end gap-2">
                <AppButton variant="ghost" :href="route('projects.dprs.index', project.id)">Cancel</AppButton>
                <AppButton type="submit" :loading="form.processing" :disabled="!!existing || !approved.length">Create DPR</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
