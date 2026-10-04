<script setup>
import Pagination from '@/Components/Data/Pagination.vue';
import Chart from '@/Components/Reports/Chart.vue';
import ReportTable from '@/Components/Reports/ReportTable.vue';
import StatCard from '@/Components/Data/StatCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDateTime, formatMoney, formatPercent, formatQty } from '@/lib/format';
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    project: { type: Object, default: null },
    report: { type: Object, required: true },
    result: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    period: { type: Object, required: true },
    can: { type: Object, required: true },
    exports: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const PARAMS = {
    project: 'project_id',
    client: 'client_id',
    vendor: 'vendor_id',
    subcontractor: 'subcontractor_id',
    warehouse: 'warehouse_id',
    category: 'category_id',
    material: 'material_id',
    assignee: 'assignee_id',
};

const state = reactive({
    fy: props.filters.fy ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    sort: props.filters.sort ?? '',
    dir: props.filters.dir ?? 'asc',
    per_page: String(props.filters.per_page ?? 50),
    ...Object.fromEntries((props.report.filters ?? []).map((filter) => {
        const key = PARAMS[filter] ?? filter;

        return [key, props.filters[key] != null ? String(props.filters[key]) : ''];
    })),
    ...(props.options.project ? { project_id: props.filters.project_id != null ? String(props.filters.project_id) : '' } : {}),
});

function apply() {
    const query = Object.fromEntries(Object.entries(state).filter(([, value]) => value !== '' && value !== null));
    router.get(props.urls.self, query, { preserveState: true, preserveScroll: true, replace: true });
}

function exportReport(format) {
    const query = Object.fromEntries(Object.entries(state).filter(([, value]) => value !== '' && value !== null));
    window.location.href = `${props.urls.export}?${new URLSearchParams({ ...query, format }).toString()}`;
}

function cardValue(card) {
    if (card.type === 'money') {
        return formatMoney(card.value);
    }
    if (card.type === 'percent') {
        return formatPercent(card.value);
    }
    if (card.type === 'qty') {
        return formatQty(card.value);
    }

    return card.value ?? '—';
}

const selectClass = 'rounded-lg border-slate-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200';
</script>

<template>
    <component :is="project ? ProjectLayout : AppLayout" v-bind="project ? { project, active: 'reports', title: report.title } : { title: report.title }">
        <PageHeader :title="report.title" :subtitle="`${period.label}${project ? '' : ''}`" :back="urls.index">
            <template #actions>
                <AppButton v-if="can.export" variant="secondary" size="sm" icon="download" @click="exportReport('xlsx')">Excel</AppButton>
                <AppButton v-if="can.export" variant="secondary" size="sm" icon="download" @click="exportReport('pdf')">PDF</AppButton>
            </template>
        </PageHeader>
        <p class="mb-4 text-sm text-slate-500">{{ report.description }}</p>

        <AppCard class="mb-4" :padded="false">
            <form class="flex flex-col gap-2 p-3 sm:flex-row sm:flex-wrap sm:items-end" @submit.prevent="apply">
                <label class="text-xs text-slate-500">
                    Financial year
                    <select v-model="state.fy" :class="selectClass" class="mt-1 block w-full sm:w-36" @change="apply">
                        <option v-for="option in options.fy" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label v-if="report.period === 'range'" class="text-xs text-slate-500">
                    From
                    <input v-model="state.from" type="date" :class="selectClass" class="mt-1 block" @change="apply" />
                </label>
                <label v-if="report.period !== 'current'" class="text-xs text-slate-500">
                    {{ report.period === 'asof' ? 'As of' : 'To' }}
                    <input v-model="state.to" type="date" :class="selectClass" class="mt-1 block" @change="apply" />
                </label>
                <label v-if="options.project" class="text-xs text-slate-500">
                    Project
                    <select v-model="state.project_id" :class="selectClass" class="mt-1 block w-full sm:w-52" @change="apply">
                        <option value="">All visible projects</option>
                        <option v-for="option in options.project" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label v-for="filter in report.filters" :key="filter" class="text-xs text-slate-500">
                    {{ filter.replaceAll('_', ' ') }}
                    <select v-if="options[filter]" v-model="state[PARAMS[filter] ?? filter]" :class="selectClass" class="mt-1 block w-full capitalize sm:w-44" @change="apply">
                        <option value="">All</option>
                        <option v-for="option in options[filter]" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <input v-else v-model="state[filter]" type="search" :class="selectClass" class="mt-1 block w-full sm:w-40" placeholder="Search" @change="apply" />
                </label>
                <label v-if="options.sorts?.length" class="text-xs text-slate-500">
                    Sort
                    <select v-model="state.sort" :class="selectClass" class="mt-1 block w-full sm:w-40" @change="apply">
                        <option value="">Default</option>
                        <option v-for="option in options.sorts" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label class="text-xs text-slate-500">
                    Direction
                    <select v-model="state.dir" :class="selectClass" class="mt-1 block" @change="apply">
                        <option value="asc">Ascending</option>
                        <option value="desc">Descending</option>
                    </select>
                </label>
                <label class="text-xs text-slate-500">
                    Rows
                    <select v-model="state.per_page" :class="selectClass" class="mt-1 block" @change="apply">
                        <option v-for="size in options.per_page" :key="size" :value="String(size)">{{ size }}</option>
                    </select>
                </label>
            </form>
        </AppCard>

        <div v-if="result.cards?.length" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard v-for="card in result.cards" :key="card.key" :label="card.label" :value="cardValue(card)" :hint="card.hint" />
        </div>

        <div v-if="result.charts?.length" class="mb-4 grid gap-4 lg:grid-cols-2">
            <AppCard v-for="chart in result.charts" :key="chart.title">
                <Chart :type="chart.type" :title="chart.title" :categories="chart.categories" :series="chart.series" :money="!!chart.money" />
            </AppCard>
        </div>

        <AppCard :padded="false" class="mb-4">
            <ReportTable :columns="result.columns" :rows="result.rows" :totals="result.totals" />
            <Pagination v-if="result.pagination" :paginator="result.pagination" />
        </AppCard>

        <AppCard v-for="section in result.sections" :key="section.title" :title="section.title" :padded="false" class="mb-4">
            <ReportTable :columns="section.columns" :rows="section.rows" :totals="section.totals" />
        </AppCard>

        <AppCard v-if="result.notes?.length" title="Notes" class="mb-4">
            <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600">
                <li v-for="note in result.notes" :key="note">{{ note }}</li>
            </ul>
        </AppCard>

        <AppCard v-if="exports.length" title="Recent exports">
            <ul class="divide-y divide-line text-sm">
                <li v-for="file in exports" :key="file.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>{{ file.format?.toUpperCase() }} · {{ file.status }} · {{ formatDateTime(file.created_at) }}</span>
                    <a v-if="file.download_url" :href="file.download_url" class="font-medium text-brand-700 hover:underline">Download</a>
                    <span v-else-if="file.error" class="text-red-700">{{ file.error }}</span>
                </li>
            </ul>
        </AppCard>
    </component>
</template>
