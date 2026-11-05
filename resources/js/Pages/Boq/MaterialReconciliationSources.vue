<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatQty } from '@/lib/format';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    material: { type: Object, required: true },
    type: { type: String, required: true },
    documents: { type: Array, default: () => [] },
});

const title = computed(() => ({
    mr: 'Material Requests',
    po: 'Purchase Orders',
    grn: 'GRNs',
    issue: 'Material Issues',
    return: 'Material Returns',
}[props.type] || props.type));
</script>

<template>
    <ProjectLayout :project="project" title="Source Documents" active="material-reconciliation">
        <div class="mb-4">
            <Link
                :href="route('projects.material-reconciliation.show', [project.id, material.id])"
                class="text-xs font-medium text-slate-500 hover:text-slate-700"
            >
                ← {{ material.code }} reconciliation
            </Link>
            <h1 class="mt-1 text-xl font-semibold text-slate-900">{{ title }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ material.code }} — {{ material.name }}. Source documents only — quantities not copied.</p>
        </div>

        <AppCard>
            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="min-w-[640px] w-full text-left text-xs">
                    <thead class="bg-slate-50 text-[11px] uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Document</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2 text-right">Quantity</th>
                            <th class="px-3 py-2">BOQ link</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="doc in documents" :key="`${doc.type}-${doc.id}-${doc.quantity}`" class="border-t border-line">
                            <td class="px-3 py-2">
                                <a :href="doc.url" class="font-medium text-brand-700 hover:underline">{{ doc.number }}</a>
                            </td>
                            <td class="px-3 py-2">{{ doc.status }}</td>
                            <td class="px-3 py-2 text-right tabular">{{ formatQty(doc.quantity) }}</td>
                            <td class="px-3 py-2">
                                <span v-if="doc.boq_item_id != null">Item #{{ doc.boq_item_id }}</span>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                        </tr>
                        <tr v-if="!documents.length">
                            <td colspan="4" class="px-3 py-8 text-center text-slate-500">No source documents for this aggregate.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>
    </ProjectLayout>
</template>
