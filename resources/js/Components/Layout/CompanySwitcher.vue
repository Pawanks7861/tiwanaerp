<script setup>
import AppDropdown from '@/Components/UI/AppDropdown.vue';
import Icon from '@/Components/UI/Icon.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const multiCompany = computed(() => page.props.features?.multi_company === true);
const company = computed(() => page.props.company);
const others = computed(() => (company.value?.available ?? []).filter((c) => c.id !== company.value?.current?.id));

function switchTo(id) {
    router.post(route('company.switch'), { company_id: id });
}
</script>

<template>
    <AppDropdown v-if="multiCompany && company?.current" align="left" width="w-64">
        <template #trigger>
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-white/90 hover:bg-white/10"
                :disabled="others.length === 0"
                :class="others.length === 0 ? 'cursor-default hover:bg-transparent' : ''"
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-accent-500 text-xs font-bold text-white">
                    {{ company.current.code?.slice(0, 2) }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-white">{{ company.current.name }}</span>
                    <span class="block truncate text-xs text-white/60">{{ company.current.code }}</span>
                </span>
                <Icon v-if="others.length" name="switch" :size="16" class="text-white/60" />
            </button>
        </template>
        <div v-if="others.length" class="py-1">
            <p class="px-3 py-1.5 text-xs font-semibold tracking-wide text-slate-400 uppercase">Switch company</p>
            <button
                v-for="c in others"
                :key="c.id"
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                @click="switchTo(c.id)"
            >
                <span class="font-mono text-xs text-slate-500">{{ c.code }}</span>
                <span class="truncate">{{ c.name }}</span>
            </button>
        </div>
    </AppDropdown>
</template>
