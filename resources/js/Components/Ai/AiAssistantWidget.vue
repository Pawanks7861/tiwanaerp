<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const canUse = computed(() => !!page.props.aiAssistant?.can_use);

const open = ref(false);
const minimized = ref(false);
const maximized = ref(false);
const busy = ref(false);
const error = ref('');
const welcome = ref('');
const suggestions = ref([]);
const conversations = ref([]);
const conversationId = ref(null);
const messages = ref([]);
const draft = ref('');
const showHistory = ref(false);
const threadEl = ref(null);

const pos = ref({ x: null, y: null });
const dragging = ref(false);
let dragMoved = false;
let dragOffset = { x: 0, y: 0 };

const storageKey = computed(() => `tiwana-smart-pos-${page.props.auth?.user?.id || 'anon'}`);

const projectId = computed(() => {
    const p = page.props.project;
    return p?.id ? Number(p.id) : null;
});

const pageContext = computed(() => {
    try {
        return route().current() || 'app';
    } catch {
        return 'app';
    }
});

const fabStyle = computed(() => {
    if (pos.value.x == null || pos.value.y == null) {
        return { right: '1.25rem', bottom: '1.25rem' };
    }
    return { left: `${pos.value.x}px`, top: `${pos.value.y}px`, right: 'auto', bottom: 'auto' };
});

const panelClass = computed(() => {
    if (maximized.value) {
        return 'right-4 bottom-4 top-4 left-4 sm:left-auto sm:w-[min(42rem,calc(100vw-2rem))] h-auto';
    }
    return 'right-4 bottom-20 h-[min(32rem,calc(100vh-6rem))] w-[min(24rem,calc(100vw-2rem))] sm:right-6';
});

onMounted(() => {
    try {
        const raw = localStorage.getItem(storageKey.value);
        if (raw) pos.value = JSON.parse(raw);
    } catch {
        // ignore
    }
});

watch(canUse, (ok) => {
    if (ok && open.value) bootstrap();
});

watch(messages, async () => {
    await nextTick();
    if (threadEl.value) {
        threadEl.value.scrollTop = threadEl.value.scrollHeight;
    }
}, { deep: true });

async function bootstrap() {
    error.value = '';
    try {
        const { data } = await window.axios.get(route('ai.bootstrap'), {
            params: { project_id: projectId.value, page_context: pageContext.value },
        });
        welcome.value = data.welcome;
        suggestions.value = data.suggestions || [];
        conversations.value = data.conversations || [];
        if (!data.config?.enabled) {
            error.value = 'Smart Assistant is currently unavailable.';
        }
        if (messages.value.length === 0 && welcome.value) {
            messages.value = [{ role: 'assistant', content: welcome.value }];
        }
    } catch (e) {
        error.value = e.response?.data?.message || 'Could not load the Smart Assistant.';
    }
}

function toggle() {
    if (dragMoved) {
        dragMoved = false;
        return;
    }
    open.value = !open.value;
    minimized.value = false;
    if (open.value) bootstrap();
}

function newChat() {
    conversationId.value = null;
    messages.value = welcome.value ? [{ role: 'assistant', content: welcome.value }] : [];
    showHistory.value = false;
    error.value = '';
}

async function loadConversation(id) {
    busy.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.get(route('ai.conversations.show', id));
        conversationId.value = data.conversation.id;
        messages.value = data.messages || [];
        showHistory.value = false;
    } catch (e) {
        error.value = e.response?.data?.message || 'Could not open that conversation.';
    } finally {
        busy.value = false;
    }
}

async function clearHistory() {
    if (!conversations.value.length) return;
    busy.value = true;
    try {
        await Promise.all(
            conversations.value.map((c) => window.axios.delete(route('ai.conversations.destroy', c.id))),
        );
        conversations.value = [];
        newChat();
    } catch (e) {
        error.value = e.response?.data?.message || 'Could not clear chat history.';
    } finally {
        busy.value = false;
    }
}

async function send(text) {
    const message = (text ?? draft.value).trim();
    if (!message || busy.value) return;
    draft.value = '';
    messages.value.push({ role: 'user', content: message });
    busy.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(route('ai.ask'), {
            message,
            conversation_id: conversationId.value,
            project_id: projectId.value,
            page_context: pageContext.value,
        });
        conversationId.value = data.conversation.id;
        messages.value.push({ role: 'assistant', content: data.reply });
        suggestions.value = data.suggestions || suggestions.value;
        await bootstrap();
    } catch (e) {
        const msg = e.response?.data?.errors?.ai?.[0]
            || e.response?.data?.errors?.message?.[0]
            || e.response?.data?.message
            || 'The assistant could not answer right now.';
        error.value = msg;
        messages.value.push({ role: 'assistant', content: msg });
    } finally {
        busy.value = false;
    }
}

function copyLast() {
    const last = [...messages.value].reverse().find((m) => m.role === 'assistant');
    if (last?.content) navigator.clipboard?.writeText(last.content);
}

function onFabPointerDown(event) {
    if (event.button !== 0) return;
    dragging.value = true;
    dragMoved = false;
    const el = event.currentTarget.getBoundingClientRect();
    dragOffset = { x: event.clientX - el.left, y: event.clientY - el.top };
    window.addEventListener('pointermove', onFabPointerMove);
    window.addEventListener('pointerup', onFabPointerUp);
}

function onFabPointerMove(event) {
    if (!dragging.value) return;
    dragMoved = true;
    pos.value = {
        x: Math.min(window.innerWidth - 56, Math.max(8, event.clientX - dragOffset.x)),
        y: Math.min(window.innerHeight - 56, Math.max(8, event.clientY - dragOffset.y)),
    };
}

function onFabPointerUp() {
    dragging.value = false;
    window.removeEventListener('pointermove', onFabPointerMove);
    window.removeEventListener('pointerup', onFabPointerUp);
    try {
        localStorage.setItem(storageKey.value, JSON.stringify(pos.value));
    } catch {
        // ignore
    }
}

onBeforeUnmount(() => {
    window.removeEventListener('pointermove', onFabPointerMove);
    window.removeEventListener('pointerup', onFabPointerUp);
});
</script>

<template>
    <div v-if="canUse" class="pointer-events-none fixed inset-0 z-[60]">
        <button
            type="button"
            class="pointer-events-auto absolute flex h-12 w-12 items-center justify-center rounded-full bg-brand-600 text-white shadow-lg hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400"
            :style="fabStyle"
            data-tour="smart-assistant"
            aria-label="Open Tiwana Smart Assistant"
            @pointerdown="onFabPointerDown"
            @click="toggle"
        >
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41m11.32-11.32 1.41-1.41" />
                <circle cx="12" cy="12" r="5" />
                <circle cx="10" cy="11" r=".6" fill="currentColor" stroke="none" />
                <circle cx="14" cy="11" r=".6" fill="currentColor" stroke="none" />
                <path stroke-linecap="round" d="M10 14.2c.6.5 1.4.5 2 0" />
            </svg>
        </button>

        <div
            v-if="open && !minimized"
            class="pointer-events-auto absolute flex flex-col overflow-hidden rounded-2xl border border-line bg-white shadow-2xl"
            :class="panelClass"
        >
            <header class="flex items-center gap-2 border-b border-line bg-slate-50 px-3 py-2">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-slate-900">Tiwana Smart Assistant</p>
                    <p class="truncate text-[11px] text-slate-500">Local · ERP help & authorized live data</p>
                </div>
                <button type="button" class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-200" @click="showHistory = !showHistory">History</button>
                <button type="button" class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-200" @click="newChat">New</button>
                <button type="button" class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-200" @click="maximized = !maximized">{{ maximized ? '❐' : '▣' }}</button>
                <button type="button" class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-200" @click="minimized = true">_</button>
                <button type="button" class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-200" @click="open = false">✕</button>
            </header>

            <div v-if="showHistory" class="max-h-44 overflow-y-auto border-b border-line bg-white text-sm">
                <div class="flex items-center justify-between px-3 py-2">
                    <span class="text-xs font-medium text-slate-500">Previous chats</span>
                    <button type="button" class="text-xs text-rose-600 hover:underline" :disabled="busy" @click="clearHistory">Clear all</button>
                </div>
                <button
                    v-for="c in conversations"
                    :key="c.id"
                    type="button"
                    class="block w-full truncate px-3 py-2 text-left hover:bg-slate-50"
                    @click="loadConversation(c.id)"
                >
                    {{ c.title || `Chat #${c.id}` }}
                </button>
                <p v-if="!conversations.length" class="px-3 py-2 text-xs text-slate-500">No previous chats.</p>
            </div>

            <div ref="threadEl" class="flex-1 space-y-3 overflow-y-auto px-3 py-3 text-sm">
                <div
                    v-for="(m, i) in messages"
                    :key="i"
                    class="rounded-xl px-3 py-2 whitespace-pre-wrap"
                    :class="m.role === 'user' ? 'ml-6 bg-brand-50 text-slate-900' : 'mr-4 bg-slate-100 text-slate-800'"
                >
                    {{ m.content }}
                </div>
                <p v-if="busy" class="text-xs text-slate-500">Looking that up…</p>
                <p v-if="error" class="rounded-lg border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-900">{{ error }}</p>
            </div>

            <div v-if="suggestions.length" class="flex flex-wrap gap-1 border-t border-line px-2 py-2">
                <button
                    v-for="s in suggestions"
                    :key="s"
                    type="button"
                    class="rounded-full border border-line bg-white px-2 py-1 text-[11px] text-slate-700 hover:bg-slate-50"
                    :disabled="busy"
                    @click="send(s)"
                >
                    {{ s }}
                </button>
            </div>

            <form class="flex gap-2 border-t border-line p-2" @submit.prevent="send()">
                <input
                    v-model="draft"
                    type="text"
                    class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-200"
                    placeholder="Ask about workflows or your ERP data…"
                    :disabled="busy"
                />
                <button type="button" class="rounded-lg px-2 text-xs text-slate-500 hover:bg-slate-100" title="Copy last answer" @click="copyLast">Copy</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-3 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50" :disabled="busy || !draft.trim()">
                    Send
                </button>
            </form>
        </div>

        <button
            v-if="open && minimized"
            type="button"
            class="pointer-events-auto absolute right-4 bottom-20 rounded-full border border-line bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow sm:right-6"
            @click="minimized = false"
        >
            Expand Smart Assistant
        </button>
    </div>
</template>
