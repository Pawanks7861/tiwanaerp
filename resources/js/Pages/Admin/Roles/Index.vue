<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import AppBadge from '@/Components/UI/AppBadge.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { toast } from '@/lib/toast';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    roles: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'name', label: 'Role' },
    { key: 'permissions_count', label: 'Permissions', align: 'right' },
    { key: 'users_count', label: 'Users', align: 'right' },
];

const deleting = ref(null);
const processing = ref(false);

function destroy() {
    processing.value = true;
    router.delete(route('admin.roles.destroy', deleting.value.id), {
        preserveScroll: true,
        onError: (errors) => toast.errors(errors),
        onFinish: () => {
            processing.value = false;
            deleting.value = null;
        },
    });
}
</script>

<template>
    <AppLayout title="Roles & Permissions">
        <PageHeader title="Roles & Permissions" subtitle="Roles group permissions. Users get permissions only through their roles in this company.">
            <template #actions>
                <AppButton v-if="can.create" :href="route('admin.roles.create')" icon="plus">New role</AppButton>
            </template>
        </PageHeader>

        <div class="overflow-hidden rounded-xl border border-line bg-white shadow-sm">
            <DataTable :columns="columns" :rows="roles" :row-href="(row) => route('admin.roles.edit', row.id)" empty-icon="shield" empty-title="No roles">
                <template #cell-name="{ row }">
                    <div class="flex items-center gap-2 font-medium text-slate-900">
                        {{ row.name }}
                        <AppBadge v-if="row.is_system" color="slate">System</AppBadge>
                    </div>
                    <div v-if="row.description" class="text-xs text-slate-500">{{ row.description }}</div>
                </template>
                <template #cell-permissions_count="{ value }"><span class="tabular">{{ value }}</span></template>
                <template #cell-users_count="{ value }"><span class="tabular">{{ value }}</span></template>
                <template #actions="{ row }">
                    <AppButton variant="ghost" size="sm" :icon="row.can_update ? 'pencil' : null" :href="route('admin.roles.edit', row.id)">
                        {{ row.can_update ? 'Edit' : 'View' }}
                    </AppButton>
                    <AppButton v-if="row.can_delete" variant="ghost" size="sm" icon="trash" @click="deleting = row">Delete</AppButton>
                </template>
            </DataTable>
        </div>

        <ConfirmDialog
            :show="!!deleting"
            :title="`Delete role ${deleting?.name}?`"
            message="A role that is still assigned to users cannot be deleted."
            confirm-label="Delete role"
            :processing="processing"
            @close="deleting = null"
            @confirm="destroy"
        />
    </AppLayout>
</template>
