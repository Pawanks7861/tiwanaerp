<script setup>
import AttachmentPanel from '@/Components/Attachments/AttachmentPanel.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import ReasonDialog from '@/Components/Inventory/ReasonDialog.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppModal from '@/Components/UI/AppModal.vue';
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue';
import Icon from '@/Components/UI/Icon.vue';
import StatusBadge from '@/Components/UI/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatDate, formatDateTime, formatMoney, formatPercent, formatQty, formatRate } from '@/lib/format';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    quotation: { type: Object, required: true },
    versions: { type: Array, required: true },
    attachments: { type: Array, required: true },
    convert: { type: Object, default: null },
    can: { type: Object, required: true },
});

const page = usePage();
const errorMessage = computed(() => ['quotation', 'valid_until'].map((k) => page.props.errors?.[k]).find(Boolean));

const confirming = ref(null);
const rejecting = ref(false);
const processing = ref(false);
const ACTIONS = {
    send: { title: 'Mark as sent?', message: 'The quotation is frozen; further changes need a revision.', label: 'Mark sent', danger: false, method: 'post', route: 'crm.quotations.send' },
    accept: { title: 'Record acceptance?', message: 'The client accepted this quotation. It becomes immutable and the lead is marked won.', label: 'Accept', danger: false, method: 'post', route: 'crm.quotations.accept' },
    expire: { title: 'Mark as expired?', message: 'The client did not respond within validity. You can revise it afterwards.', label: 'Mark expired', danger: true, method: 'post', route: 'crm.quotations.expire' },
    revise: { title: 'Create a revision?', message: 'A new draft with the same number and the next revision is opened; this version is marked revised.', label: 'Revise', danger: false, method: 'post', route: 'crm.quotations.revise' },
    delete: { title: 'Delete this draft?', message: 'The quotation draft is removed.', label: 'Delete', danger: true, method: 'delete', route: 'crm.quotations.destroy' },
};
function confirmAction() {
    const action = ACTIONS[confirming.value];
    processing.value = true;
    const options = { preserveScroll: true, onFinish: () => ((processing.value = false), (confirming.value = null)) };
    const url = route(action.route, props.quotation.id);
    action.method === 'delete' ? router.delete(url, options) : router.post(url, {}, options);
}

const converting = ref(false);
const convertForm = useForm({ code: '', project_manager_id: null, start_date: props.convert?.today ?? null, expected_end_date: null });
function doConvert() {
    convertForm
        .transform((d) => ({ ...d, code: d.code || null }))
        .post(route('crm.quotations.convert', props.quotation.id), { preserveScroll: true, onSuccess: () => (converting.value = false) });
}
const inter = computed(() => props.quotation.tax_type === 'inter');
</script>

<template>
    <AppLayout :title="quotation.number">
        <div class="space-y-4">
            <AppCard :padded="false">
                <div class="flex flex-col gap-4 p-4 sm:p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <Link :href="route('crm.quotations.index')" class="text-xs font-medium text-slate-500 hover:text-slate-700">Quotations</Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <h1 class="font-mono text-lg font-semibold text-slate-900">{{ quotation.number }}</h1>
                            <StatusBadge :status="quotation.status" :label="quotation.status_label" />
                        </div>
                        <p class="mt-1 text-sm text-slate-800">{{ quotation.title }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-4">
                            <div><dt class="text-slate-500">For</dt><dd>{{ quotation.party ?? '—' }}</dd></div>
                            <div v-if="quotation.lead"><dt class="text-slate-500">Lead</dt><dd><Link :href="route('crm.leads.show', quotation.lead.id)" class="font-mono text-brand-700 hover:underline">{{ quotation.lead.number }}</Link></dd></div>
                            <div><dt class="text-slate-500">Date</dt><dd>{{ formatDate(quotation.quotation_date) }}</dd></div>
                            <div><dt class="text-slate-500">Valid until</dt><dd>{{ formatDate(quotation.valid_until) }}</dd></div>
                            <div><dt class="text-slate-500">Project</dt><dd>{{ quotation.project_name }}<template v-if="quotation.project_type"> ({{ quotation.project_type }})</template></dd></div>
                            <div><dt class="text-slate-500">Place of supply</dt><dd>{{ quotation.place_of_supply ?? '—' }}</dd></div>
                            <div v-if="quotation.site_address || quotation.city" class="col-span-2"><dt class="text-slate-500">Site</dt><dd>{{ [quotation.site_address, quotation.city].filter(Boolean).join(', ') }}</dd></div>
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">
                            Created by {{ quotation.created_by ?? '—' }}
                            <template v-if="quotation.sent_at"> · Sent {{ formatDateTime(quotation.sent_at) }}</template>
                            <template v-if="quotation.decided_at"> · {{ quotation.status_label }} {{ formatDateTime(quotation.decided_at) }} by {{ quotation.decided_by }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 lg:justify-end">
                        <AppButton v-if="can.update" size="sm" variant="secondary" icon="pencil" :href="route('crm.quotations.edit', quotation.id)">Edit</AppButton>
                        <AppButton v-if="can.send" size="sm" @click="confirming = 'send'">Mark sent</AppButton>
                        <template v-if="can.decide">
                            <AppButton size="sm" @click="confirming = 'accept'">Accept</AppButton>
                            <AppButton size="sm" variant="danger" @click="rejecting = true">Reject</AppButton>
                            <AppButton size="sm" variant="ghost" @click="confirming = 'expire'">Expired</AppButton>
                        </template>
                        <AppButton v-if="can.revise" size="sm" variant="secondary" @click="confirming = 'revise'">Revise</AppButton>
                        <AppButton v-if="can.convert" size="sm" icon="building" @click="converting = true">Convert to project</AppButton>
                        <AppButton v-if="can.delete" size="sm" variant="ghost" icon="trash" aria-label="Delete quotation" @click="confirming = 'delete'" />
                    </div>
                </div>
                <p v-if="errorMessage" class="border-t border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 sm:px-5">{{ errorMessage }}</p>
                <div v-if="quotation.converted_project" class="border-t border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900 sm:px-5">
                    <Icon name="check-circle" :size="16" class="mr-1 inline align-text-bottom" />Converted {{ formatDateTime(quotation.converted_at) }} into project
                    <Link v-if="quotation.converted_project.url" :href="quotation.converted_project.url" class="font-medium underline">{{ quotation.converted_project.label }}</Link>
                    <span v-else class="font-medium">{{ quotation.converted_project.label }}</span>.
                </div>
                <p v-if="quotation.rejection_reason" class="border-t border-line px-4 py-2.5 text-sm text-red-700 sm:px-5">Rejected: {{ quotation.rejection_reason }}</p>
            </AppCard>

            <AppCard title="Lines" :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-line bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium">Description</th>
                                <th class="px-3 py-2 text-right font-medium">Qty</th>
                                <th class="px-3 py-2 text-right font-medium">Rate</th>
                                <th class="px-3 py-2 text-right font-medium">Taxable</th>
                                <th class="px-3 py-2 text-right font-medium">GST</th>
                                <th class="px-4 py-2 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="item in quotation.items" :key="item.id">
                                <td class="min-w-48 px-4 py-2">
                                    {{ item.description }}
                                    <p v-if="item.hsn_sac || item.tax_rate" class="text-xs text-slate-500">{{ [item.hsn_sac && `HSN ${item.hsn_sac}`, item.tax_rate].filter(Boolean).join(' · ') }}</p>
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap tabular">{{ formatQty(item.quantity) }} {{ item.unit }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap tabular">
                                    {{ formatRate(item.rate) }}
                                    <p v-if="item.discount_percent !== '0.0000'" class="text-xs text-slate-500">less {{ formatPercent(item.discount_percent) }}</p>
                                </td>
                                <td class="px-3 py-2 text-right tabular">{{ formatMoney(item.taxable_amount) }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap tabular">
                                    <template v-if="inter">{{ formatMoney(item.igst_amount) }}</template>
                                    <template v-else>{{ formatMoney(item.cgst_amount) }} + {{ formatMoney(item.sgst_amount) }}</template>
                                </td>
                                <td class="px-4 py-2 text-right font-semibold tabular">{{ formatMoney(item.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-3">
                <AppCard title="Totals">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Subtotal</dt><dd class="tabular">{{ formatMoney(quotation.subtotal) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Discount</dt><dd class="tabular">-{{ formatMoney(quotation.discount_amount) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">Taxable value</dt><dd class="tabular">{{ formatMoney(quotation.taxable_amount) }}</dd></div>
                        <div v-if="inter" class="flex justify-between"><dt class="text-slate-500">IGST</dt><dd class="tabular">{{ formatMoney(quotation.igst_amount) }}</dd></div>
                        <template v-else>
                            <div class="flex justify-between"><dt class="text-slate-500">CGST</dt><dd class="tabular">{{ formatMoney(quotation.cgst_amount) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">SGST</dt><dd class="tabular">{{ formatMoney(quotation.sgst_amount) }}</dd></div>
                        </template>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Total</dt><dd class="tabular">{{ formatMoney(quotation.total_amount) }}</dd></div>
                    </dl>
                </AppCard>
                <AppCard title="Versions" :padded="false">
                    <ul class="divide-y divide-line">
                        <li v-for="v in versions" :key="v.id" class="flex items-center justify-between gap-2 px-4 py-2 text-sm" :class="v.current ? 'bg-brand-50' : ''">
                            <Link :href="route('crm.quotations.show', v.id)" class="font-mono text-xs font-medium text-brand-700 hover:underline">{{ v.number }}</Link>
                            <span class="text-xs text-slate-500">{{ v.status_label }}</span>
                            <span class="tabular">{{ formatMoney(v.total_amount) }}</span>
                        </li>
                    </ul>
                </AppCard>
                <AppCard v-if="quotation.terms" title="Terms">
                    <p class="text-sm whitespace-pre-line text-slate-700">{{ quotation.terms }}</p>
                </AppCard>
            </div>

            <AttachmentPanel :attachments="attachments" attachable-type="quotation" :attachable-id="quotation.id" :can-upload="can.attach" :can-delete="can.attach" placeholder="Drawings, BOQ sheet…" />
        </div>

        <ConfirmDialog
            :show="!!confirming"
            :title="confirming ? ACTIONS[confirming].title : ''"
            :message="confirming ? ACTIONS[confirming].message : ''"
            :confirm-label="confirming ? ACTIONS[confirming].label : ''"
            :danger="confirming ? ACTIONS[confirming].danger : true"
            :processing="processing"
            @close="confirming = null"
            @confirm="confirmAction"
        />
        <ReasonDialog :show="rejecting" :url="route('crm.quotations.reject', quotation.id)" title="Record rejection" message="The client declined this quotation. You can revise it afterwards." confirm-label="Reject" @close="rejecting = false" />
        <AppModal :show="converting" title="Convert to project" @close="converting = false">
            <p class="mb-3 text-sm text-slate-600">
                Creates project "{{ quotation.project_name }}" with contract value {{ formatMoney(quotation.taxable_amount) }} (excluding GST), reusing or creating the client.
                No BOQ is created. This can happen only once.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormInput v-model="convertForm.code" label="Project code" uppercase maxlength="12" help="Leave empty to auto-number." :error="convertForm.errors.code" />
                <SearchSelect v-model="convertForm.project_manager_id" label="Project manager" :options="convert?.managers ?? []" :error="convertForm.errors.project_manager_id" />
                <FormInput v-model="convertForm.start_date" type="date" label="Start date" :error="convertForm.errors.start_date" />
                <FormInput v-model="convertForm.expected_end_date" type="date" label="Expected completion" :min="convertForm.start_date" :error="convertForm.errors.expected_end_date" />
            </div>
            <p v-if="convertForm.errors.quotation" class="mt-2 text-sm text-red-700">{{ convertForm.errors.quotation }}</p>
            <template #footer>
                <AppButton variant="secondary" @click="converting = false">Cancel</AppButton>
                <AppButton :loading="convertForm.processing" @click="doConvert">Create project</AppButton>
            </template>
        </AppModal>
    </AppLayout>
</template>
