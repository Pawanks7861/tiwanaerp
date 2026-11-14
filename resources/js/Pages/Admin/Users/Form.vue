<script setup>
import FormInput from '@/Components/Form/FormInput.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import Icon from '@/Components/UI/Icon.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { usePermissions } from '@/lib/permissions';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    user: { type: Object, default: null },
    roles: { type: Array, required: true },
    permissionCatalog: { type: Array, required: true },
    menuCatalog: { type: Array, required: true },
    canEditProfile: { type: Boolean, default: true },
});

const { can } = usePermissions();
const editing = !!props.user;

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    mobile: props.user?.mobile ?? '',
    password: '',
    password_confirmation: '',
    roles: props.user?.roles ?? [],
    permissions: [...(props.user?.permissions ?? [])],
    menu_items: [...(props.user?.menu_items ?? [])],
});

const selectedPermissions = computed(() => new Set(form.permissions));
const selectedMenus = computed(() => new Set(form.menu_items));
const permissionFilter = ref('');
const menuFilter = ref('');

const permissionGroups = computed(() => {
    const q = permissionFilter.value.trim().toLowerCase();

    return props.permissionCatalog
        .map((g) => ({
            ...g,
            modules: q ? g.modules.filter((m) => `${g.group} ${m.label} ${m.key}`.toLowerCase().includes(q)) : g.modules,
        }))
        .filter((g) => g.modules.length);
});

const menuGroups = computed(() => {
    const q = menuFilter.value.trim().toLowerCase();

    return props.menuCatalog
        .map((g) => ({
            ...g,
            items: q ? g.items.filter((i) => `${g.group} ${i.label} ${i.key}`.toLowerCase().includes(q)) : g.items,
        }))
        .filter((g) => g.items.length);
});

const actionLabel = (action) => action.replace(/_/g, ' ');
const perm = (module, action) => `${module.key}.${action}`;
const grantable = (name) => can(name);

function togglePermission(name) {
    if (!grantable(name)) {
        return;
    }
    form.permissions = selectedPermissions.value.has(name)
        ? form.permissions.filter((p) => p !== name)
        : [...form.permissions, name];
}

function grantableNames(names) {
    return names.filter(grantable);
}

function moduleGrantableNames(module) {
    return grantableNames(module.actions.map((a) => perm(module, a)));
}

function moduleState(module) {
    const names = moduleGrantableNames(module);
    if (names.length === 0) {
        const all = module.actions.map((a) => perm(module, a));
        const count = all.filter((n) => selectedPermissions.value.has(n)).length;

        return count === 0 ? 'none' : count === all.length ? 'all' : 'some';
    }
    const count = names.filter((n) => selectedPermissions.value.has(n)).length;

    return count === 0 ? 'none' : count === names.length ? 'all' : 'some';
}

function setManyPermissions(names, on) {
    const allowed = grantableNames(names);
    if (allowed.length === 0) {
        return;
    }
    const set = new Set(form.permissions);
    allowed.forEach((n) => (on ? set.add(n) : set.delete(n)));
    form.permissions = [...set];
}

const toggleModule = (module) => {
    const names = moduleGrantableNames(module);
    if (names.length === 0) {
        return;
    }
    setManyPermissions(names, moduleState(module) !== 'all');
};

const groupPermissionNames = (group) => group.modules.flatMap((m) => m.actions.map((a) => perm(m, a)));
const groupGrantableNames = (group) => grantableNames(groupPermissionNames(group));
const groupAllPermissions = (group) => {
    const names = groupGrantableNames(group);

    return names.length > 0 && names.every((n) => selectedPermissions.value.has(n));
};

function toggleMenu(key) {
    form.menu_items = selectedMenus.value.has(key) ? form.menu_items.filter((k) => k !== key) : [...form.menu_items, key];
}

function setManyMenus(keys, on) {
    const set = new Set(form.menu_items);
    keys.forEach((k) => (on ? set.add(k) : set.delete(k)));
    form.menu_items = [...set];
}

const menuGroupKeys = (group) => group.items.map((i) => i.key);
const menuGroupAll = (group) => {
    const keys = menuGroupKeys(group);

    return keys.length > 0 && keys.every((k) => selectedMenus.value.has(k));
};

function submit() {
    const options = { onFinish: () => form.reset('password', 'password_confirmation') };
    if (editing) {
        form.put(route('admin.users.update', props.user.id), options);
    } else {
        form.post(route('admin.users.store'), options);
    }
}
</script>

<template>
    <AppLayout :title="editing ? `Edit ${user.name}` : 'Add user'">
        <PageHeader
            :title="editing ? `Edit ${user.name}` : 'Add user'"
            :subtitle="editing ? user.email : 'If the email already has an account (e.g. in another company), that person is added to this company.'"
            :back="route('admin.users.index')"
        />

        <form class="space-y-4" @submit.prevent="submit">
            <AppCard title="Profile">
                <div v-if="editing && !canEditProfile" class="mb-4 flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                    <Icon name="info" class="mt-0.5 text-amber-500" />
                    This person also works for another company, so only they can change their name, email or password. You can still change their roles here.
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormInput v-model="form.name" label="Full name" required maxlength="150" :disabled="editing && !canEditProfile" :error="form.errors.name" autofocus />
                    <FormInput
                        v-model="form.email"
                        label="Email"
                        type="email"
                        required
                        autocomplete="off"
                        :disabled="editing && !canEditProfile"
                        :error="form.errors.email"
                        :hint="user?.is_placeholder_email ? 'Placeholder email — outbound mail is disabled for this address.' : undefined"
                    />
                    <FormInput
                        v-model="form.mobile"
                        label="Mobile"
                        type="tel"
                        inputmode="numeric"
                        maxlength="10"
                        placeholder="98XXXXXXXX"
                        :disabled="editing && !canEditProfile"
                        :error="form.errors.mobile"
                    />
                </div>
            </AppCard>

            <AppCard v-if="!editing || canEditProfile" title="Password" :subtitle="editing ? 'Leave blank to keep the current password.' : 'Required for a new account. Not used if the email already has an account.'">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormInput v-model="form.password" label="Password" type="password" autocomplete="new-password" :error="form.errors.password" help="At least 8 characters with letters and numbers." />
                    <FormInput v-model="form.password_confirmation" label="Confirm password" type="password" autocomplete="new-password" />
                </div>
            </AppCard>

            <AppCard title="Roles in this company" subtitle="Roles decide what the user can see and do. You can only assign roles whose permissions you hold.">
                <p v-if="form.errors.roles" class="mb-3 text-sm text-red-600">{{ form.errors.roles }}</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="role in roles"
                        :key="role.value"
                        class="flex cursor-pointer gap-3 rounded-lg border p-3 transition"
                        :class="form.roles.includes(role.value) ? 'border-brand-300 bg-brand-50' : 'border-line hover:bg-slate-50'"
                    >
                        <input v-model="form.roles" type="checkbox" :value="role.value" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                        <span>
                            <span class="block text-sm font-medium text-slate-800">{{ role.label }}</span>
                            <span v-if="role.description" class="block text-xs text-slate-500">{{ role.description }}</span>
                        </span>
                    </label>
                </div>
            </AppCard>

            <AppCard title="Direct permissions" subtitle="Extra permissions for this user on top of their roles. Controls what they can do." :padded="false">
                <template #actions>
                    <div class="relative">
                        <Icon name="search" :size="14" class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="permissionFilter"
                            type="search"
                            placeholder="Filter modules"
                            class="w-44 rounded-md border-slate-300 py-1.5 pr-2 pl-8 text-sm focus:border-brand-500 focus:ring-brand-200"
                        />
                    </div>
                </template>
                <p v-if="form.errors.permissions" class="border-b border-line bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.permissions }}</p>
                <div v-for="group in permissionGroups" :key="group.group" class="border-b border-line last:border-b-0">
                    <div class="flex items-center justify-between bg-slate-50 px-4 py-2">
                        <h3 class="text-xs font-semibold tracking-wider text-slate-600 uppercase">{{ group.group }}</h3>
                        <button
                            v-if="groupGrantableNames(group).length"
                            type="button"
                            class="text-xs font-medium text-brand-600 hover:text-brand-700"
                            @click="setManyPermissions(groupGrantableNames(group), !groupAllPermissions(group))"
                        >
                            {{ groupAllPermissions(group) ? 'Clear all' : 'Select all' }}
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
                                    :disabled="moduleGrantableNames(module).length === 0"
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
                                        selectedPermissions.has(perm(module, action)) ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-line bg-white text-slate-600',
                                        !grantable(perm(module, action)) ? 'cursor-not-allowed opacity-50' : 'hover:border-brand-300',
                                    ]"
                                    :title="!grantable(perm(module, action)) ? 'You do not hold this permission, so you cannot grant it.' : perm(module, action)"
                                    :aria-pressed="selectedPermissions.has(perm(module, action))"
                                    @click="togglePermission(perm(module, action))"
                                >
                                    {{ actionLabel(action) }}
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
            </AppCard>

            <AppCard
                title="Sidebar menu"
                subtitle="Leave all unchecked to show every menu the user's permissions allow. Check items to limit the sidebar to only those. Dashboard always stays visible."
                :padded="false"
            >
                <template #actions>
                    <div class="relative">
                        <Icon name="search" :size="14" class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="menuFilter"
                            type="search"
                            placeholder="Filter menus"
                            class="w-44 rounded-md border-slate-300 py-1.5 pr-2 pl-8 text-sm focus:border-brand-500 focus:ring-brand-200"
                        />
                    </div>
                </template>
                <p v-if="form.errors.menu_items" class="border-b border-line bg-red-50 px-4 py-2 text-sm text-red-700">{{ form.errors.menu_items }}</p>
                <div v-for="group in menuGroups" :key="group.group" class="border-b border-line last:border-b-0">
                    <div class="flex items-center justify-between bg-slate-50 px-4 py-2">
                        <h3 class="text-xs font-semibold tracking-wider text-slate-600 uppercase">{{ group.group }}</h3>
                        <button
                            type="button"
                            class="text-xs font-medium text-brand-600 hover:text-brand-700"
                            @click="setManyMenus(menuGroupKeys(group), !menuGroupAll(group))"
                        >
                            {{ menuGroupAll(group) ? 'Clear all' : 'Select all' }}
                        </button>
                    </div>
                    <div class="grid gap-2 px-4 py-3 sm:grid-cols-2 lg:grid-cols-3">
                        <label
                            v-for="item in group.items"
                            :key="item.key"
                            class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition"
                            :class="selectedMenus.has(item.key) ? 'border-brand-300 bg-brand-50 text-brand-800' : 'border-line text-slate-700 hover:bg-slate-50'"
                        >
                            <input
                                type="checkbox"
                                class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                :checked="selectedMenus.has(item.key)"
                                @change="toggleMenu(item.key)"
                            />
                            <span>{{ item.label }}</span>
                        </label>
                    </div>
                </div>
            </AppCard>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <AppButton variant="secondary" :href="route('admin.users.index')">Cancel</AppButton>
                <AppButton type="submit" :loading="form.processing">{{ editing ? 'Save changes' : 'Add user' }}</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
