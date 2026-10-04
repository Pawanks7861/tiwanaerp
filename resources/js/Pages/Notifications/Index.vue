<script setup>
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { timeAgo } from '@/lib/format';
import { Link, router, usePage } from '@inertiajs/vue3';

defineProps({
    notifications: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
});

const page = usePage();

function open(notification) {
    const go = () => notification.url && router.visit(notification.url);
    if (notification.read) {
        go();
        return;
    }
    router.post(route('notifications.read', notification.id), {}, { preserveScroll: true, onSuccess: go });
}

function markAll() {
    router.post(route('notifications.read-all'), {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Notifications">
        <PageHeader title="Notifications" :subtitle="`For ${page.props.company?.current?.name ?? 'this company'}`">
            <template #actions>
                <Link :href="route('notifications.preferences')" class="text-sm font-medium text-brand-700 hover:text-brand-800">Preferences</Link>
                <AppButton v-if="page.props.unreadNotifications > 0" variant="secondary" size="sm" @click="markAll">Mark all as read</AppButton>
            </template>
        </PageHeader>

        <AppCard :padded="false">
            <FilterBar
                :filters="filters"
                :searchable="false"
                :selects="[
                    { key: 'read', options: options.read },
                    { key: 'type', options: options.types },
                    { key: 'project_id', options: options.projects },
                ]"
            />
            <ul v-if="notifications.data.length" class="divide-y divide-line">
                <li v-for="n in notifications.data" :key="n.id">
                    <button type="button" class="flex w-full gap-3 px-4 py-3 text-left hover:bg-slate-50 sm:px-5" @click="open(n)">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read ? 'bg-transparent' : 'bg-accent-500'" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm" :class="n.read ? 'text-slate-600' : 'font-medium text-slate-900'">{{ n.title }}</span>
                            <span v-if="n.body" class="mt-0.5 block text-sm text-slate-500">{{ n.body }}</span>
                            <span class="mt-1 block text-xs text-slate-400">{{ n.type }} · {{ timeAgo(n.created_at) }}</span>
                        </span>
                        <Icon v-if="n.url" name="chevron-right" :size="16" class="mt-1 text-slate-300" />
                    </button>
                </li>
            </ul>
            <EmptyState v-else icon="bell" title="No notifications" description="Nothing matches these filters. Approval requests, tasks and alerts show up here." />
            <Pagination :paginator="notifications" />
        </AppCard>
    </AppLayout>
</template>
