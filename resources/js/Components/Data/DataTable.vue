<script setup>
import EmptyState from '@/Components/UI/EmptyState.vue';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import CellValue from './CellValue.vue';

/**
 * Table on desktop, stacked cards on phones (ResponsiveList behaviour built in).
 * columns: [{ key, label, type?, align?, mobile?: false }]
 * Slots: `cell-<key>` ({ row, value }), `actions` ({ row }), `empty`.
 */
const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    rowKey: { type: String, default: 'id' },
    rowHref: { type: Function, default: null },
    emptyTitle: { type: String, default: 'Nothing here yet' },
    emptyDescription: { type: String, default: null },
    emptyIcon: { type: String, default: 'folder' },
});

const numericTypes = ['money', 'rate', 'qty', 'percent'];
const alignOf = (col) => col.align ?? (numericTypes.includes(col.type) ? 'right' : 'left');
const titleColumn = computed(() => props.columns[0]);
const mobileColumns = computed(() => props.columns.slice(1).filter((c) => c.mobile !== false));

function open(row) {
    if (props.rowHref) {
        router.visit(props.rowHref(row));
    }
}
</script>

<template>
    <div>
        <template v-if="rows.length">
            <!-- Desktop -->
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th
                                v-for="col in columns"
                                :key="col.key"
                                scope="col"
                                class="px-4 py-2.5 text-xs font-semibold tracking-wide whitespace-nowrap text-slate-500 uppercase"
                                :class="alignOf(col) === 'right' ? 'text-right' : 'text-left'"
                            >
                                {{ col.label }}
                            </th>
                            <th v-if="$slots.actions" class="px-4 py-2.5"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line bg-white">
                        <tr
                            v-for="row in rows"
                            :key="row[rowKey]"
                            class="transition hover:bg-slate-50"
                            :class="rowHref ? 'cursor-pointer' : ''"
                            @click="open(row)"
                        >
                            <td
                                v-for="col in columns"
                                :key="col.key"
                                class="px-4 py-3 align-middle text-slate-700"
                                :class="alignOf(col) === 'right' ? 'text-right whitespace-nowrap' : ''"
                            >
                                <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                    <CellValue :type="col.type" :value="row[col.key]" />
                                </slot>
                            </td>
                            <td v-if="$slots.actions" class="px-4 py-3 text-right whitespace-nowrap" @click.stop>
                                <slot name="actions" :row="row" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile -->
            <ul class="divide-y divide-line md:hidden">
                <li v-for="row in rows" :key="row[rowKey]" class="px-4 py-3" :class="rowHref ? 'active:bg-slate-50' : ''" @click="open(row)">
                    <div class="font-medium text-slate-900">
                        <slot :name="`cell-${titleColumn.key}`" :row="row" :value="row[titleColumn.key]">
                            <CellValue :type="titleColumn.type" :value="row[titleColumn.key]" />
                        </slot>
                    </div>
                    <dl class="mt-1.5 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        <template v-for="col in mobileColumns" :key="col.key">
                            <dt class="text-slate-500">{{ col.label }}</dt>
                            <dd class="text-right text-slate-700">
                                <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                    <CellValue :type="col.type" :value="row[col.key]" />
                                </slot>
                            </dd>
                        </template>
                    </dl>
                    <div v-if="$slots.actions" class="mt-2 flex justify-end gap-2" @click.stop>
                        <slot name="actions" :row="row" />
                    </div>
                </li>
            </ul>
        </template>

        <slot v-else name="empty">
            <EmptyState :icon="emptyIcon" :title="emptyTitle" :description="emptyDescription" />
        </slot>
    </div>
</template>
