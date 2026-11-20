<script setup>
import { scrollToActiveProjectTab } from '@/lib/scrollToActiveProjectTab';
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Icon from './Icon.vue';

/**
 * Tabs as links (each tab is its own page) or buttons when `modelValue` is used.
 * items: [{ key, label, href?, icon?, count?, children?: [{ key, label, href, icon?, active? }] }]
 *
 * When an item has children, the parent opens a click-toggle dropdown (teleported)
 * so it is not clipped by the horizontal scroll container.
 */
const props = defineProps({
    items: { type: Array, required: true },
    active: { type: String, default: null },
});

const model = defineModel({ type: String, default: null });
const tabNav = ref(null);
const openKey = ref(null);
const panelStyle = ref({});
const triggerEls = ref({});
let positionedForKey = null;

const currentKey = computed(() => props.active ?? model.value);

function hasChildren(item) {
    return Array.isArray(item.children) && item.children.length > 0;
}

function isParentActive(item) {
    if (currentKey.value === item.key) {
        return true;
    }

    return hasChildren(item) && item.children.some((child) => child.active || child.key === currentKey.value);
}

function isChildActive(child) {
    return !!child.active || child.key === currentKey.value;
}

function setTriggerEl(key, el) {
    if (el) {
        triggerEls.value[key] = el;
    } else {
        delete triggerEls.value[key];
    }
}

function positionPanel(key) {
    const el = triggerEls.value[key];
    if (!el) {
        return;
    }

    const rect = el.getBoundingClientRect();
    const width = 240;
    const gap = 4;
    let left = rect.left;
    if (left + width > window.innerWidth - 8) {
        left = Math.max(8, rect.right - width);
    }
    if (left < 8) {
        left = 8;
    }

    let top = rect.bottom + gap;
    const estimatedHeight = Math.min(360, 12 + (triggerEls.value[key] ? 40 : 40));
    if (top + estimatedHeight > window.innerHeight - 8) {
        top = Math.max(8, rect.top - estimatedHeight - gap);
    }

    panelStyle.value = {
        position: 'fixed',
        top: `${Math.round(top)}px`,
        left: `${Math.round(left)}px`,
        width: `${width}px`,
        zIndex: 60,
    };
}

function toggleDropdown(item, event) {
    event.preventDefault();
    event.stopPropagation();

    if (openKey.value === item.key) {
        openKey.value = null;

        return;
    }

    openKey.value = item.key;
    nextTick(() => positionPanel(item.key));
}

function closeDropdown() {
    openKey.value = null;
}

function onDocumentPointer(event) {
    if (!openKey.value) {
        return;
    }

    const trigger = triggerEls.value[openKey.value];
    const panel = document.getElementById(`app-tabs-dropdown-${openKey.value}`);
    if (trigger?.contains(event.target) || panel?.contains(event.target)) {
        return;
    }

    closeDropdown();
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        closeDropdown();
    }
}

function onViewportChange() {
    if (openKey.value) {
        positionPanel(openKey.value);
    }
}

function positionKey() {
    const key = currentKey.value;
    const firstHref = props.items?.[0]?.href ?? '';

    return `${firstHref}::${key}`;
}

function positionActiveTab({ instant = false } = {}) {
    const key = positionKey();

    if (positionedForKey === key) {
        return;
    }

    positionedForKey = key;
    nextTick(() => {
        scrollToActiveProjectTab(tabNav.value, { instant });
    });
}

onMounted(() => {
    positionActiveTab({ instant: true });
    document.addEventListener('mousedown', onDocumentPointer);
    document.addEventListener('keydown', onKeydown);
    window.addEventListener('resize', onViewportChange);
    window.addEventListener('scroll', onViewportChange, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentPointer);
    document.removeEventListener('keydown', onKeydown);
    window.removeEventListener('resize', onViewportChange);
    window.removeEventListener('scroll', onViewportChange, true);
});

watch(
    () => currentKey.value,
    () => {
        closeDropdown();
        positionActiveTab({ instant: false });
    },
);

watch(
    () => props.items,
    () => {
        // Recompute child active states after Inertia navigations recreate items.
        if (openKey.value) {
            closeDropdown();
        }
    },
);
</script>

<template>
    <nav ref="tabNav" class="-mb-px flex gap-1 overflow-x-auto" aria-label="Tabs">
        <template v-for="(item, index) in items" :key="item.key">
            <span
                v-if="item.group && item.group !== items[index - 1]?.group"
                class="inline-flex shrink-0 items-end border-b-2 border-transparent px-2 pb-2.5 text-[10px] font-semibold tracking-wide text-slate-400 uppercase"
                aria-hidden="true"
            >
                {{ item.group }}
            </span>

            <button
                v-if="hasChildren(item)"
                :ref="(el) => setTriggerEl(item.key, el)"
                type="button"
                class="inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition"
                :class="
                    isParentActive(item)
                        ? 'border-accent-500 text-slate-900'
                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                "
                :aria-expanded="openKey === item.key"
                :aria-haspopup="true"
                :aria-controls="`app-tabs-dropdown-${item.key}`"
                @click="toggleDropdown(item, $event)"
            >
                <Icon v-if="item.icon" :name="item.icon" :size="16" />
                {{ item.label }}
                <Icon name="chevron-down" :size="14" class="opacity-70 transition" :class="openKey === item.key ? 'rotate-180' : ''" />
            </button>

            <component
                :is="item.href ? Link : 'button'"
                v-else
                :href="item.href"
                :type="item.href ? undefined : 'button'"
                class="inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition"
                :class="
                    currentKey === item.key
                        ? 'border-accent-500 text-slate-900'
                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                "
                :aria-current="currentKey === item.key ? 'page' : undefined"
                @click="!item.href && (model = item.key)"
            >
                <Icon v-if="item.icon" :name="item.icon" :size="16" />
                {{ item.label }}
                <span
                    v-if="item.count !== undefined && item.count !== null"
                    class="rounded-full bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600 tabular"
                >
                    {{ item.count }}
                </span>
            </component>
        </template>
    </nav>

    <Teleport to="body">
        <div
            v-for="item in items.filter(hasChildren)"
            :id="`app-tabs-dropdown-${item.key}`"
            :key="`panel-${item.key}`"
            v-show="openKey === item.key"
            role="menu"
            :aria-label="item.label"
            class="overflow-hidden rounded-lg border border-line bg-white py-1 shadow-lg"
            :style="openKey === item.key ? panelStyle : undefined"
        >
            <Link
                v-for="child in item.children"
                :key="child.key"
                :href="child.href"
                role="menuitem"
                class="flex items-center gap-2 px-3 py-2 text-sm transition"
                :class="
                    isChildActive(child)
                        ? 'bg-brand-50 font-medium text-brand-800'
                        : 'text-slate-700 hover:bg-slate-50'
                "
                :aria-current="isChildActive(child) ? 'page' : undefined"
                @click="closeDropdown"
            >
                <Icon v-if="child.icon" :name="child.icon" :size="16" class="shrink-0 text-slate-500" />
                <span class="truncate">{{ child.label }}</span>
            </Link>
        </div>
    </Teleport>
</template>
