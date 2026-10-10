<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import FormSelect from '@/Components/Form/FormSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    company: { type: Object, required: true },
    states: { type: Array, required: true },
    procurement: { type: Object, default: null },
    can: { type: Object, required: true },
    branding: { type: Object, default: () => ({ logo_url: null, favicon_url: null, max_kb: 2048 }) },
    cadPreview: { type: Object, default: () => ({ available: false, driver: 'none', configured: 'auto', version: null, last_success_at: null, error: null }) },
    dwgPreview: { type: Object, default: () => ({ provider: 'local', acknowledged: false, server_enabled: false }) },
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

const previewProviders = [
    { value: 'local', label: 'Local' },
    { value: 'sharecad', label: 'ShareCAD' },
    { value: 'auto', label: 'Automatic' },
];
const previewForm = useForm({
    provider: props.dwgPreview?.provider ?? 'local',
    sharecad_acknowledged: Boolean(props.dwgPreview?.acknowledged),
});
function savePreview() {
    previewForm.put(route('admin.company.dwg-preview'), { preserveScroll: true });
}

const procurementForm = useForm({ grn_tolerance_percent: props.procurement?.grn_tolerance_percent ?? '0' });
function saveProcurement() {
    procurementForm.put(route('admin.company.procurement'), { preserveScroll: true });
}

const logoInput = ref(null);
const faviconInput = ref(null);
const logoBroken = ref(false);
const faviconBroken = ref(false);
const maxMb = Math.min(2, (props.branding.max_kb ?? 2048) / 1024);

function uploadBrand(kind, file) {
    if (!file) {
        return;
    }
    if (file.size > maxMb * 1024 * 1024) {
        window.alert(`The file must be ${maxMb} MB or smaller.`);
        return;
    }
    const data = new FormData();
    data.append('file', file);
    router.post(route(kind === 'logo' ? 'admin.company.logo.store' : 'admin.company.favicon.store'), data, {
        forceFormData: true,
        preserveScroll: true,
    });
}
function removeBrand(kind) {
    router.delete(route(kind === 'logo' ? 'admin.company.logo.destroy' : 'admin.company.favicon.destroy'), { preserveScroll: true });
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

        <AppCard class="mt-4" title="Branding" subtitle="Shown in the sidebar and browser tab for this company. PNG, JPG or WEBP, up to 2 MB. The server limit is 2 MB.">
            <div class="grid gap-6 sm:grid-cols-2">
                <div>
                    <p class="text-sm font-medium text-slate-800">Company logo</p>
                    <div class="mt-2 flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg border border-line bg-slate-50">
                        <img v-if="branding.logo_url && !logoBroken" :src="branding.logo_url" alt="Current logo" class="h-full w-full object-contain" @error="logoBroken = true" />
                        <span v-else class="text-xs text-slate-400">None</span>
                    </div>
                    <div v-if="can.update" class="mt-3 flex flex-wrap gap-2">
                        <AppButton type="button" size="sm" @click="logoInput?.click()">{{ branding.logo_url ? 'Replace logo' : 'Upload logo' }}</AppButton>
                        <AppButton v-if="branding.logo_url" type="button" size="sm" variant="secondary" @click="removeBrand('logo')">Remove logo</AppButton>
                        <input ref="logoInput" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" class="hidden" @change="uploadBrand('logo', $event.target.files?.[0]); $event.target.value = ''" />
                    </div>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-800">Favicon</p>
                    <div class="mt-2 flex h-16 w-16 items-center justify-center overflow-hidden rounded-lg border border-line bg-slate-50">
                        <img v-if="branding.favicon_url && !faviconBroken" :src="branding.favicon_url" alt="Current favicon" class="h-8 w-8 object-contain" @error="faviconBroken = true" />
                        <span v-else class="text-xs text-slate-400">Default</span>
                    </div>
                    <div v-if="can.update" class="mt-3 flex flex-wrap gap-2">
                        <AppButton type="button" size="sm" @click="faviconInput?.click()">{{ branding.favicon_url ? 'Replace favicon' : 'Upload favicon' }}</AppButton>
                        <AppButton v-if="branding.favicon_url" type="button" size="sm" variant="secondary" @click="removeBrand('favicon')">Remove favicon</AppButton>
                        <input ref="faviconInput" type="file" accept=".png,.jpg,.jpeg,.webp,.ico,image/png,image/jpeg,image/webp,image/x-icon" class="hidden" @change="uploadBrand('favicon', $event.target.files?.[0]); $event.target.value = ''" />
                    </div>
                </div>
            </div>
        </AppCard>

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

        <AppCard class="mt-4" title="CAD preview" subtitle="Drawing files stay on this server. The converter path is not shown.">
            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-slate-500">Status</dt>
                    <dd>{{ cadPreview.available ? 'Available' : 'Not configured' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Driver</dt>
                    <dd>{{ cadPreview.driver }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Version</dt>
                    <dd>{{ cadPreview.version || 'Unknown' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Last successful preview</dt>
                    <dd>{{ cadPreview.last_success_at || 'None yet' }}</dd>
                </div>
            </dl>
            <p v-if="cadPreview.error" class="mt-3 text-sm text-slate-600">{{ cadPreview.error }}</p>
        </AppCard>

        <form class="mt-4" @submit.prevent="savePreview">
            <AppCard title="File preview" subtitle="DWG preview provider. The local converter stays the preferred production path.">
                <FormSelect
                    v-model="previewForm.provider"
                    label="DWG preview provider"
                    :options="previewProviders"
                    :error="previewForm.errors.provider"
                    help="Automatic uses ShareCAD only when a local converter is not available."
                />
                <p class="mt-3 text-sm text-slate-600">Commercial licensing/permission must be confirmed before production use. The free ShareCAD viewer is not for commercial use. A local converter or a licensed CAD SDK should remain the production preview.</p>
                <p v-if="!dwgPreview.server_enabled" class="mt-2 text-sm text-slate-600">ShareCAD is turned off on this server until SHARECAD_DWG_PREVIEW is enabled.</p>
                <div v-if="previewForm.provider !== 'local'" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <p>DWG files will be temporarily shared with an external CAD viewing service for preview.</p>
                    <label class="mt-2 flex items-start gap-2">
                        <input v-model="previewForm.sharecad_acknowledged" type="checkbox" class="mt-1 rounded border-amber-300" />
                        <span>I understand that DWG files will be temporarily shared with an external CAD viewing service for preview.</span>
                    </label>
                    <p v-if="previewForm.errors.sharecad_acknowledged" class="mt-2 text-red-700">{{ previewForm.errors.sharecad_acknowledged }}</p>
                </div>
                <template v-if="can.update" #footer>
                    <div class="flex justify-end">
                        <AppButton type="submit" size="sm" :loading="previewForm.processing">Save file preview settings</AppButton>
                    </div>
                </template>
            </AppCard>
        </form>
    </AppLayout>
</template>
