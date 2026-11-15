<script setup>
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppDrawer from '@/Components/UI/AppDrawer.vue';
import StatCard from '@/Components/Data/StatCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney, formatQty, formatRate } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    boq: { type: Object, required: true },
    comparison: { type: Object, required: true },
    from_boq_id: { type: Number, required: true },
    to_boq_id: { type: Number, required: true },
    revisions: { type: Array, default: () => [] },
    can: { type: Object, required: true },
});

const statusFilter = ref('changed');
const search = ref('');
const drawer = ref(null);

const items = computed(() => props.comparison?.items ?? []);
const summary = computed(() => props.comparison?.summary ?? {});
const header = computed(() => props.comparison?.header ?? {});

const filtered = computed(() => {
    let rows = items.value;
    switch (statusFilter.value) {
        case 'added':
        case 'removed':
        case 'modified':
            rows = rows.filter((r) => r.status === statusFilter.value);
            break;
        case 'quantity':
            rows = rows.filter((r) => (r.change_types || []).some((t) => t.includes('Quantity')));
            break;
        case 'rate':
            rows = rows.filter((r) => (r.change_types || []).some((t) => t.includes('Rate') || t.includes('Commercial Rate')));
            break;
        case 'technical':
            rows = rows.filter((r) => r.impact === 'technical' || r.impact === 'mixed');
            break;
        case 'commercial':
            rows = rows.filter((r) => r.impact === 'commercial' || r.impact === 'mixed');
            break;
        case 'cost_increase':
            rows = rows.filter((r) => Number(r.internal_cost_difference || r.commercial_difference || 0) > 0);
            break;
        case 'cost_decrease':
            rows = rows.filter((r) => Number(r.internal_cost_difference || r.commercial_difference || 0) < 0);
            break;
        default:
            break;
    }
    const term = search.value.trim().toLowerCase();
    if (term) {
        rows = rows.filter((r) => `${r.item_code ?? ''} ${r.description ?? ''}`.toLowerCase().includes(term));
    }
    return rows;
});

function statusBadge(row) {
    if (row.status === 'added') return { label: 'Added', class: 'bg-emerald-100 text-emerald-800' };
    if (row.status === 'removed') return { label: 'Removed', class: 'bg-rose-100 text-rose-800' };
    return { label: 'Modified', class: 'bg-sky-100 text-sky-900' };
}

function impactBadge(row) {
    if (row.impact === 'mixed') return { label: 'Mixed', class: 'bg-violet-100 text-violet-800' };
    if (row.impact === 'commercial') return { label: 'Commercial', class: 'bg-amber-100 text-amber-900' };
    if (row.impact === 'technical') return { label: 'Technical', class: 'bg-slate-200 text-slate-800' };
    if (row.impact === 'description') return { label: 'Description', class: 'bg-slate-100 text-slate-700' };
    return null;
}

function reload(fromId, toId) {
    router.get(route('projects.boqs.revisions.compare', [props.project.id, props.boq.id]), { from: fromId, to: toId }, { preserveScroll: true });
}

function createChangeOrder() {
    router.post(route('projects.boqs.change-orders.store', [props.project.id, props.boq.id]), {
        from: props.from_boq_id,
        to: props.to_boq_id,
    });
}
</script>

<template>
    <ProjectLayout :project="project" :title="`Compare ${boq.boq_number}`" active="boqs">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-slate-900">Revision comparison</h1>
                <p class="mt-1 text-sm text-slate-500">
                    {{ header.previous?.label }} → {{ header.current?.label }}
                    <span v-if="header.current_is_draft" class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-900">Draft Current Values</span>
                </p>
                <p v-if="header.revision_reason" class="mt-1 text-xs text-slate-500">Reason: {{ header.revision_reason }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link :href="route('projects.boqs.show', [project.id, boq.id])" class="text-sm font-medium text-brand-700 hover:underline">Back to BOQ</Link>
                <AppButton v-if="can.create_change_order" size="sm" @click="createChangeOrder">Create change order draft</AppButton>
            </div>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Added" :value="summary.added ?? 0" />
            <StatCard label="Removed" :value="summary.removed ?? 0" />
            <StatCard label="Modified" :value="summary.modified ?? 0" />
            <StatCard label="Unchanged" :value="summary.unchanged ?? 0" />
            <StatCard label="Previous commercial" :value="formatMoney(summary.previous_commercial)" />
            <StatCard label="Current commercial" :value="formatMoney(summary.current_commercial)" />
            <StatCard label="Commercial Δ" :value="formatMoney(summary.commercial_variance)" />
            <StatCard v-if="can.view_costs" label="Internal Δ" :value="formatMoney(summary.internal_variance)" />
            <StatCard v-if="can.view_costs" label="Margin Δ" :value="formatMoney(summary.margin_variance)" />
            <StatCard label="Impact" :value="(summary.impact || 'none').toUpperCase()" />
        </div>

        <AppCard title="Filters" class="mb-4">
            <div class="flex flex-wrap gap-3 text-sm">
                <label class="text-slate-600">From
                    <select class="ml-1 rounded border border-line px-2 py-1" :value="from_boq_id" @change="reload(Number($event.target.value), to_boq_id)">
                        <option v-for="r in revisions" :key="r.id" :value="r.id">v{{ r.version }}</option>
                    </select>
                </label>
                <label class="text-slate-600">To
                    <select class="ml-1 rounded border border-line px-2 py-1" :value="to_boq_id" @change="reload(from_boq_id, Number($event.target.value))">
                        <option v-for="r in revisions" :key="r.id" :value="r.id">v{{ r.version }}</option>
                    </select>
                </label>
                <input v-model="search" type="search" placeholder="Search lines…" class="rounded border border-line px-2 py-1" />
                <select v-model="statusFilter" class="rounded border border-line px-2 py-1">
                    <option value="changed">Changed only</option>
                    <option value="added">Added</option>
                    <option value="removed">Removed</option>
                    <option value="modified">Modified</option>
                    <option value="quantity">Quantity change</option>
                    <option value="rate">Rate change</option>
                    <option value="technical">Technical change</option>
                    <option value="commercial">Commercial change</option>
                    <option value="cost_increase">Cost increase</option>
                    <option value="cost_decrease">Cost decrease</option>
                </select>
            </div>
        </AppCard>

        <div class="overflow-hidden rounded-xl border border-line bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-600">
                        <tr>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Item</th>
                            <th class="px-3 py-2 text-left">Unit</th>
                            <th class="px-3 py-2 text-right">Prev Qty</th>
                            <th class="px-3 py-2 text-right">Curr Qty</th>
                            <th class="px-3 py-2 text-right">Qty Δ</th>
                            <th class="px-3 py-2 text-right">Prev Rate</th>
                            <th class="px-3 py-2 text-right">Curr Rate</th>
                            <th class="px-3 py-2 text-right">Commercial Δ</th>
                            <th v-if="can.view_costs" class="px-3 py-2 text-right">Internal Δ</th>
                            <th class="px-3 py-2 text-left">Change type</th>
                            <th class="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in filtered" :key="row.line_uid" class="hover:bg-slate-50/60">
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase" :class="statusBadge(row).class">{{ statusBadge(row).label }}</span>
                                    <span
                                        v-if="impactBadge(row)"
                                        class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                                        :class="impactBadge(row).class"
                                    >{{ impactBadge(row).label }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <div class="font-medium text-slate-900">{{ row.description }}</div>
                                <div class="text-xs text-slate-500">{{ row.item_code || row.line_uid }}</div>
                            </td>
                            <td class="px-3 py-2 text-slate-700">{{ row.unit || '—' }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(row.previous_qty) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(row.current_qty) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ row.qty_difference }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatRate(row.previous_client_rate) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatRate(row.current_client_rate) }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatMoney(row.commercial_difference) }}</td>
                            <td v-if="can.view_costs" class="px-3 py-2 text-right tabular">{{ formatMoney(row.internal_cost_difference) }}</td>
                            <td class="px-3 py-2 text-xs text-slate-600">{{ (row.change_types || []).join(', ') || '—' }}</td>
                            <td class="px-3 py-2 text-right">
                                <button type="button" class="text-xs font-medium text-brand-700" @click="drawer = row">Details</button>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td :colspan="can.view_costs ? 12 : 11" class="px-3 py-6 text-center text-slate-500">No matching changes.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AppDrawer :show="!!drawer" title="Item comparison detail" @close="drawer = null">
            <template v-if="drawer">
                <p class="mb-3 text-sm text-slate-600">{{ drawer.description }}</p>
                <div class="mb-3 flex flex-wrap gap-1">
                    <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase" :class="statusBadge(drawer).class">{{ statusBadge(drawer).label }}</span>
                    <span
                        v-if="impactBadge(drawer)"
                        class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                        :class="impactBadge(drawer).class"
                    >{{ impactBadge(drawer).label }}</span>
                </div>
                <ul class="mb-4 space-y-1 text-sm text-slate-700">
                    <li v-for="(t, i) in drawer.change_types ?? []" :key="i">{{ t }}</li>
                </ul>

                <section v-if="drawer.details?.measurements?.length" class="mb-4">
                    <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Measurement</h4>
                    <div v-for="(m, i) in drawer.details.measurements" :key="i" class="mb-1 text-sm text-slate-700">
                        {{ m.description || 'Line' }}: {{ m.previous_qty }} → {{ m.current_qty }} ({{ m.difference }})
                    </div>
                </section>
                <section v-if="can.view_costs && drawer.details?.materials?.length" class="mb-4">
                    <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Materials</h4>
                    <div v-for="(m, i) in drawer.details.materials" :key="i" class="mb-1 text-sm text-slate-700">
                        {{ m.name || m.description }} · qty {{ m.qty_variance ?? m.qty_difference }} · cost {{ formatMoney(m.cost_variance ?? m.amount_difference) }}
                    </div>
                </section>
                <section v-if="can.view_costs && drawer.details?.labour?.length" class="mb-4">
                    <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Labour</h4>
                    <div v-for="(m, i) in drawer.details.labour" :key="i" class="mb-1 text-sm text-slate-700">
                        {{ m.name || m.description }} · {{ formatMoney(m.amount_difference) }}
                    </div>
                </section>
                <section v-if="can.view_costs && drawer.details?.bbs?.length" class="mb-4">
                    <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">BBS</h4>
                    <div v-for="(m, i) in drawer.details.bbs" :key="i" class="mb-1 text-sm text-slate-700">
                        Ø{{ m.diameter_mm }} · {{ m.previous_mt }} → {{ m.current_mt }} MT
                    </div>
                </section>
                <section v-if="drawer.details?.planning?.length" class="mb-4">
                    <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Planning</h4>
                    <div v-for="(m, i) in drawer.details.planning" :key="i" class="mb-1 text-sm text-slate-700">
                        {{ m.activity || m.task_name || 'Map' }} · {{ m.previous_allocation }} → {{ m.current_allocation }}
                    </div>
                </section>
                <dl class="grid gap-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Variation class</dt><dd>{{ drawer.variation_class || drawer.details?.variation_class || '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Commercial Δ</dt><dd class="tabular">{{ formatMoney(drawer.commercial_difference) }}</dd></div>
                    <div v-if="can.view_costs" class="flex justify-between"><dt class="text-slate-500">Internal Δ</dt><dd class="tabular">{{ formatMoney(drawer.internal_cost_difference) }}</dd></div>
                </dl>
            </template>
        </AppDrawer>
    </ProjectLayout>
</template>
