<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const page = usePage();
const state = ref(null);
const open = ref(false);
const paused = ref(false);
const busy = ref(false);
const error = ref('');
const hole = ref({ top: 0, left: 0, width: 0, height: 0 });
const pop = ref({ top: 0, left: 0 });
const reduceMotion = ref(false);

const payload = computed(() => state.value ?? page.props.onboarding ?? { show: false });
const steps = computed(() => payload.value.steps ?? []);
const currentKey = computed(() => payload.value.current_step ?? steps.value[0]?.key ?? 'welcome');
const currentIndex = computed(() => Math.max(0, steps.value.findIndex((s) => s.key === currentKey.value)));
const current = computed(() => steps.value[currentIndex.value] ?? null);
const isFirst = computed(() => currentIndex.value <= 0);
const isLast = computed(() => current.value?.key === 'complete');
const progressLabel = computed(() => {
    const done = payload.value.progress?.done ?? 0;
    const total = payload.value.progress?.total ?? steps.value.filter((s) => s.key !== 'complete').length;
    return `${Math.min(done, total)} / ${total}`;
});
const stepNumber = computed(() => `${currentIndex.value + 1} of ${steps.value.length}`);

let resizeObserver = null;
let repositionTimer = null;

onMounted(() => {
    reduceMotion.value = window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false;
    syncFromPage();
    window.addEventListener('resize', scheduleReposition);
    window.addEventListener('scroll', scheduleReposition, true);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', scheduleReposition);
    window.removeEventListener('scroll', scheduleReposition, true);
    resizeObserver?.disconnect();
    clearTimeout(repositionTimer);
});

watch(() => page.props.onboarding, () => {
    if (!busy.value) {
        syncFromPage();
    }
}, { deep: true });

watch(() => page.url, async () => {
    if (open.value && !paused.value) {
        await nextTick();
        scheduleReposition();
        maybeNavigateForStep(current.value, false);
    }
});

watch(currentKey, async () => {
    if (open.value && !paused.value) {
        await ensureRoute(current.value);
        await nextTick();
        scheduleReposition();
    }
});

function syncFromPage() {
    const data = page.props.onboarding;
    if (!data) {
        open.value = false;
        return;
    }
    state.value = data;
    if (data.show && !data.completed && !data.exempt && !paused.value) {
        open.value = true;
        nextTick(() => {
            ensureRoute(current.value).then(() => scheduleReposition());
        });
    } else if (data.completed || data.exempt || !data.show) {
        open.value = false;
    }
}

function applyPayload(data) {
    state.value = data;
    page.props.onboarding = data;
    if (data.completed || !data.show) {
        open.value = false;
        paused.value = false;
    }
}

async function ensureRoute(step, force = true) {
    if (!step?.route) {
        return;
    }
    try {
        const target = route(step.route, step.route_params || {});
        const currentPath = window.location.pathname + window.location.search;
        const targetUrl = new URL(target, window.location.origin);
        if (!force && currentPath.startsWith(targetUrl.pathname)) {
            return;
        }
        if (currentPath === targetUrl.pathname + targetUrl.search) {
            return;
        }
        await new Promise((resolve, reject) => {
            router.visit(target, {
                preserveState: true,
                preserveScroll: false,
                onFinish: () => resolve(),
                onError: (e) => reject(e),
            });
        });
        await nextTick();
        await new Promise((r) => setTimeout(r, reduceMotion.value ? 50 : 180));
    } catch {
        // Stay on the current page and highlight the nearest available target.
    }
}

function maybeNavigateForStep() {
    // no-op helper for url watch
}

function scheduleReposition() {
    clearTimeout(repositionTimer);
    repositionTimer = setTimeout(() => reposition(), reduceMotion.value ? 0 : 40);
}

function reposition() {
    const step = current.value;
    if (!open.value || !step) {
        return;
    }

    const pad = 8;
    if (!step.target) {
        hole.value = { top: 0, left: 0, width: 0, height: 0 };
        pop.value = {
            top: Math.max(24, window.innerHeight / 2 - 160),
            left: Math.max(16, Math.min(window.innerWidth - 360, window.innerWidth / 2 - 180)),
        };
        return;
    }

    let el = document.querySelector(step.target);
    if (!el) {
        // Fallbacks when project tabs are not mounted
        el = document.querySelector('[data-tour="nav-projects"]')
            || document.querySelector('[data-tour="sidebar"]')
            || document.querySelector('[data-tour="main-content"]');
    }
    if (!el) {
        hole.value = { top: 0, left: 0, width: 0, height: 0 };
        pop.value = { top: 80, left: 24 };
        return;
    }

    el.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reduceMotion.value ? 'auto' : 'smooth' });
    const rect = el.getBoundingClientRect();
    hole.value = {
        top: Math.max(0, rect.top - pad),
        left: Math.max(0, rect.left - pad),
        width: Math.min(window.innerWidth, rect.width + pad * 2),
        height: Math.min(window.innerHeight, rect.height + pad * 2),
    };

    const popW = Math.min(360, window.innerWidth - 24);
    const popH = 220;
    let top = rect.bottom + 12;
    let left = rect.left;
    const placement = step.placement || 'bottom';

    if (placement === 'right') {
        top = Math.max(16, rect.top);
        left = rect.right + 12;
    } else if (placement === 'left') {
        top = Math.max(16, rect.top - 40);
        left = rect.left - popW - 12;
    } else if (placement === 'top') {
        top = rect.top - popH - 12;
        left = rect.left;
    }

    if (left + popW > window.innerWidth - 12) {
        left = window.innerWidth - popW - 12;
    }
    if (left < 12) {
        left = 12;
    }
    if (top + popH > window.innerHeight - 12) {
        top = Math.max(12, rect.top - popH - 12);
    }
    if (top < 12) {
        top = 12;
    }

    pop.value = { top, left };

    resizeObserver?.disconnect();
    resizeObserver = new ResizeObserver(() => scheduleReposition());
    resizeObserver.observe(el);
}

async function startOrNext() {
    if (!current.value || busy.value) {
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        if (current.value.key === 'complete') {
            const { data } = await window.axios.post(route('tutorial.complete'));
            applyPayload(data);
            return;
        }
        const { data } = await window.axios.post(route('tutorial.acknowledge'), { step: current.value.key });
        applyPayload(data);
        open.value = true;
        await ensureRoute(current.value);
        await nextTick();
        scheduleReposition();
    } catch (e) {
        error.value = e.response?.data?.errors?.step?.[0]
            || e.response?.data?.errors?.tutorial?.[0]
            || e.response?.data?.message
            || 'Could not save tutorial progress.';
    } finally {
        busy.value = false;
    }
}

async function previous() {
    if (isFirst.value || busy.value) {
        return;
    }
    const prev = steps.value[currentIndex.value - 1];
    if (!prev) {
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(route('tutorial.goto'), { step: prev.key });
        applyPayload(data);
        open.value = true;
        await ensureRoute(current.value);
        await nextTick();
        scheduleReposition();
    } catch (e) {
        error.value = e.response?.data?.errors?.step?.[0] || e.response?.data?.message || 'Could not go back.';
    } finally {
        busy.value = false;
    }
}

async function pauseTour() {
    paused.value = true;
    open.value = false;
    try {
        await window.axios.post(route('tutorial.pause'));
    } catch {
        // ignore
    }
}

function resumeTour() {
    if (!payload.value.show || payload.value.completed) {
        return;
    }
    paused.value = false;
    open.value = true;
    nextTick(() => {
        ensureRoute(current.value).then(() => scheduleReposition());
    });
}

const primaryLabel = computed(() => {
    if (!current.value) {
        return 'Next';
    }
    if (current.value.key === 'welcome') {
        return 'Start Tutorial';
    }
    if (current.value.key === 'complete') {
        return 'Finish Tutorial';
    }
    return 'Next';
});
</script>

<template>
    <div v-if="payload.show && !payload.completed && !payload.exempt">
        <button
            v-if="paused && !open"
            type="button"
            class="fixed bottom-20 left-4 z-[70] rounded-full border border-line bg-white px-3 py-2 text-xs font-semibold text-slate-800 shadow-lg sm:left-auto sm:right-20"
            @click="resumeTour"
        >
            Resume tutorial ({{ progressLabel }})
        </button>

        <div
            v-if="open"
            class="fixed inset-0 z-[70]"
            role="dialog"
            aria-modal="true"
            aria-labelledby="tour-title"
            aria-describedby="tour-body"
        >
            <!-- Dim overlay with spotlight hole via box-shadow -->
            <div
                class="pointer-events-none absolute rounded-xl ring-2 ring-accent-400/80"
                :class="reduceMotion ? '' : 'transition-all duration-200'"
                :style="{
                    top: `${hole.top}px`,
                    left: `${hole.left}px`,
                    width: `${Math.max(hole.width, 0)}px`,
                    height: `${Math.max(hole.height, 0)}px`,
                    boxShadow: current?.target && hole.width
                        ? '0 0 0 9999px rgba(15, 23, 42, 0.55)'
                        : '0 0 0 9999px rgba(15, 23, 42, 0.55)',
                }"
            />
            <div class="absolute inset-0" aria-hidden="true" @click.prevent />

            <div
                class="pointer-events-auto absolute w-[min(22.5rem,calc(100vw-1.5rem))] rounded-2xl border border-line bg-white p-4 shadow-2xl"
                :style="{ top: `${pop.top}px`, left: `${pop.left}px` }"
            >
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div>
                        <p class="text-[11px] font-semibold tracking-wide text-slate-500 uppercase">{{ stepNumber }} · {{ progressLabel }} done</p>
                        <h2 id="tour-title" class="text-base font-semibold text-slate-900">{{ current?.title }}</h2>
                    </div>
                    <button
                        type="button"
                        class="rounded-md px-2 py-1 text-xs text-slate-500 hover:bg-slate-100"
                        aria-label="Hide tutorial until refresh"
                        @click="pauseTour"
                    >
                        Hide
                    </button>
                </div>
                <p id="tour-body" class="whitespace-pre-wrap text-sm leading-relaxed text-slate-700">{{ current?.body }}</p>
                <p v-if="error" class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-900">{{ error }}</p>
                <div class="mt-4 flex items-center justify-between gap-2">
                    <button
                        type="button"
                        class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 disabled:opacity-40"
                        :disabled="isFirst || busy"
                        @click="previous"
                    >
                        Previous
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50"
                        :disabled="busy"
                        @click="startOrNext"
                    >
                        {{ primaryLabel }}
                    </button>
                </div>
                <p class="mt-2 text-[11px] text-slate-500">Progress is saved. Hiding does not finish the tutorial.</p>
            </div>
        </div>
    </div>
</template>
