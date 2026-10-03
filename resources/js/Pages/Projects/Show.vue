<script setup>
import StatCard from '@/Components/Data/StatCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDropdown from '@/Components/UI/AppDropdown.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    transitions: { type: Array, required: true },
    can: { type: Object, required: true },
});

const pending = ref(null);
const processing = ref(false);

function changeStatus() {
    processing.value = true;
    router.patch(
        route('projects.status', props.project.id),
        { status: pending.value.value },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
                pending.value = null;
            },
        },
    );
}

const details = computed(() => {
    const p = props.project;

    return [
        ['Client', p.client ? `${p.client.company_name} (${p.client.code})` : null],
        ['Project manager', p.manager?.name],
        ['Project type', p.project_type ? p.project_type.replace(/^\w/, (c) => c.toUpperCase()) : null],
        ['State (place of supply)', p.state_name],
        ['Address', [p.address, p.city].filter(Boolean).join(', ') || null],
    ];
});
</script>

<template>
    <ProjectLayout :project="project" active="overview" title="Overview">
        <template #actions>
            <AppDropdown v-if="can.changeStatus && transitions.length" width="w-48">
                <template #trigger>
                    <AppButton variant="secondary" size="sm" icon="chevron-down">Status</AppButton>
                </template>
                <div class="py-1">
                    <button
                        v-for="t in transitions"
                        :key="t.value"
                        type="button"
                        class="block w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                        @click="pending = t"
                    >
                        Mark as {{ t.label }}
                    </button>
                </div>
            </AppDropdown>
            <AppButton v-if="can.update" :href="route('projects.edit', project.id)" variant="secondary" size="sm" icon="pencil">Edit</AppButton>
        </template>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <StatCard label="Sites" :value="project.sites_count ?? 0" icon="map-pin" tone="brand" :href="route('projects.sites.index', project.id)" />
            <StatCard label="Team members" :value="project.members_count ?? 0" icon="users" tone="green" :href="route('projects.team.index', project.id)" />
            <StatCard label="Start" :value="formatDate(project.start_date)" icon="calendar" tone="slate" />
            <StatCard
                label="Expected completion"
                :value="formatDate(project.expected_end_date)"
                icon="calendar"
                tone="orange"
                :hint="project.actual_end_date ? `Completed ${formatDate(project.actual_end_date)}` : null"
            />
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <AppCard title="Project information" class="lg:col-span-2">
                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div v-for="[label, value] in details" :key="label">
                        <dt class="text-xs font-medium text-slate-500 uppercase">{{ label }}</dt>
                        <dd class="mt-0.5 text-sm text-slate-800">{{ value ?? '—' }}</dd>
                    </div>
                    <div v-if="project.description" class="sm:col-span-2">
                        <dt class="text-xs font-medium text-slate-500 uppercase">Description</dt>
                        <dd class="mt-0.5 text-sm whitespace-pre-line text-slate-800">{{ project.description }}</dd>
                    </div>
                </dl>
            </AppCard>

            <div class="space-y-4">
                <AppCard v-if="can.viewFinancials" title="Contract">
                    <p class="text-xs text-slate-500 uppercase">Contract value</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900 tabular">{{ formatMoney(project.contract_value) }}</p>
                </AppCard>
                <AppCard title="Coming next">
                    <p class="text-sm text-slate-600">
                        BOQ, planning, daily progress, procurement and billing will appear in this project as each module is released.
                    </p>
                    <Link :href="route('projects.team.index', project.id)" class="mt-3 inline-block text-sm font-medium text-brand-600 hover:text-brand-700">
                        Manage the project team →
                    </Link>
                </AppCard>
            </div>
        </div>

        <ConfirmDialog
            :show="!!pending"
            :title="`Mark project as ${pending?.label}?`"
            :message="pending?.value === 'cancelled' ? 'Cancelling a project stops all further work on it.' : 'The project status will be updated for everyone.'"
            :confirm-label="`Mark as ${pending?.label ?? ''}`"
            :danger="pending?.value === 'cancelled'"
            :processing="processing"
            @close="pending = null"
            @confirm="changeStatus"
        />
    </ProjectLayout>
</template>
