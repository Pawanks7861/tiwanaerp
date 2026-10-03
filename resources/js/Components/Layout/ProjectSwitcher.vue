<script setup>
import Icon from '@/Components/UI/Icon.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Global project selector. Choosing a project opens its dashboard; the project context
 * is carried by the URL (/projects/{id}/...), never by hidden client state.
 */
const page = usePage();
const projects = computed(() => page.props.projectSwitcher ?? []);
const current = computed(() => page.props.project ?? null);

const open = ref(false);
const query = ref('');
const root = ref(null);
const search = ref(null);

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();

    return q ? projects.value.filter((p) => `${p.code} ${p.name}`.toLowerCase().includes(q)) : projects.value;
});

async function toggle() {
    open.value = !open.value;
    if (open.value) {
        query.value = '';
        await nextTick();
        search.value?.focus();
    }
}

function choose(project) {
    open.value = false;
    router.visit(route('projects.show', project.id));
}

const onClick = (e) => root.value && !root.value.contains(e.target) && (open.value = false);
onMounted(() => document.addEventListener('mousedown', onClick));
onBeforeUnmount(() => document.removeEventListener('mousedown', onClick));
</script>

<template>
    <div ref="root" class="relative min-w-0">
        <button
            type="button"
            class="flex w-full max-w-xs min-w-0 items-center gap-2 rounded-lg border border-line bg-white px-3 py-1.5 text-left text-sm shadow-sm hover:bg-slate-50"
            :aria-expanded="open"
            @click="toggle"
        >
            <Icon name="building" :size="16" class="text-slate-400" />
            <span class="min-w-0 flex-1 truncate">
                <template v-if="current">
                    <span class="font-mono text-xs text-slate-500">{{ current.code }}</span>
                    <span class="ml-1 font-medium text-slate-800">{{ current.name }}</span>
                </template>
                <span v-else class="text-slate-500">Select project</span>
            </span>
            <Icon name="chevron-down" :size="16" class="text-slate-400" />
        </button>

        <div v-if="open" class="absolute left-0 z-40 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-line bg-white shadow-lg">
            <div class="border-b border-line p-2">
                <input
                    ref="search"
                    v-model="query"
                    type="search"
                    placeholder="Search projects…"
                    class="block w-full rounded-md border-slate-300 px-2.5 py-1.5 text-sm focus:border-brand-500 focus:ring-brand-200"
                    @keydown.enter.prevent="filtered[0] && choose(filtered[0])"
                />
            </div>
            <ul class="max-h-72 overflow-y-auto py-1">
                <li v-for="project in filtered" :key="project.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50"
                        :class="current?.id === project.id ? 'bg-brand-50' : ''"
                        @click="choose(project)"
                    >
                        <span class="w-16 shrink-0 font-mono text-xs text-slate-500">{{ project.code }}</span>
                        <span class="truncate text-slate-800">{{ project.name }}</span>
                    </button>
                </li>
                <li v-if="filtered.length === 0" class="px-3 py-3 text-sm text-slate-500">No open projects found</li>
            </ul>
            <Link :href="route('projects.index')" class="block border-t border-line px-3 py-2 text-xs font-medium text-brand-600 hover:bg-slate-50" @click="open = false">
                All projects →
            </Link>
        </div>
    </div>
</template>
