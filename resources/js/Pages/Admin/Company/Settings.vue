<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    company: { type: Object, required: true },
    states: { type: Array, required: true },
    procurement: { type: Object, default: null },
    can: { type: Object, required: true },
});

const c = props.company;
const form = useForm({
    name: c.name ?? '',
    legal_name: c.legal_name ?? '',
    gstin: c.gstin ?? '',
    pan: c.pan ?? '',
    state_code: c.state_code ?? null,
    address: c.address ?? '',
    city: c.city ?? '',
    pincode: c.pincode ?? '',
    phone: c.phone ?? '',
    email: c.email ?? '',
});

const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

function submit() {
    form.put(route('admin.company.update'), { preserveScroll: true });
}

const procurementForm = useForm({ grn_tolerance_percent: props.procurement?.grn_tolerance_percent ?? '0' });
function saveProcurement() {
    procurementForm.put(route('admin.company.procurement'), { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Company settings">
        <PageHeader title="Company settings" :subtitle="`${company.name} · ${company.code}`" />

        <form class="space-y-4" @submit.prevent="submit">
            <AppCard title="Company profile" subtitle="Printed on purchase orders, bills and reports.">
                <fieldset :disabled="!can.update" class="grid gap-4 sm:grid-cols-2">
                    <FormInput v-model="form.name" label="Display name" required maxlength="200" :error="form.errors.name" />
                    <FormInput v-model="form.legal_name" label="Legal name" maxlength="200" :error="form.errors.legal_name" />
                    <FormInput v-model="form.gstin" label="GSTIN" uppercase maxlength="15" :error="form.errors.gstin" />
                    <FormInput v-model="form.pan" label="PAN" uppercase maxlength="10" :error="form.errors.pan" />
                    <FormSelect
                        v-model="form.state_code"
                        label="State"
                        required
                        :options="states"
                        :error="form.errors.state_code"
                        help="Your registered state decides CGST + SGST or IGST."
                    />
                    <FormInput v-model="form.city" label="City" maxlength="100" :error="form.errors.city" />
                    <FormInput v-model="form.address" label="Address" multiline :rows="2" class="sm:col-span-2" :error="form.errors.address" />
                    <FormInput v-model="form.pincode" label="PIN code" inputmode="numeric" maxlength="6" :error="form.errors.pincode" />
                    <FormInput v-model="form.phone" label="Phone" type="tel" maxlength="20" :error="form.errors.phone" />
                    <FormInput v-model="form.email" label="Email" type="email" :error="form.errors.email" />
                </fieldset>
            </AppCard>

            <AppCard title="Regional settings" subtitle="Set when the company was created.">
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-xs font-medium text-slate-500 uppercase">Currency</dt>
                        <dd class="mt-0.5 text-slate-800">{{ company.currency ?? 'INR' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500 uppercase">Financial year starts</dt>
                        <dd class="mt-0.5 text-slate-800">{{ months[(company.fy_start_month ?? 4) - 1] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500 uppercase">Time zone</dt>
                        <dd class="mt-0.5 text-slate-800">{{ company.timezone ?? 'Asia/Kolkata' }}</dd>
                    </div>
                </dl>
            </AppCard>

            <div v-if="can.update" class="flex justify-end">
                <AppButton type="submit" :loading="form.processing">Save settings</AppButton>
            </div>
        </form>

        <form class="mt-4" @submit.prevent="saveProcurement">
            <AppCard title="Procurement" subtitle="Applies to every project unless a project overrides it.">
                <fieldset :disabled="!can.update" class="grid gap-4 sm:grid-cols-3">
                    <DecimalInput
                        v-model="procurementForm.grn_tolerance_percent"
                        label="GRN over-receipt tolerance"
                        :decimals="4"
                        suffix="%"
                        required
                        help="How much more than the ordered quantity may be accepted on goods receipts. 0 = no over-receipt."
                        :error="procurementForm.errors.grn_tolerance_percent"
                    />
                </fieldset>
                <template v-if="can.update" #footer>
                    <div class="flex justify-end">
                        <AppButton type="submit" size="sm" :loading="procurementForm.processing">Save procurement settings</AppButton>
                    </div>
                </template>
            </AppCard>
        </form>
    </AppLayout>
</template>
