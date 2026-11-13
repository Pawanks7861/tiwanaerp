<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    site: { type: Object, default: null },
    states: { type: Array, default: () => [] },
});

const editing = computed(() => !!props.site);
const s = props.site;
const form = useForm({
    code: s?.code ?? '',
    name: s?.name ?? '',
    state_code: s?.state_code ?? null,
    city: s?.city ?? '',
    address: s?.address ?? '',
    is_active: s?.is_active ?? true,
});

function submit() {
    const transform = (d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]));
    editing.value
        ? form.transform(transform).put(route('utilities.electricity.sites.update', props.site.id))
        : form.transform(transform).post(route('utilities.electricity.sites.store'));
}
</script>

<template>
    <AppLayout :title="editing ? site.code : 'New site'">
        <PageHeader :title="editing ? `Edit ${site.name}` : 'New utility site'" :back="route('utilities.electricity.sites.index')" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard title="Site details">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormInput v-model="form.code" label="Code" required maxlength="40" :error="form.errors.code" :disabled="editing" />
                    <FormInput v-model="form.name" label="Name" required maxlength="200" :error="form.errors.name" class="sm:col-span-2" />
                    <SearchSelect v-model="form.state_code" label="State" :options="states" :error="form.errors.state_code" />
                    <FormInput v-model="form.city" label="City" maxlength="100" :error="form.errors.city" />
                    <FormInput v-model="form.address" label="Address" multiline :rows="2" class="sm:col-span-2 lg:col-span-3" :error="form.errors.address" />
                    <FormSwitch v-model="form.is_active" label="Active" :error="form.errors.is_active" />
                </div>
            </AppCard>
            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link :href="route('utilities.electricity.sites.index')" class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none">Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Create site' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
