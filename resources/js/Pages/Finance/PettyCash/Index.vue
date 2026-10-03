<script setup>
import DataTable from '@/Components/Data/DataTable.vue';
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import FinanceNav from '@/Components/Finance/FinanceNav.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import ProjectLayout from '@/Layouts/ProjectLayout.vue';
import { formatMoney } from '@/lib/format';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    project: { type: Object, required: true },
    accounts: { type: Array, required: true },
    holders: { type: Array, required: true },
    can: { type: Object, required: true },
});

const columns = [
    { key: 'name', label: 'Float' },
    { key: 'holder', label: 'Holder' },
    { key: 'is_active', label: 'Status', mobile: false },
    { key: 'limit_amount', label: 'Limit', align: 'right', mobile: false },
    { key: 'balance', label: 'Balance', align: 'right' },
];

const creating = ref(false);
const form = useForm({ name: '', holder_user_id: null, limit_amount: null });
function store() {
    form.post(route('projects.petty-cash.store', props.project.id), { onSuccess: () => ((creating.value = false), form.reset()) });
}
</script>

<template>
    <ProjectLayout :project="project" active="finance" title="Petty cash">
        <FinanceNav :project-id="project.id" active="petty-cash" />
        <AppCard title="Petty cash floats" subtitle="Funding a float moves cash; only approved expenses paid from it become project cost." :padded="false">
            <template #actions>
                <AppButton v-if="can.create" size="sm" icon="plus" @click="creating = true">New float</AppButton>
            </template>
            <DataTable
                :columns="columns"
                :rows="accounts"
                :row-href="(row) => route('projects.petty-cash.show', [project.id, row.id])"
                empty-icon="banknotes"
                empty-title="No petty cash floats"
                empty-description="Open a float for the site in-charge, then fund it."
            >
                <template #cell-is_active="{ value }"><StatusBadge :status="value" :label="value ? 'Active' : 'Closed'" /></template>
                <template #cell-limit_amount="{ value }"><span class="tabular">{{ value ? formatMoney(value) : 'No limit' }}</span></template>
                <template #cell-balance="{ value }"><span class="font-semibold tabular">{{ formatMoney(value) }}</span></template>
            </DataTable>
        </AppCard>

        <AppModal :show="creating" title="New petty cash float" @close="creating = false">
            <div class="grid gap-4">
                <FormInput v-model="form.name" label="Name" required maxlength="100" placeholder="Site imprest" :error="form.errors.name" />
                <SearchSelect v-model="form.holder_user_id" label="Holder" required :options="holders" help="Must be a member of this project." :error="form.errors.holder_user_id" />
                <DecimalInput v-model="form.limit_amount" label="Limit" prefix="₹" help="Optional maximum balance." :error="form.errors.limit_amount" />
            </div>
            <template #footer>
                <AppButton variant="secondary" @click="creating = false">Cancel</AppButton>
                <AppButton :loading="form.processing" @click="store">Open float</AppButton>
            </template>
        </AppModal>
    </ProjectLayout>
</template>
