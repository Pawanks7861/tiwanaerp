<script setup>
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { enablePush } from '@/lib/fcm';
import { toast } from '@/lib/toast';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    groups: { type: Array, required: true },
    futureChannels: { type: Array, required: true },
});

const page = usePage();
const enabling = ref(false);
const form = useForm({
    preferences: props.groups.flatMap((group) => group.types.map((type) => ({
        type: type.key,
        database: type.database,
        push: type.push,
    }))),
});

function row(key) {
    return form.preferences.find((preference) => preference.type === key);
}

function save() {
    form.put(route('notifications.preferences.update'));
}

async function enableBrowser() {
    enabling.value = true;
    try {
        await enablePush(page.props.fcm?.web);
        toast.success('This browser can receive push notifications.');
    } catch {
        toast.error('Push permission was not granted, or this browser could not register.');
    } finally {
        enabling.value = false;
    }
}
</script>

<template>
    <AppLayout title="Notification preferences">
        <PageHeader title="Notification preferences" subtitle="Choose which alerts appear in your inbox.">
            <template #actions>
                <Link :href="route('notifications.index')" class="text-sm font-medium text-slate-600 hover:text-slate-900">Back to notifications</Link>
            </template>
        </PageHeader>

        <form class="space-y-4" @submit.prevent="save">
            <AppCard v-for="group in groups" :key="group.group" :title="group.group">
                <ul class="divide-y divide-line">
                    <li v-for="type in group.types" :key="type.key" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <span class="text-sm text-slate-800">{{ type.label }}</span>
                        <span class="flex shrink-0 items-center gap-4">
                            <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                                <input v-model="row(type.key).database" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-200" />
                                In-app
                            </label>
                            <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                                <input v-model="row(type.key).push" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-200" />
                                Push
                            </label>
                        </span>
                    </li>
                </ul>
            </AppCard>

            <AppCard title="This browser" subtitle="Push is delivered only for the notification types you turn on, and only after this browser is registered.">
                <AppButton type="button" variant="secondary" :disabled="!page.props.fcm?.web || enabling" @click="enableBrowser">
                    {{ page.props.fcm?.web ? 'Enable push on this browser' : 'Firebase web keys are not configured' }}
                </AppButton>
            </AppCard>

            <AppCard title="Coming later">
                <ul class="divide-y divide-line">
                    <li v-for="channel in futureChannels" :key="channel.key" class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                        <span class="text-sm text-slate-500">{{ channel.label }}</span>
                        <label class="inline-flex items-center gap-2 text-sm text-slate-400">
                            <input type="checkbox" disabled class="rounded border-slate-200" />
                            Not available yet
                        </label>
                    </li>
                </ul>
            </AppCard>

            <div class="flex justify-end">
                <AppButton type="submit" :disabled="form.processing">Save preferences</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
