<script setup>
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    project: { type: Object, default: null },
    groups: { type: Array, required: true },
});
</script>

<template>
    <component :is="project ? ProjectLayout : AppLayout" v-bind="project ? { project, active: 'reports', title: 'Reports' } : { title: 'Reports' }">
        <PageHeader v-if="!project" title="Reports" subtitle="Figures come from the ledgers. What you can open depends on your role." />

        <div v-if="groups.length" class="space-y-6">
            <section v-for="group in groups" :key="group.key">
                <h2 class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ group.label }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <Link v-for="report in group.reports" :key="report.key" :href="report.url">
                        <AppCard class="h-full transition hover:border-brand-200">
                            <p class="text-sm font-semibold text-slate-900">{{ report.title }}</p>
                            <p class="mt-1 text-sm text-slate-500">{{ report.description }}</p>
                        </AppCard>
                    </Link>
                </div>
            </section>
        </div>
        <AppCard v-else>
            <p class="text-sm text-slate-600">No reports are available for your role.</p>
        </AppCard>
    </component>
</template>
