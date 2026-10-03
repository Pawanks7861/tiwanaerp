<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppBadge from '@/Components/UI/AppBadge.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { initials } from '@/lib/format';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    members: { type: Array, required: true },
    roles: { type: Array, required: true },
    candidates: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'name', label: 'Member' },
    { key: 'project_role', label: 'Project role' },
    { key: 'mobile', label: 'Mobile', mobile: false },
];

const roleLabel = (value) => props.roles.find((r) => r.value === value)?.label ?? value;

const showAdd = ref(false);
const addForm = useForm({ user_id: null, project_role: 'engineer' });
const removing = ref(null);
const removeProcessing = ref(false);

function add() {
    addForm.post(route('projects.team.store', props.project.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAdd.value = false;
            addForm.reset();
        },
    });
}

function changeRole(member, role) {
    router.put(route('projects.team.update', [props.project.id, member.id]), { project_role: role }, { preserveScroll: true });
}

function remove() {
    removeProcessing.value = true;
    router.delete(route('projects.team.destroy', [props.project.id, removing.value.id]), {
        preserveScroll: true,
        onFinish: () => {
            removeProcessing.value = false;
            removing.value = null;
        },
    });
}
</script>

<template>
    <ProjectLayout :project="project" active="team" title="Team">
        <AppCard title="Project team" subtitle="Only team members (and users allowed to see all projects) can open this project." :padded="false">
            <template #actions>
                <AppButton v-if="can.manage" size="sm" icon="plus" :disabled="candidates.length === 0" @click="showAdd = true">Add member</AppButton>
            </template>
            <DataTable :columns="columns" :rows="members" empty-icon="users" empty-title="No team members yet">
                <template #cell-name="{ row }">
                    <div class="flex items-center gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
                            {{ initials(row.name) }}
                        </span>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 font-medium text-slate-900">
                                {{ row.name }}
                                <AppBadge v-if="row.is_manager" color="orange">PM</AppBadge>
                            </div>
                            <div class="truncate text-xs text-slate-500">{{ row.email }}</div>
                        </div>
                    </div>
                </template>
                <template #cell-project_role="{ row }">
                    <select
                        v-if="can.manage && !row.is_manager"
                        :value="row.project_role"
                        class="rounded-md border-slate-300 py-1 text-sm focus:border-brand-500 focus:ring-brand-200"
                        @click.stop
                        @change="changeRole(row, $event.target.value)"
                    >
                        <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                    </select>
                    <span v-else>{{ roleLabel(row.project_role) }}</span>
                </template>
                <template v-if="can.manage" #actions="{ row }">
                    <AppButton v-if="!row.is_manager" variant="ghost" size="sm" icon="trash" @click="removing = row">Remove</AppButton>
                </template>
            </DataTable>
        </AppCard>

        <AppModal :show="showAdd" title="Add team member" @close="showAdd = false">
            <form id="member-form" class="space-y-4" @submit.prevent="add">
                <SearchSelect
                    v-model="addForm.user_id"
                    label="User"
                    required
                    :options="candidates"
                    :error="addForm.errors.user_id"
                    placeholder="Select a company user"
                    help="Only active users of this company are listed."
                />
                <FormSelect v-model="addForm.project_role" label="Project role" required :options="roles" :error="addForm.errors.project_role" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="showAdd = false">Cancel</AppButton>
                <AppButton type="submit" form="member-form" :loading="addForm.processing">Add member</AppButton>
            </template>
        </AppModal>

        <ConfirmDialog
            :show="!!removing"
            :title="`Remove ${removing?.name}?`"
            message="They will lose access to this project unless their role lets them see all projects."
            confirm-label="Remove"
            :processing="removeProcessing"
            @close="removing = null"
            @confirm="remove"
        />
    </ProjectLayout>
</template>
