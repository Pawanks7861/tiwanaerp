<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppBadge from '@/Components/UI/AppBadge.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions } from '@/lib/permissions';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    role: { type: Object, default: null },
    catalog: { type: Array, required: true }, // [{ group, modules: [{ key, label, actions }] }]
});

const { can } = usePermissions();
const editing = !!props.role;
const readonly = !!props.role?.readonly;

const form = useForm({
    name: props.role?.name ?? '',
    description: props.role?.description ?? '',
    permissions: [...(props.role?.permissions ?? [])],
});

const selected = computed(() => new Set(form.permissions));
const filter = ref('');

const groups = computed(() => {
    const q = filter.value.trim().toLowerCase();

    return props.catalog
        .map((g) => ({ ...g, modules: q ? g.modules.filter((m) => `${g.group} ${m.label} ${m.key}`.toLowerCase().includes(q)) : g.modules }))
        .filter((g) => g.modules.length);
});

const actionLabel = (action) => action.replace(/_/g, ' ');
const perm = (module, action) => `${module.key}.${action}`;
// Users can only grant permissions they hold themselves (checked again on the server).
const grantable = (name) => can(name);

function toggle(name) {
    if (readonly || !grantable(name)) {
        return;
    }
    form.permissions = selected.value.has(name) ? form.permissions.filter((p) => p !== name) : [...form.permissions, name];
}

function moduleState(module) {
    const names = module.actions.map((a) => perm(module, a));
    const count = names.filter((n) => selected.value.has(n)).length;

    return count === 0 ? 'none' : count === names.length ? 'all' : 'some';
}

function setMany(names, on) {
    if (readonly) {
        return;
    }
    const allowed = names.filter(grantable);
    const set = new Set(form.permissions);
    allowed.forEach((n) => (on ? set.add(n) : set.delete(n)));
    form.permissions = [...set];
}

const toggleModule = (module) => setMany(module.actions.map((a) => perm(module, a)), moduleState(module) !== 'all');
const groupNames = (group) => group.modules.flatMap((m) => m.actions.map((a) => perm(m, a)));
const groupAll = (group) => groupNames(group).every((n) => selected.value.has(n));

function submit() {
    if (editing) {
        form.put(route('admin.roles.update', props.role.id));
    } else {
        form.post(route('admin.roles.store'));
    }
}
</script>

<template>
    <AppLayout :title="editing ? role.name : 'New role'">
        <PageHeader
            :title="editing ? role.name : 'New role'"
            :subtitle="readonly ? 'This role cannot be changed.' : `${form.permissions.length} permissions selected`"
            :back="route('admin.roles.index')"
        >
            <template #actions>
                <AppBadge v-if="role?.is_system" color="slate">System role</AppBadge>
            </template>
        </PageHeader>

        <form class="space-y-4" @submit.prevent="submit">
            <AppCard title="Role">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormInput
                        v-model="form.name"
                        label="Role name"
                        required
                        maxlength="100"
                        :disabled="readonly || role?.is_system"
                        :error="form.errors.name"
                        :help="role?.is_system ? 'System role names cannot be changed.' : null"
                    />
                    <FormInput v-model="form.description" label="Description" maxlength="255" :disabled="readonly" :error="form.errors.description" />
                </div>
            </AppCard>

            <AppCard title="Permissions" :padded="false">
                <template #actions>
                    <div class="relative">
                        <Icon name="search" :size="14" class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="filter"
                            type="search"
                            placeholder="Filter modules"
                            class="w-44 rounded-md border-slate-300 py-1.5 pr-2 pl-8 text-sm focus:border-brand-500 focus:ring-brand-200"
                        />
                    </div>
                </template>
                <p v-if="form.errors.permissions" class="border-b border-line bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.permissions }}</p>

                <div v-for="group in groups" :key="group.group" class="border-b border-line last:border-b-0">
                    <div class="flex items-center justify-between bg-slate-50 px-4 py-2">
                        <h3 class="text-xs font-semibold tracking-wider text-slate-600 uppercase">{{ group.group }}</h3>
                        <button
                            v-if="!readonly"
                            type="button"
                            class="text-xs font-medium text-brand-600 hover:text-brand-700"
                            @click="setMany(groupNames(group), !groupAll(group))"
                        >
                            {{ groupAll(group) ? 'Clear all' : 'Select all' }}
                        </button>
                    </div>
                    <ul class="divide-y divide-line">
                        <li v-for="module in group.modules" :key="module.key" class="flex flex-col gap-2 px-4 py-3 md:flex-row md:items-center">
                            <label class="flex w-56 shrink-0 items-center gap-2 text-sm font-medium text-slate-800">
                                <input
                                    type="checkbox"
                                    class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                    :checked="moduleState(module) === 'all'"
                                    :indeterminate="moduleState(module) === 'some'"
                                    :disabled="readonly"
                                    @change="toggleModule(module)"
                                />
                                {{ module.label }}
                            </label>
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="action in module.actions"
                                    :key="action"
                                    type="button"
                                    class="rounded-full border px-2.5 py-1 text-xs font-medium capitalize transition"
                                    :class="[
                                        selected.has(perm(module, action)) ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-line bg-white text-slate-600',
                                        readonly || !grantable(perm(module, action)) ? 'cursor-not-allowed opacity-50' : 'hover:border-brand-300',
                                    ]"
                                    :title="!grantable(perm(module, action)) ? 'You do not hold this permission, so you cannot grant it.' : perm(module, action)"
                                    :aria-pressed="selected.has(perm(module, action))"
                                    @click="toggle(perm(module, action))"
                                >
                                    {{ actionLabel(action) }}
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
            </AppCard>

            <div v-if="!readonly" class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <AppButton variant="secondary" :href="route('admin.roles.index')">Cancel</AppButton>
                <AppButton type="submit" :loading="form.processing">{{ editing ? 'Save role' : 'Create role' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
