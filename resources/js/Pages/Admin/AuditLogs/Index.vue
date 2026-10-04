<script setup>
import Pagination from '@/Components/Data/Pagination.vue';
import AuditTrail from '@/Components/Audit/AuditTrail.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/format';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
});

const openId = ref(null);
const range = ref({ from: props.filters.from, to: props.filters.to, record_id: props.filters.record_id });

function applyRange() {
    const query = {
        search: props.filters.search || undefined,
        user_id: props.filters.user_id !== 'all' ? props.filters.user_id : undefined,
        auditable_type: props.filters.auditable_type !== 'all' ? props.filters.auditable_type : undefined,
        event: props.filters.event !== 'all' ? props.filters.event : undefined,
        project_id: props.filters.project_id !== 'all' ? props.filters.project_id : undefined,
        from: range.value.from || undefined,
        to: range.value.to || undefined,
        record_id: range.value.record_id || undefined,
    };
    router.get(route('admin.audit-logs.index'), query, { preserveState: true, replace: true });
}
</script>

<template>
    <AppLayout title="Audit logs">
        <PageHeader title="Audit logs" subtitle="Who changed what. Values are labelled here; the stored history is never edited." />

        <AppCard :padded="false">
            <FilterBar
                :filters="{ search: filters.search, user_id: filters.user_id, auditable_type: filters.auditable_type, event: filters.event, project_id: filters.project_id }"
                placeholder="Search event, record or user"
                :selects="[
                    { key: 'user_id', options: options.users },
                    { key: 'auditable_type', options: options.entities },
                    { key: 'event', options: options.events },
                    { key: 'project_id', options: options.projects },
                ]"
            />
            <form class="flex flex-wrap items-end gap-2 border-b border-line px-3 py-3" @submit.prevent="applyRange">
                <label class="text-xs text-slate-500">From<input v-model="range.from" type="date" class="mt-1 block rounded-lg border-slate-300 text-sm" /></label>
                <label class="text-xs text-slate-500">To<input v-model="range.to" type="date" class="mt-1 block rounded-lg border-slate-300 text-sm" /></label>
                <label class="text-xs text-slate-500">Record id<input v-model="range.record_id" type="number" min="1" class="mt-1 block w-28 rounded-lg border-slate-300 text-sm" /></label>
                <button type="submit" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white">Apply</button>
            </form>

            <div class="overflow-x-auto">
                <table v-if="logs.data.length" class="min-w-full divide-y divide-line text-sm">
                    <thead class="bg-slate-50 text-left text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-3 py-2">When</th>
                            <th class="px-3 py-2">User</th>
                            <th class="px-3 py-2">Event</th>
                            <th class="px-3 py-2">Record</th>
                            <th class="px-3 py-2">Id</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <template v-for="log in logs.data" :key="log.id">
                            <tr class="cursor-pointer hover:bg-slate-50" @click="openId = openId === log.id ? null : log.id">
                                <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(log.at) }}</td>
                                <td class="px-3 py-2">{{ log.user }}</td>
                                <td class="px-3 py-2">{{ log.event_label }}</td>
                                <td class="px-3 py-2">{{ log.entity }}</td>
                                <td class="px-3 py-2 tabular">{{ log.record_id }}</td>
                            </tr>
                            <tr v-if="openId === log.id">
                                <td colspan="5" class="bg-slate-50 px-3 py-2">
                                    <AuditTrail :entries="[{ ...log, subject: null }]" title="Changes" />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <p v-else class="p-5 text-sm text-slate-500">No audit entries match these filters.</p>
            </div>
            <Pagination :paginator="logs" />
        </AppCard>
    </AppLayout>
</template>
