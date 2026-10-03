<script setup>
import Pagination from '@/Components/Data/Pagination.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import InventoryNav from '@/Components/Inventory/InventoryNav.vue';
import AppBadge from '@/Components/UI/AppBadge.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatQty, formatRate } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    rows: { type: Object, required: true },
    warehouses: { type: Array, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

const state = reactive({
    search: props.filters.search ?? '',
    warehouse: props.filters.warehouse ? Number(props.filters.warehouse) : null,
    material: props.filters.material ? Number(props.filters.material) : null,
    category: props.filters.category ? Number(props.filters.category) : null,
    low: !!Number(props.filters.low ?? 0),
});

function apply() {
    const query = Object.fromEntries(Object.entries({ ...state, low: state.low ? 1 : null }).filter(([, v]) => v !== '' && v !== null));
    router.get(route('projects.inventory.index', props.project.id), query, { preserveState: true, preserveScroll: true, replace: true });
}
let timer = null;
watch(
    () => state.search,
    () => {
        clearTimeout(timer);
        timer = setTimeout(apply, 350);
    },
);
watch(() => [state.warehouse, state.material, state.category, state.low], apply);

const ledgerHref = (row) => route('projects.inventory.ledger', { project: props.project.id, warehouse: row.warehouse_id, material: row.material_id });
</script>

<template>
    <ProjectLayout :project="project" active="inventory" title="Stock">
        <InventoryNav :project-id="project.id" active="stock" />

        <div v-if="warehouses.length" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <button
                v-for="w in warehouses"
                :key="w.id"
                type="button"
                class="rounded-xl border bg-white p-4 text-left shadow-sm transition hover:border-brand-300"
                :class="state.warehouse === w.id ? 'border-brand-500 ring-2 ring-brand-100' : 'border-line'"
                @click="state.warehouse = state.warehouse === w.id ? null : w.id"
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="truncate text-sm font-semibold text-slate-900">{{ w.name }}</p>
                    <AppBadge :color="w.central ? 'blue' : 'slate'">{{ w.central ? 'Central' : 'Site' }}</AppBadge>
                </div>
                <p class="mt-1 text-xs text-slate-500"><span class="font-mono">{{ w.code }}</span> · {{ w.items }} item(s) in stock</p>
                <p v-if="can.view_valuation" class="mt-2 text-lg font-semibold text-slate-900 tabular">{{ formatMoney(w.value) }}</p>
            </button>
        </div>

        <AppCard title="Stock on hand" subtitle="Book balances of this project's stores and the company's central stores." :padded="false">
            <div class="grid gap-2 border-b border-line p-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="relative lg:col-span-2">
                    <Icon name="search" :size="16" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="state.search"
                        type="search"
                        placeholder="Search item name or code…"
                        class="block w-full rounded-lg border-slate-300 py-2 pr-3 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200"
                    />
                </div>
                <SearchSelect v-model="state.warehouse" :options="options.warehouses" placeholder="All stores" />
                <SearchSelect v-model="state.category" :options="options.categories" placeholder="All categories" />
                <label class="flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm text-slate-700">
                    <input v-model="state.low" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-200" />
                    Low stock only
                </label>
            </div>

            <template v-if="rows.data.length">
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Item</th>
                                <th class="px-4 py-2.5 text-left">Store</th>
                                <th class="px-4 py-2.5 text-right">Quantity</th>
                                <th class="px-4 py-2.5 text-right">Reorder level</th>
                                <th v-if="can.view_valuation" class="px-4 py-2.5 text-right">Avg cost</th>
                                <th v-if="can.view_valuation" class="px-4 py-2.5 text-right">Value</th>
                                <th class="px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in rows.data" :key="`${row.warehouse_id}-${row.material_id}`" class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900">{{ row.name }}</div>
                                    <div class="text-xs text-slate-500"><span class="font-mono">{{ row.code }}</span><template v-if="row.category"> · {{ row.category }}</template></div>
                                </td>
                                <td class="px-4 py-3">{{ row.warehouse }} <AppBadge v-if="row.central" color="blue" class="ml-1">Central</AppBadge></td>
                                <td class="px-4 py-3 text-right font-semibold tabular" :class="row.low ? 'text-red-700' : 'text-slate-900'">
                                    {{ formatQty(row.quantity) }} <span class="font-normal text-slate-500">{{ row.unit }}</span>
                                </td>
                                <td class="px-4 py-3 text-right text-slate-500 tabular">
                                    <span v-if="row.low" class="mr-1 inline-flex items-center gap-0.5 text-xs font-medium text-red-700"><Icon name="warning" :size="12" />Low</span>
                                    {{ Number(row.reorder_level) ? formatQty(row.reorder_level) : '—' }}
                                </td>
                                <td v-if="can.view_valuation" class="px-4 py-3 text-right tabular">{{ formatRate(row.avg_cost) }}</td>
                                <td v-if="can.view_valuation" class="px-4 py-3 text-right font-medium tabular">{{ formatMoney(row.value) }}</td>
                                <td class="px-4 py-3 text-right"><Link :href="ledgerHref(row)" class="text-xs font-medium text-brand-700 hover:underline">Ledger</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="row in rows.data" :key="`m-${row.warehouse_id}-${row.material_id}`">
                        <Link :href="ledgerHref(row)" class="block px-4 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-slate-900">{{ row.name }}</p>
                                    <p class="text-xs text-slate-500">{{ row.warehouse }}<template v-if="row.central"> · Central</template></p>
                                </div>
                                <p class="shrink-0 text-right font-semibold tabular" :class="row.low ? 'text-red-700' : 'text-slate-900'">
                                    {{ formatQty(row.quantity) }} <span class="text-xs font-normal text-slate-500">{{ row.unit }}</span>
                                </p>
                            </div>
                            <div class="mt-1 flex justify-between text-xs text-slate-500">
                                <span><template v-if="row.low"><span class="font-medium text-red-700">Low</span> · </template>Reorder {{ Number(row.reorder_level) ? formatQty(row.reorder_level) : '—' }}</span>
                                <span v-if="can.view_valuation" class="tabular">{{ formatMoney(row.value) }}</span>
                            </div>
                        </Link>
                    </li>
                </ul>
            </template>
            <EmptyState
                v-else
                icon="archive"
                :title="state.low ? 'Nothing is low on stock' : 'No stock yet'"
                description="Approved goods receipts, transfers and opening-stock adjustments put material into the stores."
            />
            <Pagination :paginator="rows" />
        </AppCard>
    </ProjectLayout>
</template>
