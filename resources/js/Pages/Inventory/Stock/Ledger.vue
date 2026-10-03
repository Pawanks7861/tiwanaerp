<script setup>
import Pagination from '@/Components/Data/Pagination.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import InventoryNav from '@/Components/Inventory/InventoryNav.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatDate, formatMoney, formatQty, formatRate } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    rows: { type: Object, required: true },
    filters: { type: Object, required: true },
    running: { type: Boolean, required: true },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

const state = reactive({
    warehouse: props.filters.warehouse ? Number(props.filters.warehouse) : null,
    material: props.filters.material ? Number(props.filters.material) : null,
    type: props.filters.type ?? null,
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
watch(
    () => ({ ...state }),
    () => {
        const query = Object.fromEntries(Object.entries(state).filter(([, v]) => v !== '' && v !== null));
        router.get(route('projects.inventory.ledger', props.project.id), query, { preserveState: true, preserveScroll: true, replace: true });
    },
);

const isIn = (row) => Number(row.qty_in) > 0;
</script>

<template>
    <ProjectLayout :project="project" active="inventory" title="Stock ledger">
        <InventoryNav :project-id="project.id" active="ledger" />
        <AppCard title="Stock ledger" subtitle="Every posted stock movement, newest first. Entries are never edited; corrections appear as reversals." :padded="false">
            <div class="grid gap-2 border-b border-line p-3 sm:grid-cols-2 lg:grid-cols-5">
                <SearchSelect v-model="state.warehouse" :options="options.warehouses" placeholder="All stores" />
                <SearchSelect v-model="state.material" :options="options.materials" placeholder="All items" />
                <FormSelect v-model="state.type" :options="options.types" placeholder="All movement types" />
                <input v-model="state.from" type="date" aria-label="From date" class="rounded-lg border-slate-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200" />
                <input v-model="state.to" type="date" aria-label="To date" class="rounded-lg border-slate-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200" />
            </div>
            <p v-if="!running" class="border-b border-line bg-slate-50 px-4 py-2 text-xs text-slate-600">
                <Icon name="info" :size="14" class="mr-1 inline align-text-bottom" />Choose one store and one item to see the running balance after each movement.
            </p>

            <template v-if="rows.data.length">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Date</th>
                                <th class="px-4 py-2.5 text-left">Movement</th>
                                <th class="px-4 py-2.5 text-left">Item / store</th>
                                <th class="px-4 py-2.5 text-right">In</th>
                                <th class="px-4 py-2.5 text-right">Out</th>
                                <th v-if="running" class="px-4 py-2.5 text-right">Balance</th>
                                <th v-if="can.view_valuation" class="px-4 py-2.5 text-right">Unit cost</th>
                                <th v-if="can.view_valuation" class="px-4 py-2.5 text-right">Value</th>
                                <th v-if="can.view_valuation && running" class="px-4 py-2.5 text-right">Balance value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in rows.data" :key="row.id" class="align-top">
                                <td class="px-4 py-3 whitespace-nowrap">{{ formatDate(row.txn_date) }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900">{{ row.type_label }}</div>
                                    <div class="text-xs text-slate-500">
                                        <Link v-if="row.reference?.url" :href="row.reference.url" class="font-mono text-brand-700 hover:underline">{{ row.reference.number }}</Link>
                                        <span v-else-if="row.other_project" class="italic">Another project</span>
                                        <template v-if="row.reverses_id"> · reverses #{{ row.reverses_id }}</template>
                                    </div>
                                    <div v-if="row.remarks" class="max-w-xs truncate text-xs text-slate-400" :title="row.remarks">{{ row.remarks }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-slate-900">{{ row.material?.name }}</div>
                                    <div class="text-xs text-slate-500">{{ row.warehouse }}</div>
                                </td>
                                <td class="px-4 py-3 text-right tabular" :class="isIn(row) ? 'font-medium text-emerald-700' : 'text-slate-300'">{{ isIn(row) ? formatQty(row.qty_in) : '—' }}</td>
                                <td class="px-4 py-3 text-right tabular" :class="!isIn(row) ? 'font-medium text-red-700' : 'text-slate-300'">{{ !isIn(row) ? formatQty(row.qty_out) : '—' }}</td>
                                <td v-if="running" class="px-4 py-3 text-right font-semibold tabular">{{ formatQty(row.running_qty) }} <span class="text-xs font-normal text-slate-500">{{ row.material?.unit }}</span></td>
                                <td v-if="can.view_valuation" class="px-4 py-3 text-right tabular">{{ formatRate(row.unit_cost) }}</td>
                                <td v-if="can.view_valuation" class="px-4 py-3 text-right tabular">{{ formatMoney(row.value) }}</td>
                                <td v-if="can.view_valuation && running" class="px-4 py-3 text-right font-medium tabular">{{ formatMoney(row.running_value) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="row in rows.data" :key="`m-${row.id}`" class="px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900">{{ row.type_label }}</p>
                                <p class="truncate text-xs text-slate-500">{{ formatDate(row.txn_date) }} · {{ row.material?.name }} · {{ row.warehouse }}</p>
                                <Link v-if="row.reference?.url" :href="row.reference.url" class="font-mono text-xs text-brand-700">{{ row.reference.number }}</Link>
                                <span v-else-if="row.other_project" class="text-xs text-slate-400 italic">Another project</span>
                            </div>
                            <div class="shrink-0 text-right tabular">
                                <p :class="isIn(row) ? 'text-emerald-700' : 'text-red-700'" class="font-semibold">{{ isIn(row) ? '+' + formatQty(row.qty_in) : '−' + formatQty(row.qty_out) }}</p>
                                <p v-if="running" class="text-xs text-slate-500">Bal. {{ formatQty(row.running_qty) }}</p>
                                <p v-if="can.view_valuation" class="text-xs text-slate-500">{{ formatMoney(row.value) }}</p>
                            </div>
                        </div>
                    </li>
                </ul>
            </template>
            <EmptyState v-else icon="archive" title="No stock movements" description="Movements appear here once goods receipts, issues, transfers, returns or adjustments are posted." />
            <Pagination :paginator="rows" />
        </AppCard>
    </ProjectLayout>
</template>
