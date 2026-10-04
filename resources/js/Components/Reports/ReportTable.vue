<script setup>
import CellValue from '@/Components/Data/CellValue.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    totals: { type: Object, default: null },
    emptyTitle: { type: String, default: 'No rows for these filters' },
});

const numeric = ['money', 'rate', 'qty', 'percent', 'number'];
</script>

<template>
    <div class="overflow-x-auto">
        <table v-if="rows.length || totals" class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        class="px-3 py-2 text-xs font-semibold tracking-wide text-slate-500 uppercase"
                        :class="numeric.includes(column.type) ? 'text-right' : 'text-left'"
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="(row, index) in rows" :key="row.id ?? index" :class="row.emphasis ? 'bg-amber-50/60' : ''">
                    <td v-for="column in columns" :key="column.key" class="px-3 py-2 whitespace-nowrap" :class="numeric.includes(column.type) ? 'text-right' : 'text-left'">
                        <Link v-if="column.key === columns[0].key && row.url" :href="row.url" class="font-medium text-brand-700 hover:underline">
                            <CellValue :type="numeric.includes(column.type) || column.type === 'date' ? column.type : 'text'" :value="row[column.key]" />
                        </Link>
                        <CellValue v-else :type="numeric.includes(column.type) || column.type === 'date' ? column.type : 'text'" :value="row[column.key]" />
                        <span v-if="column.key === columns[0].key && row.flag" class="ml-2 inline-flex rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">{{ row.flag }}</span>
                    </td>
                </tr>
            </tbody>
            <tfoot v-if="totals">
                <tr class="bg-slate-50 font-semibold">
                    <td v-for="column in columns" :key="column.key" class="px-3 py-2 whitespace-nowrap" :class="numeric.includes(column.type) ? 'text-right' : 'text-left'">
                        <CellValue v-if="totals[column.key] !== undefined" :type="numeric.includes(column.type) || column.type === 'date' ? column.type : 'text'" :value="totals[column.key]" />
                    </td>
                </tr>
            </tfoot>
        </table>
        <EmptyState v-else icon="document" :title="emptyTitle" description="Try a different financial year or clear a filter." />
    </div>
</template>
