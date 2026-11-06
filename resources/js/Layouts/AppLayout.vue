<script setup>
import AiAssistantWidget from '@/Components/Ai/AiAssistantWidget.vue';
import CompanySwitcher from '@/Components/Layout/CompanySwitcher.vue';
import ProjectSwitcher from '@/Components/Layout/ProjectSwitcher.vue';
import UserOnboardingTour from '@/Components/Tutorial/UserOnboardingTour.vue';
import AppDropdown from '@/Components/UI/AppDropdown.vue';
import FlashMessages from '@/Components/UI/FlashMessages.vue';
import Icon from '@/Components/UI/Icon.vue';
import PageSkeleton from '@/Components/UI/Skeleton/PageSkeleton.vue';
import { registerStoredPush } from '@/lib/fcm';
import { initials } from '@/lib/format';
import { buildNavigation } from '@/lib/navigation';
import { usePermissions } from '@/lib/permissions';
import { scrollToActiveSidebarItem } from '@/lib/scrollToActiveSidebarItem';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';

defineProps({
    title: { type: String, default: null },
});

const SIDEBAR_COLLAPSED_KEY = 'sidebar_collapsed';
const page = usePage();
const { can } = usePermissions();
const sidebarOpen = ref(false);
const sidebarCollapsed = ref(false);
const sidebarNav = ref(null);
let positionedForUrl = null;

function readSidebarCollapsed() {
    try {
        return window.localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1';
    } catch {
        return false;
    }
}

function toggleSidebarCollapsed() {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    try {
        window.localStorage.setItem(SIDEBAR_COLLAPSED_KEY, sidebarCollapsed.value ? '1' : '0');
    } catch {
        // Ignore storage failures (private mode / blocked storage).
    }
}

function positionActiveSidebarItem({ instant = false } = {}) {
    const nav = sidebarNav.value;
    const url = page.url;

    if (!nav) {
        return;
    }

    if (positionedForUrl === url) {
        return;
    }

    positionedForUrl = url;
    nextTick(() => {
        scrollToActiveSidebarItem(sidebarNav.value, { instant });
    });
}

const user = computed(() => page.props.auth.user);
const unread = computed(() => page.props.unreadNotifications ?? 0);
const branding = computed(() => page.props.branding ?? { logo_url: null, favicon_url: null });
const companyName = computed(() => page.props.company?.current?.name ?? '');
const appName = computed(() => page.props.app?.name || 'Tiwana Construction ERP');
const multiCompany = computed(() => page.props.features?.multi_company === true);
const advancedBoqCalculations = computed(() => page.props.features?.advanced_boq_calculations === true);
const chatUnread = ref(page.props.chatUnread ?? 0);
const showProjectSwitcher = computed(() => can('projects.view'));
const recommendTwoFactor = computed(() => page.props.auth?.two_factor_recommended === true);

watch(() => page.props.chatUnread, (count) => {
    chatUnread.value = count ?? 0;
});

let presenceTimer;
function refreshChat() {
    if (document.hidden) {
        return;
    }
    window.axios.post(route('chat.heartbeat')).catch(() => {});
    window.axios.get(route('chat.unread')).then((response) => {
        chatUnread.value = response.data.count ?? 0;
    }).catch(() => {});
}

// Recomputed on every visit so "active" follows the current URL.
const navigation = computed(() => {
    void page.url;

    return buildNavigation({
        can,
        isSuperAdmin: !!user.value?.is_super_admin,
        multiCompany: multiCompany.value,
        advancedBoqCalculations: advancedBoqCalculations.value,
    });
});

const removeListener = router.on('navigate', () => (sidebarOpen.value = false));

/** false | 'page' | 'section'. Page overlays main; section lets tables/cards skeleton in place. */
const contentSkeleton = ref(false);
provide('contentSkeleton', contentSkeleton);
let skeletonTimer = null;
let visitStarted = false;

function clearSkeletonTimer() {
    if (skeletonTimer) {
        clearTimeout(skeletonTimer);
        skeletonTimer = null;
    }
}

const removeStart = router.on('start', (event) => {
    const visit = event.detail?.visit;
    if (visit?.prefetch) {
        return;
    }
    visitStarted = true;
    clearSkeletonTimer();
    skeletonTimer = setTimeout(() => {
        if (!visitStarted) {
            return;
        }
        contentSkeleton.value = visit?.preserveState ? 'section' : 'page';
    }, 120);
});

function endVisit() {
    visitStarted = false;
    clearSkeletonTimer();
    contentSkeleton.value = false;
}

const removeFinish = router.on('finish', endVisit);
const removeError = router.on('error', endVisit);
const removeInvalid = router.on('invalid', endVisit);
const removeException = router.on('exception', endVisit);
onMounted(() => {
    sidebarCollapsed.value = readSidebarCollapsed();
    positionActiveSidebarItem({ instant: true });
    refreshChat();
    presenceTimer = setInterval(refreshChat, 15000);
    registerStoredPush(page.props.fcm?.web).catch(() => {});
});
watch(
    () => page.url,
    () => {
        positionActiveSidebarItem({ instant: false });
    },
);
watch(sidebarOpen, (open) => {
    if (open) {
        nextTick(() => {
            scrollToActiveSidebarItem(sidebarNav.value, { instant: true });
        });
    }
});
onBeforeUnmount(() => {
    removeListener();
    removeStart();
    removeFinish();
    removeError();
    removeInvalid();
    removeException();
    clearSkeletonTimer();
    clearInterval(presenceTimer);
});
</script>

<template>
    <Head v-if="title" :title="title" />
    <Head>
        <link head-key="favicon" rel="icon" :href="branding.favicon_url || '/favicon.svg'" />
    </Head>
    <div class="min-h-screen bg-surface">
        <FlashMessages />

        <!-- Mobile sidebar backdrop -->
        <Transition enter-active-class="duration-200" enter-from-class="opacity-0" leave-active-class="duration-150" leave-to-class="opacity-0">
            <div v-if="sidebarOpen" class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden" @click="sidebarOpen = false" />
        </Transition>

        <!-- Sidebar -->
        <aside
            data-tour="sidebar"
            class="fixed inset-y-0 left-0 z-50 flex flex-col bg-navy-900 transition-[transform,width] duration-200 ease-out lg:translate-x-0"
            :class="[
                sidebarOpen ? 'translate-x-0' : '-translate-x-full',
                sidebarCollapsed ? 'w-64 lg:w-[4.5rem]' : 'w-64',
            ]"
        >
            <div class="flex h-14 shrink-0 items-center justify-between px-4" :class="sidebarCollapsed ? 'lg:justify-center lg:px-2' : ''">
                <Link :href="route('dashboard')" class="flex min-w-0 flex-1 items-center gap-2" :class="sidebarCollapsed ? 'lg:flex-none lg:justify-center' : ''" :aria-label="companyName ? `${appName}, ${companyName}` : appName" :title="appName">
                    <img v-if="branding.logo_url" :src="branding.logo_url" alt="" class="h-8 w-8 shrink-0 rounded-lg bg-white object-contain" />
                    <span v-else class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-accent-500 text-sm font-black text-white" aria-hidden="true">T</span>
                    <span class="line-clamp-2 min-w-0 text-[13px] font-bold leading-tight tracking-tight text-white" :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ appName }}</span>
                </Link>
                <button type="button" class="rounded-md p-1 text-white/70 hover:bg-white/10 lg:hidden" aria-label="Close menu" @click="sidebarOpen = false">
                    <Icon name="close" />
                </button>
            </div>

            <div v-if="multiCompany" class="border-y border-white/10 px-2 py-2" :class="sidebarCollapsed ? 'lg:hidden' : ''">
                <CompanySwitcher />
            </div>

            <nav ref="sidebarNav" class="sidebar-scroll flex-1 space-y-5 overflow-x-hidden overflow-y-auto px-2 py-4" aria-label="Main">
                <div v-for="(section, i) in navigation" :key="section.title ?? i">
                    <p
                        v-if="section.title"
                        class="mb-1 px-3 text-[11px] font-semibold tracking-wider text-white/40 uppercase"
                        :class="sidebarCollapsed ? 'lg:hidden' : ''"
                    >
                        {{ section.title }}
                    </p>
                    <ul class="space-y-0.5">
                        <li v-for="item in section.items" :key="item.href">
                            <Link
                                :href="item.href"
                                class="relative flex items-center rounded-lg py-2 text-sm font-medium transition"
                                :class="[
                                    item.active ? 'bg-white/10 text-white' : 'text-white/70 hover:bg-white/5 hover:text-white',
                                    sidebarCollapsed ? 'gap-3 px-3 lg:justify-center lg:px-2' : 'gap-3 px-3',
                                ]"
                                :title="sidebarCollapsed ? item.label : undefined"
                                :aria-label="item.label"
                                :aria-current="item.active ? 'page' : undefined"
                                :data-tour="item.label === 'Projects' ? 'nav-projects' : item.label === 'Reports' ? 'nav-reports' : item.label === 'Dashboard' ? 'nav-dashboard' : undefined"
                            >
                                <Icon :name="item.icon" :size="18" :class="item.active ? 'text-accent-400' : ''" />
                                <span class="truncate" :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ item.label }}</span>
                                <span
                                    v-if="item.label === 'Chat' && chatUnread > 0"
                                    class="flex h-5 min-w-5 items-center justify-center rounded-full bg-accent-500 px-1 text-[10px] font-bold text-white tabular"
                                    :class="sidebarCollapsed ? 'ml-auto lg:absolute lg:top-1 lg:right-1 lg:ml-0 lg:h-4 lg:min-w-4' : 'ml-auto'"
                                >
                                    {{ chatUnread > 99 ? '99+' : chatUnread }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="hidden border-t border-white/10 p-2 lg:block">
                <button
                    type="button"
                    class="flex w-full items-center rounded-lg px-3 py-2 text-sm font-medium text-white/70 transition hover:bg-white/5 hover:text-white"
                    :class="sidebarCollapsed ? 'justify-center px-2' : 'gap-3'"
                    :aria-label="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    :aria-expanded="!sidebarCollapsed"
                    :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    @click="toggleSidebarCollapsed"
                >
                    <Icon :name="sidebarCollapsed ? 'chevron-right' : 'chevron-left'" :size="18" />
                    <span :class="sidebarCollapsed ? 'sr-only' : ''">{{ sidebarCollapsed ? 'Expand' : 'Collapse' }}</span>
                </button>
            </div>
        </aside>

        <div class="transition-[padding] duration-200 ease-out" :class="sidebarCollapsed ? 'lg:pl-[4.5rem]' : 'lg:pl-64'">
            <!-- Top bar -->
            <header class="sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-line bg-white/95 px-3 backdrop-blur sm:gap-3 sm:px-6">
                <button type="button" class="rounded-md p-1.5 text-slate-600 hover:bg-slate-100 lg:hidden" aria-label="Open menu" @click="sidebarOpen = true">
                    <Icon name="menu" />
                </button>

                <div class="min-w-0 flex-1">
                    <ProjectSwitcher v-if="showProjectSwitcher" />
                </div>

                <Link
                    :href="route('chat.index')"
                    class="relative rounded-full p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                    :aria-label="`Chat (${chatUnread} unread)`"
                >
                    <Icon name="chat" />
                    <span
                        v-if="chatUnread > 0"
                        class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-accent-500 px-1 text-[10px] font-bold text-white tabular"
                    >
                        {{ chatUnread > 99 ? '99+' : chatUnread }}
                    </span>
                </Link>

                <Link
                    :href="route('notifications.index')"
                    class="relative rounded-full p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                    :aria-label="`Notifications (${unread} unread)`"
                >
                    <Icon name="bell" />
                    <span
                        v-if="unread > 0"
                        class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-accent-500 px-1 text-[10px] font-bold text-white tabular"
                    >
                        {{ unread > 99 ? '99+' : unread }}
                    </span>
                </Link>

                <AppDropdown width="w-60">
                    <template #trigger>
                        <button type="button" class="flex items-center gap-2 rounded-full p-0.5 hover:bg-slate-100 sm:pr-2" aria-label="Account menu">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">
                                {{ initials(user.name) }}
                            </span>
                            <span class="hidden max-w-32 truncate text-sm font-medium text-slate-700 sm:block">{{ user.name }}</span>
                            <Icon name="chevron-down" :size="16" class="hidden text-slate-400 sm:block" />
                        </button>
                    </template>
                    <div class="border-b border-line px-4 py-3">
                        <p class="truncate text-sm font-medium text-slate-900">{{ user.name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ user.email }}</p>
                    </div>
                    <div class="py-1">
                        <Link :href="route('profile.edit')" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <Icon name="user" :size="16" /> My profile
                        </Link>
                        <Link :href="route('notifications.preferences')" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <Icon name="cog" :size="16" /> Notification preferences
                        </Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                        >
                            <Icon name="logout" :size="16" /> Log out
                        </Link>
                    </div>
                </AppDropdown>
            </header>

            <slot name="header" />

            <main data-tour="main-content" class="relative mx-auto w-full max-w-7xl px-3 py-5 sm:px-6 sm:py-6" :aria-busy="contentSkeleton === 'page'">
                <div v-if="recommendTwoFactor" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    Two-factor authentication is recommended for this account.
                    <Link :href="route('profile.edit')" class="font-semibold underline">Set it up</Link>
                </div>
                <div :class="contentSkeleton === 'page' ? 'invisible' : ''" :aria-hidden="contentSkeleton === 'page'">
                    <slot />
                </div>
                <div v-if="contentSkeleton === 'page'" class="absolute inset-x-3 top-5 sm:inset-x-6">
                    <PageSkeleton />
                </div>
            </main>
        </div>
        <AiAssistantWidget />
        <UserOnboardingTour />
    </div>
</template>
