<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    sites: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'name', label: 'Site' },
    { key: 'location', label: 'Location' },
    { key: 'geofence_radius_m', label: 'Geofence', mobile: false },
    { key: 'is_active', label: 'Status' },
];

const editing = ref(null);
const showForm = ref(false);
const deleting = ref(null);
const deleteProcessing = ref(false);

const form = useForm({ name: '', address: '', latitude: '', longitude: '', geofence_radius_m: 200, is_active: true });

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
}

function openEdit(site) {
    editing.value = site;
    form.clearErrors();
    form.name = site.name;
    form.address = site.address ?? '';
    form.latitude = site.latitude ?? '';
    form.longitude = site.longitude ?? '';
    form.geofence_radius_m = site.geofence_radius_m ?? 200;
    form.is_active = !!site.is_active;
    showForm.value = true;
}

function useCurrentLocation() {
    navigator.geolocation?.getCurrentPosition((pos) => {
        form.latitude = pos.coords.latitude.toFixed(7);
        form.longitude = pos.coords.longitude.toFixed(7);
    });
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    if (editing.value) {
        form.put(route('projects.sites.update', [props.project.id, editing.value.id]), options);
    } else {
        form.post(route('projects.sites.store', props.project.id), options);
    }
}

function destroy() {
    deleteProcessing.value = true;
    router.delete(route('projects.sites.destroy', [props.project.id, deleting.value.id]), {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            deleting.value = null;
        },
    });
}
</script>

<template>
    <ProjectLayout :project="project" active="sites" title="Sites">
        <AppCard title="Sites" subtitle="Work locations of this project. GPS and geofence are used for attendance and DPR photos." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" @click="openCreate">Add site</AppButton>
            </template>
            <DataTable :columns="columns" :rows="sites" empty-icon="map-pin" empty-title="No sites yet" empty-description="Add the site locations where work happens.">
                <template #cell-name="{ row }">
                    <div class="font-medium text-slate-900">{{ row.name }}</div>
                    <div v-if="row.address" class="text-xs text-slate-500">{{ row.address }}</div>
                </template>
                <template #cell-location="{ row }">
                    <a
                        v-if="row.latitude && row.longitude"
                        :href="`https://www.google.com/maps?q=${row.latitude},${row.longitude}`"
                        target="_blank"
                        rel="noopener"
                        class="text-xs text-brand-600 tabular hover:underline"
                        @click.stop
                    >
                        {{ row.latitude }}, {{ row.longitude }}
                    </a>
                    <span v-else class="text-xs text-slate-400">Not set</span>
                </template>
                <template #cell-geofence_radius_m="{ value }">
                    <span class="tabular">{{ value ? `${value} m` : '—' }}</span>
                </template>
                <template #cell-is_active="{ value }">
                    <StatusBadge :status="!!value" />
                </template>
                <template v-if="can.update || can.delete" #actions="{ row }">
                    <AppButton v-if="can.update" variant="ghost" size="sm" icon="pencil" @click="openEdit(row)">Edit</AppButton>
                    <AppButton v-if="can.delete" variant="ghost" size="sm" icon="trash" @click="deleting = row">Delete</AppButton>
                </template>
            </DataTable>
        </AppCard>

        <AppModal :show="showForm" :title="editing ? `Edit ${editing.name}` : 'Add site'" @close="showForm = false">
            <form id="site-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <FormInput v-model="form.name" label="Site name" required class="sm:col-span-2" :error="form.errors.name" maxlength="150" />
                <FormInput v-model="form.address" label="Address" multiline :rows="2" class="sm:col-span-2" :error="form.errors.address" />
                <FormInput v-model="form.latitude" label="Latitude" inputmode="decimal" :error="form.errors.latitude" />
                <FormInput v-model="form.longitude" label="Longitude" inputmode="decimal" :error="form.errors.longitude" />
                <div class="sm:col-span-2">
                    <button type="button" class="text-xs font-medium text-brand-600 hover:text-brand-700" @click="useCurrentLocation">
                        Use my current location
                    </button>
                </div>
                <FormInput
                    v-model="form.geofence_radius_m"
                    label="Geofence radius (m)"
                    type="number"
                    min="10"
                    max="10000"
                    :error="form.errors.geofence_radius_m"
                />
                <div class="flex items-end pb-2">
                    <FormSwitch v-model="form.is_active" label="Active" />
                </div>
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="showForm = false">Cancel</AppButton>
                <AppButton type="submit" form="site-form" :loading="form.processing">{{ editing ? 'Save' : 'Add site' }}</AppButton>
            </template>
        </AppModal>

        <ConfirmDialog
            :show="!!deleting"
            :title="`Delete ${deleting?.name}?`"
            message="Sites that already have records cannot be deleted; deactivate them instead."
            confirm-label="Delete site"
            :processing="deleteProcessing"
            @close="deleting = null"
            @confirm="destroy"
        />
    </ProjectLayout>
</template>
