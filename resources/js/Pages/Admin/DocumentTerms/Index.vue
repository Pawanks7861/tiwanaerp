<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import FormSwitch from '@/Components/Form/FormSwitch.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    templates: { type: Array, required: true },
    profiles: { type: Array, required: true },
    can: { type: Object, required: true },
});

const documentTypes = [
    { value: 'work_order', label: 'Work order' },
    { value: 'purchase_order', label: 'Purchase order' },
];

const form = useForm({
    name: '',
    document_type: 'work_order',
    template_profile: null,
    contractor_scope: '',
    company_scope: '',
    payment_terms: '',
    safety_terms: '',
    general_terms: '',
    penalty_terms: '',
    is_active: true,
});

const editingId = ref(null);

function resetForm() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.document_type = 'work_order';
    form.is_active = true;
}

function edit(row) {
    editingId.value = row.id;
    form.name = row.name;
    form.document_type = row.document_type;
    form.template_profile = row.template_profile;
    form.is_active = row.is_active;
    form.contractor_scope = row.contractor_scope ?? '';
    form.company_scope = row.company_scope ?? '';
    form.payment_terms = row.payment_terms ?? '';
    form.safety_terms = row.safety_terms ?? '';
    form.general_terms = row.general_terms ?? '';
    form.penalty_terms = row.penalty_terms ?? '';
}

function submit() {
    const payload = (d) => ({
        ...d,
        template_profile: d.template_profile || null,
        contractor_scope: d.contractor_scope || null,
        company_scope: d.company_scope || null,
        payment_terms: d.payment_terms || null,
        safety_terms: d.safety_terms || null,
        general_terms: d.general_terms || null,
        penalty_terms: d.penalty_terms || null,
    });
    if (editingId.value) {
        form.transform(payload).put(route('admin.document-terms.update', editingId.value), {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        });
    } else {
        form.transform(payload).post(route('admin.document-terms.store'), {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        });
    }
}

function remove(row) {
    if (!window.confirm(`Delete terms template “${row.name}”? Existing work orders keep their copied wording.`)) {
        return;
    }
    router.delete(route('admin.document-terms.destroy', row.id), { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Document terms templates">
        <PageHeader
            title="Document terms templates"
            subtitle="Defaults copied into a Work Order or Purchase Order. Editing a template later does not change approved documents."
        />

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard title="Templates" :padded="false">
                <ul class="divide-y divide-line">
                    <li v-for="row in templates" :key="row.id" class="flex items-start justify-between gap-3 px-4 py-3">
                        <div>
                            <p class="text-sm font-medium text-slate-900">{{ row.name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ row.document_type === 'work_order' ? 'Work order' : 'Purchase order' }}
                                <template v-if="row.template_profile"> · {{ row.template_profile }}</template>
                                · {{ row.is_active ? 'Active' : 'Inactive' }}
                            </p>
                        </div>
                        <div v-if="can.manage" class="flex gap-1">
                            <AppButton size="sm" variant="ghost" @click="edit(row)">Edit</AppButton>
                            <AppButton size="sm" variant="ghost" @click="remove(row)">Delete</AppButton>
                        </div>
                    </li>
                    <li v-if="!templates.length" class="px-4 py-8 text-center text-sm text-slate-500">No templates yet.</li>
                </ul>
            </AppCard>

            <AppCard v-if="can.manage" :title="editingId ? 'Edit template' : 'New template'">
                <form class="space-y-3" @submit.prevent="submit">
                    <FormInput v-model="form.name" label="Name" required maxlength="120" :error="form.errors.name" />
                    <FormSelect v-model="form.document_type" label="Document type" required :options="documentTypes" :error="form.errors.document_type" />
                    <FormSelect v-model="form.template_profile" label="Profile" placeholder="Any" :options="profiles" :error="form.errors.template_profile" />
                    <FormInput v-model="form.contractor_scope" label="Contractor scope" multiline :rows="3" maxlength="10000" :error="form.errors.contractor_scope" />
                    <FormInput v-model="form.company_scope" label="Company scope" multiline :rows="3" maxlength="10000" :error="form.errors.company_scope" />
                    <FormInput v-model="form.payment_terms" label="Payment terms" multiline :rows="2" maxlength="10000" :error="form.errors.payment_terms" />
                    <FormInput v-model="form.safety_terms" label="Safety terms" multiline :rows="2" maxlength="10000" :error="form.errors.safety_terms" />
                    <FormInput v-model="form.general_terms" label="General terms" multiline :rows="3" maxlength="10000" :error="form.errors.general_terms" />
                    <FormInput v-model="form.penalty_terms" label="Penalty terms" multiline :rows="2" maxlength="10000" :error="form.errors.penalty_terms" />
                    <FormSwitch v-model="form.is_active" label="Active" />
                    <div class="flex justify-end gap-2 pt-2">
                        <AppButton v-if="editingId" type="button" variant="secondary" @click="resetForm">Cancel</AppButton>
                        <AppButton type="submit" :loading="form.processing">{{ editingId ? 'Save' : 'Create' }}</AppButton>
                    </div>
                </form>
            </AppCard>
        </div>
    </AppLayout>
</template>
