<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatQty } from '@/lib/format';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    date: { type: String, required: true },
    today: { type: String, required: true },
    include_completed: { type: Boolean, default: false },
    existing: { type: Object, default: null },
    prefill: { type: Object, required: true },
    legacy_diaries: { type: Array, default: () => [] },
    members: { type: Array, required: true },
    defaultEngineer: { type: Number, default: null },
});

const showCompleted = ref(props.include_completed);

const form = useForm({
    dpr_date: props.date,
    engineer_id: props.defaultEngineer,
    remarks: '',
    include_completed: props.include_completed,
});

const visibleItems = computed(() =>
    showCompleted.value ? props.prefill.items : props.prefill.items.filter((i) => !isComplete(i)),
);

function isComplete(item) {
    const planned = Number(item.planned_qty ?? 0);
    const prev = Number(item.previous_executed_qty ?? 0);
    return planned > 0 && prev >= planned;
}

function changeDate(value) {
    form.dpr_date = value;
    reloadPrefill();
}

function toggleCompleted() {
    showCompleted.value = !showCompleted.value;
    form.include_completed = showCompleted.value;
    reloadPrefill();
}

function reloadPrefill() {
    if (!form.dpr_date) return;
    router.get(
        route('projects.dprs.create', props.project.id),
        { date: form.dpr_date, include_completed: showCompleted.value ? 1 : 0 },
        { preserveState: true, preserveScroll: true, replace: true, only: ['date', 'existing', 'prefill', 'legacy_diaries', 'include_completed'] },
    );
}

const submit = () => form.post(route('projects.dprs.store', props.project.id));
</script>

<template>
    <ProjectLayout :project="project" active="dprs" title="New DPR">
        <form class="space-y-4" @submit.prevent="submit">
            <AppCard title="New daily progress report" subtitle="Prefilled from WBS activities, with optional labour, equipment and material summaries for the date.">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormInput :model-value="form.dpr_date" type="date" label="DPR date" required :max="today" :error="form.errors.dpr_date" @update:model-value="changeDate" />
                    <SearchSelect v-model="form.engineer_id" label="Site engineer" :options="members" :error="form.errors.engineer_id" />
                    <FormInput v-model="form.remarks" label="Remarks" maxlength="5000" class="sm:col-span-2 lg:col-span-1" :error="form.errors.remarks" />
                </div>
                <label class="mt-3 flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-200" :checked="showCompleted" @change="toggleCompleted" />
                    Include completed activities
                </label>
                <div v-if="existing" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    <Link :href="route('projects.dprs.show', [project.id, existing.id])" class="font-mono font-medium underline">{{ existing.dpr_number }}</Link> already covers {{ formatDate(date) }}. Open it to refresh or correct it.
                </div>
            </AppCard>

            <AppCard v-if="legacy_diaries.length" :title="`Legacy site diaries · ${formatDate(date)}`" subtitle="Historical records only — not required for new DPRs." :padded="false">
                <ul class="divide-y divide-line text-sm">
                    <li v-for="d in legacy_diaries" :key="d.id" class="flex items-center justify-between gap-2 px-4 py-2">
                        <div>
                            <span class="font-medium text-slate-900">{{ d.site ?? 'Record #' + d.id }}</span>
                            <div class="text-xs text-slate-500">{{ d.created_by }} · {{ d.work_items_count }} work line(s)</div>
                        </div>
                        <StatusBadge :status="d.status" :label="d.status_label" />
                    </li>
                </ul>
            </AppCard>

            <AppCard
                title="Activity preview"
                :subtitle="`${visibleItems.length} line(s) · ${prefill.headcount} labour headcount · ${prefill.equipment_count} equipment · ${prefill.material_count} material line(s)`"
                :padded="false"
            >
                <div v-if="visibleItems.length" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-line text-xs">
                        <thead class="bg-slate-50 font-semibold text-slate-500 uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Work area</th>
                                <th class="px-3 py-2 text-left">Activity</th>
                                <th class="hidden px-3 py-2 text-left md:table-cell">Plan</th>
                                <th class="px-3 py-2 text-right">Previous</th>
                                <th class="px-3 py-2 text-right">Target</th>
                                <th class="px-3 py-2 text-right">Today</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(item, i) in visibleItems" :key="i">
                                <td class="px-3 py-2 text-slate-600">{{ item.work_area ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <div class="text-slate-900">{{ item.description }}</div>
                                    <div v-if="!item.linked" class="text-[11px] text-amber-700">Not linked to task/BOQ — will not post progress.</div>
                                </td>
                                <td class="hidden px-3 py-2 text-slate-500 md:table-cell">
                                    <span v-if="item.planned_start_date">{{ formatDate(item.planned_start_date) }}</span>
                                    <span v-if="item.planned_end_date"> – {{ formatDate(item.planned_end_date) }}</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular">{{ formatQty(item.previous_executed_qty ?? 0) }}</td>
                                <td class="px-3 py-2 text-right tabular text-slate-500">{{ item.today_target_qty != null ? formatQty(item.today_target_qty) : '—' }}</td>
                                <td class="px-3 py-2 text-right font-medium tabular">{{ formatQty(item.executed_qty) }} {{ item.unit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-4 py-3 text-sm text-slate-500">No open activities for this date. Turn on “Include completed activities” or create the DPR and add lines manually.</p>
            </AppCard>

            <div class="flex justify-end gap-2">
                <AppButton variant="ghost" :href="route('projects.dprs.index', project.id)">Cancel</AppButton>
                <AppButton type="submit" :loading="form.processing" :disabled="!!existing">Create DPR</AppButton>
            </div>
        </form>
    </ProjectLayout>
</template>
