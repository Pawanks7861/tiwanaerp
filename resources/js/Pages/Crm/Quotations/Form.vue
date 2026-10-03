<script setup>
import DecimalInput from '@/Components/Form/DecimalInput.vue';
import FormInput from '@/Components/Form/FormInput.vue';
import SearchSelect from '@/Components/Form/SearchSelect.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { formatMoney } from '@/lib/format';
import { Link, useForm } from '@inertiajs/vue3';
import Decimal from 'decimal.js';
import { computed } from 'vue';

const props = defineProps({
    quotation: { type: Object, default: null },
    defaults: { type: Object, required: true },
    leads: { type: Array, required: true },
    clients: { type: Array, required: true },
    states: { type: Array, required: true },
    companyState: { type: String, default: null },
    units: { type: Array, required: true },
    taxRates: { type: Array, required: true },
});

const dec = (v) => {
    try {
        return new Decimal(v || 0);
    } catch {
        return new Decimal(0);
    }
};
const nz = (v) => (v === null || v === undefined || dec(v).isZero() ? null : v);

const editing = computed(() => !!props.quotation);
const q = props.quotation;
const d = props.defaults;
const blankLine = () => ({ description: '', hsn_sac: '', unit_id: null, quantity: null, rate: null, discount_percent: null, tax_rate_id: null });
const form = useForm({
    lead_id: q?.lead_id ?? d.lead_id ?? null,
    client_id: q?.client_id ?? d.client_id ?? null,
    quotation_date: q?.quotation_date ?? d.quotation_date,
    valid_until: q ? q.valid_until : d.valid_until,
    title: q?.title ?? '',
    project_name: q?.project_name ?? d.project_name ?? '',
    project_type: q?.project_type ?? d.project_type ?? '',
    site_address: q?.site_address ?? d.site_address ?? '',
    city: q?.city ?? '',
    place_of_supply_state: q?.place_of_supply_state ?? d.place_of_supply_state ?? null,
    terms: q?.terms ?? '',
    items: q?.items?.length ? q.items.map((i) => ({ ...i, hsn_sac: i.hsn_sac ?? '', discount_percent: nz(i.discount_percent) })) : [blankLine()],
});

const money = (x) => x.toDecimalPlaces(2, Decimal.ROUND_HALF_UP);
const inter = computed(() => !!form.place_of_supply_state && !!props.companyState && form.place_of_supply_state !== props.companyState);
/** Preview only: the server recomputes every line and total. */
const lines = computed(() =>
    form.items.map((i) => {
        const base = money(dec(i.quantity).times(dec(i.rate)));
        const taxable = base.minus(money(base.times(dec(i.discount_percent)).dividedBy(100)));
        const t = props.taxRates.find((r) => r.value === i.tax_rate_id);
        let tax = new Decimal(0);
        if (t) {
            tax = inter.value
                ? money(taxable.times(dec(t.igst_rate)).dividedBy(100))
                : money(taxable.times(dec(t.cgst_rate)).dividedBy(100)).plus(money(taxable.times(dec(t.sgst_rate)).dividedBy(100)));
        }

        return { taxable, tax, amount: taxable.plus(tax) };
    }),
);
const taxable = computed(() => lines.value.reduce((s, l) => s.plus(l.taxable), new Decimal(0)));
const taxTotal = computed(() => lines.value.reduce((s, l) => s.plus(l.tax), new Decimal(0)));
const lineError = (i, field) => form.errors[`items.${i}.${field}`];

function submit() {
    const transform = (data) => ({
        ...Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])),
        items: data.items.map((i) => ({ ...i, hsn_sac: i.hsn_sac || null })),
    });
    editing.value
        ? form.transform(transform).put(route('crm.quotations.update', props.quotation.id))
        : form.transform(transform).post(route('crm.quotations.store'));
}
</script>

<template>
    <AppLayout :title="editing ? quotation.number : 'New quotation'">
        <PageHeader :title="editing ? `Edit ${quotation.number}` : 'New quotation'" :back="editing ? route('crm.quotations.show', quotation.id) : route('crm.quotations.index')" />
        <form class="space-y-4 pb-20 md:pb-0" @submit.prevent="submit">
            <AppCard>
                <p v-if="form.errors.quotation" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ form.errors.quotation }}</p>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SearchSelect v-model="form.lead_id" label="Lead" :options="leads" class="sm:col-span-2" help="A lead or a client is required." :error="form.errors.lead_id" />
                    <SearchSelect v-model="form.client_id" label="Client" :options="clients" class="sm:col-span-2" :error="form.errors.client_id" />
                    <FormInput v-model="form.title" label="Title" required maxlength="200" class="sm:col-span-2" :error="form.errors.title" />
                    <FormInput v-model="form.quotation_date" type="date" label="Date" required :error="form.errors.quotation_date" />
                    <FormInput v-model="form.valid_until" type="date" label="Valid until" :min="form.quotation_date" :error="form.errors.valid_until" />
                    <FormInput v-model="form.project_name" label="Project name" required maxlength="200" class="sm:col-span-2" help="Used as the project name on conversion." :error="form.errors.project_name" />
                    <FormInput v-model="form.project_type" label="Project type" maxlength="50" :error="form.errors.project_type" />
                    <SearchSelect v-model="form.place_of_supply_state" label="Place of supply" required :options="states" :help="inter ? 'IGST (inter-state)' : 'CGST + SGST'" :error="form.errors.place_of_supply_state" />
                    <FormInput v-model="form.site_address" label="Site address" maxlength="1000" class="sm:col-span-2 lg:col-span-3" :error="form.errors.site_address" />
                    <FormInput v-model="form.city" label="City" maxlength="100" :error="form.errors.city" />
                </div>
            </AppCard>

            <AppCard title="Lines" :padded="false">
                <p v-if="form.errors.items" class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.items }}</p>
                <ol class="divide-y divide-line">
                    <li v-for="(item, i) in form.items" :key="i" class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-4 lg:grid-cols-8">
                        <FormInput v-model="item.description" label="Description" required maxlength="500" class="col-span-2 lg:col-span-3" :error="lineError(i, 'description')" />
                        <FormInput v-model="item.hsn_sac" label="HSN/SAC" maxlength="10" :error="lineError(i, 'hsn_sac')" />
                        <SearchSelect v-model="item.unit_id" label="Unit" :options="units" :error="lineError(i, 'unit_id')" />
                        <DecimalInput v-model="item.quantity" label="Qty" required :decimals="4" :error="lineError(i, 'quantity')" />
                        <DecimalInput v-model="item.rate" label="Rate" required prefix="₹" :decimals="4" :error="lineError(i, 'rate')" />
                        <DecimalInput v-model="item.discount_percent" label="Disc." suffix="%" :decimals="4" :error="lineError(i, 'discount_percent')" />
                        <SearchSelect v-model="item.tax_rate_id" label="GST" :options="taxRates" class="col-span-2" :error="lineError(i, 'tax_rate_id')" />
                        <div class="col-span-2 flex items-end justify-between gap-2 sm:col-span-2 lg:col-span-6">
                            <AppButton v-if="form.items.length > 1" size="sm" variant="ghost" icon="trash" :aria-label="`Remove line ${i + 1}`" @click="form.items.splice(i, 1)" />
                            <span class="ml-auto text-sm font-semibold tabular">{{ formatMoney(lines[i].amount.toFixed(2)) }}</span>
                        </div>
                    </li>
                </ol>
                <div class="border-t border-line p-3">
                    <AppButton size="sm" variant="secondary" icon="plus" @click="form.items.push(blankLine())">Add line</AppButton>
                </div>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard title="Terms">
                    <FormInput v-model="form.terms" multiline :rows="5" maxlength="5000" aria-label="Terms" :error="form.errors.terms" />
                </AppCard>
                <AppCard title="Totals (preview)">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">Taxable value</dt><dd class="tabular">{{ formatMoney(taxable.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">GST</dt><dd class="tabular">{{ formatMoney(taxTotal.toFixed(2)) }}</dd></div>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Total</dt><dd class="tabular">{{ formatMoney(taxable.plus(taxTotal).toFixed(2)) }}</dd></div>
                    </dl>
                </AppCard>
            </div>

            <div class="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-white p-3 md:static md:justify-end md:border-0 md:bg-transparent md:p-0">
                <Link
                    :href="editing ? route('crm.quotations.show', quotation.id) : route('crm.quotations.index')"
                    class="inline-flex h-10 flex-1 items-center justify-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 md:flex-none"
                >Cancel</Link>
                <AppButton type="submit" :loading="form.processing" class="flex-1 md:flex-none">{{ editing ? 'Save changes' : 'Save draft' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
