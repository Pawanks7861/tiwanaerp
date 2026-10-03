<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FilterBar from '@/Components/Data/FilterBar.vue';
import Pagination from '@/Components/Data/Pagination.vue';
import AppBadge from '@/Components/UI/AppBadge.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDateTime, initials } from '@/lib/format';
import { usePermissions } from '@/lib/permissions';
import { toast } from '@/lib/toast';
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, required: true },
    can: { type: Object, required: true },
});

const page = usePage();
const { can: hasPermission } = usePermissions();
const canManage = hasPermission('admin.users.manage');
const toggling = ref(null);
const processing = ref(false);

const columns = [
    { key: 'name', label: 'User' },
    { key: 'roles', label: 'Roles' },
    { key: 'mobile', label: 'Mobile', mobile: false },
    { key: 'last_login_at', label: 'Last login', mobile: false },
    { key: 'is_active', label: 'Status' },
];

function toggle() {
    processing.value = true;
    router.patch(
        route('admin.users.status', toggling.value.id),
        { is_active: !toggling.value.is_active },
        {
            preserveScroll: true,
            onError: (errors) => toast.errors(errors),
            onFinish: () => {
                processing.value = false;
                toggling.value = null;
            },
        },
    );
}
</script>

<template>
    <AppLayout title="Users">
        <PageHeader title="Users" subtitle="People who can sign in to this company.">
            <template #actions>
                <AppButton v-if="can.create" :href="route('admin.users.create')" icon="plus">Add user</AppButton>
            </template>
        </PageHeader>

        <div class="overflow-hidden rounded-xl border border-line bg-white shadow-sm">
            <FilterBar
                :filters="filters"
                placeholder="Search by name, email or mobile"
                :selects="[
                    {
                        key: 'status',
                        options: [
                            { value: 'all', label: 'All users' },
                            { value: 'active', label: 'Active' },
                            { value: 'inactive', label: 'Inactive' },
                        ],
                    },
                ]"
            />
            <DataTable :columns="columns" :rows="users.data" empty-icon="users" empty-title="No users found">
                <template #cell-name="{ row }">
                    <div class="flex items-center gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
                            {{ initials(row.name) }}
                        </span>
                        <div class="min-w-0">
                            <div class="font-medium text-slate-900">
                                {{ row.name }}
                                <span v-if="row.id === page.props.auth.user.id" class="ml-1 text-xs font-normal text-slate-500">(you)</span>
                            </div>
                            <div class="truncate text-xs text-slate-500">{{ row.email }}</div>
                        </div>
                    </div>
                </template>
                <template #cell-roles="{ value }">
                    <div class="flex flex-wrap justify-end gap-1 md:justify-start">
                        <AppBadge v-for="role in value" :key="role" color="brand">{{ role }}</AppBadge>
                        <span v-if="!value.length" class="text-xs text-slate-400">No role</span>
                    </div>
                </template>
                <template #cell-last_login_at="{ value }">
                    <span class="text-xs text-slate-500">{{ value ? formatDateTime(value) : 'Never' }}</span>
                </template>
                <template #cell-is_active="{ row }">
                    <StatusBadge :status="row.is_active" :label="row.account_disabled ? 'Account disabled' : row.is_active ? 'Active' : 'Inactive'" />
                </template>
                <template v-if="canManage" #actions="{ row }">
                    <AppButton variant="ghost" size="sm" icon="pencil" :href="route('admin.users.edit', row.id)">Edit</AppButton>
                    <AppButton v-if="row.id !== page.props.auth.user.id && !row.account_disabled" variant="ghost" size="sm" @click="toggling = row">
                        {{ row.is_active ? 'Deactivate' : 'Activate' }}
                    </AppButton>
                </template>
            </DataTable>
            <Pagination :paginator="users" />
        </div>

        <ConfirmDialog
            :show="!!toggling"
            :title="toggling?.is_active ? `Deactivate ${toggling?.name}?` : `Activate ${toggling?.name}?`"
            :message="
                toggling?.is_active
                    ? 'They will be signed out of this company and cannot sign in to it until reactivated. Their history is kept.'
                    : 'They will be able to sign in to this company again with their existing roles.'
            "
            :confirm-label="toggling?.is_active ? 'Deactivate' : 'Activate'"
            :danger="!!toggling?.is_active"
            :processing="processing"
            @close="toggling = null"
            @confirm="toggle"
        />
    </AppLayout>
</template>
