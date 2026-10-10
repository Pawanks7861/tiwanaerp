<script setup>
import UniversalFileViewer from '@/Components/Files/UniversalFileViewer.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import Icon from '@/Components/UI/Icon.vue';
import LargeFileUploader from '@/Components/Uploads/LargeFileUploader.vue';
import { initials } from '@/lib/format';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    conversations: { type: Array, required: true },
    active: { type: Object, default: null },
});

const page = usePage();
const me = computed(() => page.props.auth.user.id);
const list = ref(props.conversations);
const messages = ref(props.active?.page?.messages ?? []);
const hasMore = ref(props.active?.page?.has_more ?? false);
const query = ref('');
const people = ref([]);
const body = ref('');
const uploadIds = ref([]);
const uploaderKey = ref(0);
const replyTo = ref(null);
const error = ref('');
const sending = ref(false);
const viewing = ref(null);
const attachmentGallery = computed(() => messages.value.flatMap((message) => (message.attachments ?? []).map((file) => ({ source: 'chat', id: file.id }))));
const scroller = ref(null);

watch(() => props.conversations, (rows) => {
    list.value = rows;
});
watch(() => props.active?.id, () => {
    messages.value = props.active?.page?.messages ?? [];
    hasMore.value = props.active?.page?.has_more ?? false;
    replyTo.value = null;
    error.value = '';
});

const peer = computed(() => list.value.find((row) => row.id === props.active?.id)?.peer ?? props.active?.peer ?? null);

function seen(person) {
    if (!person) {
        return '';
    }
    if (person.online) {
        return 'Online';
    }
    if (!person.last_seen_at) {
        return 'Offline';
    }
    return `Last seen ${new Date(person.last_seen_at).toLocaleString()}`;
}

let searchTimer;
function search() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
        const response = await window.axios.get(route('chat.directory'), { params: { q: query.value } });
        people.value = response.data.users;
    }, 250);
}

async function openWith(userId) {
    const response = await window.axios.post(route('chat.direct'), { user_id: userId });
    router.visit(response.data.url);
}

function onUploaded(file) {
    if (!uploadIds.value.includes(file.id) && uploadIds.value.length < 5) {
        uploadIds.value = [...uploadIds.value, file.id];
    }
}

function onRemoved(file) {
    uploadIds.value = uploadIds.value.filter((id) => id !== file.id);
}

async function send() {
    if (!props.active || sending.value) {
        return;
    }
    sending.value = true;
    error.value = '';
    const data = new FormData();
    data.append('body', body.value);
    if (replyTo.value) {
        data.append('reply_to_message_id', replyTo.value.id);
    }
    uploadIds.value.forEach((id) => data.append('upload_ids[]', id));
    try {
        const response = await window.axios.post(route('chat.messages.store', props.active.id), data);
        messages.value = [...messages.value, response.data.message];
        body.value = '';
        uploadIds.value = [];
        uploaderKey.value += 1;
        replyTo.value = null;
        router.reload({ only: ['conversations'], preserveState: true, preserveScroll: true });
    } catch (e) {
        const errors = e.response?.data?.errors ?? {};
        error.value = errors.body?.[0] || errors.files?.[0] || errors.user_id?.[0] || 'The message could not be sent.';
    } finally {
        sending.value = false;
    }
}

async function loadOlder() {
    const first = messages.value[0];
    if (!props.active || !first) {
        return;
    }
    const response = await window.axios.get(route('chat.messages.index', props.active.id), { params: { before: first.id } });
    const older = response.data.messages.filter((row) => !messages.value.some((have) => have.id === row.id));
    messages.value = [...older, ...messages.value];
    hasMore.value = response.data.has_more;
}

async function pollThread() {
    if (!props.active || document.hidden) {
        return;
    }
    const last = messages.value.at(-1)?.id ?? 0;
    const response = await window.axios.get(route('chat.messages.index', props.active.id), {
        params: { after: last, reading: 1 },
    });
    if (response.data.messages?.length) {
        const fresh = response.data.messages.filter((row) => !messages.value.some((have) => have.id === row.id));
        messages.value = [...messages.value, ...fresh];
    }
}

function pollList() {
    if (document.hidden) {
        return;
    }
    router.reload({ only: ['conversations'], preserveState: true, preserveScroll: true });
}

function size(bytes) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    return `${Math.round(bytes / 1024)} KB`;
}

let threadTimer;
let listTimer;
onMounted(() => {
    search();
    threadTimer = setInterval(pollThread, document.hidden ? 20000 : 4000);
    listTimer = setInterval(pollList, document.hidden ? 45000 : 12000);
});
onBeforeUnmount(() => {
    clearInterval(threadTimer);
    clearInterval(listTimer);
    clearTimeout(searchTimer);
});
</script>

<template>
    <AppLayout title="Chat">
        <Head title="Chat" />
        <div class="flex h-[calc(100dvh-8.5rem)] min-h-[24rem] overflow-hidden rounded-xl border border-line bg-white">
            <aside class="flex w-full min-w-0 flex-col border-line md:w-80 md:border-r" :class="active ? 'hidden md:flex' : 'flex'">
                <div class="border-b border-line p-3">
                    <label class="sr-only" for="chat-search">Search colleagues</label>
                    <input id="chat-search" v-model="query" type="search" placeholder="Search name, email or role" class="w-full rounded-lg border-slate-300 text-sm" @input="search" />
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto">
                    <button v-for="person in people" :key="`u-${person.id}`" type="button" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-slate-50" @click="openWith(person.id)">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">{{ initials(person.name) }}</span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-slate-900">{{ person.name }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ person.role || 'Colleague' }} · {{ seen(person) }}</span>
                        </span>
                    </button>
                    <div v-if="list.length" class="border-t border-line">
                        <Link v-for="row in list" :key="row.id" :href="route('chat.show', row.id)" class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50" :class="active?.id === row.id ? 'bg-brand-50' : ''">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-700">{{ initials(row.peer?.name || '?') }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="truncate text-sm font-medium text-slate-900">{{ row.peer?.name || 'Conversation' }}</span>
                                    <span v-if="row.unread" class="ml-auto rounded-full bg-accent-500 px-1.5 text-[10px] font-bold text-white">{{ row.unread }}</span>
                                </span>
                                <span class="block truncate text-xs text-slate-500">{{ row.preview || 'No messages yet' }}</span>
                            </span>
                        </Link>
                    </div>
                </div>
            </aside>

            <section class="min-w-0 flex-1 flex-col" :class="active ? 'flex' : 'hidden md:flex'">
                <div v-if="!active" class="m-auto px-6 text-center text-sm text-slate-500">Choose a colleague to start a conversation.</div>
                <template v-else>
                    <header class="flex items-center gap-3 border-b border-line px-3 py-2">
                        <Link :href="route('chat.index')" class="rounded-md p-1 text-slate-500 hover:bg-slate-100 md:hidden" aria-label="Back to conversations">
                            <Icon name="arrow-left" :size="18" />
                        </Link>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ peer?.name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ peer?.role || 'Colleague' }} · {{ seen(peer) }}</p>
                        </div>
                    </header>
                    <div ref="scroller" class="min-h-0 flex-1 space-y-2 overflow-y-auto px-3 py-3">
                        <button v-if="hasMore" type="button" class="mx-auto block text-xs font-medium text-brand-700" @click="loadOlder">Load older messages</button>
                        <div v-for="message in messages" :key="message.id" class="flex" :class="message.sender_id === me ? 'justify-end' : 'justify-start'">
                            <div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm" :class="message.sender_id === me ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-800'">
                                <p v-if="message.reply" class="mb-1 truncate border-l-2 border-current/40 pl-2 text-xs opacity-80">{{ message.reply.sender }}: {{ message.reply.excerpt }}</p>
                                <p v-if="message.deleted" class="italic opacity-80">Message deleted</p>
                                <p v-else-if="message.body" class="whitespace-pre-wrap break-words">{{ message.body }}</p>
                                <div v-if="message.attachments?.length" class="mt-1 space-y-1">
                                    <button v-for="file in message.attachments" :key="file.id" type="button" class="block w-full rounded-lg bg-black/10 p-1 text-left" @click="viewing = file">
                                        <img v-if="file.image" :src="`${file.url}?inline=1`" :alt="file.name" class="max-h-40 rounded object-contain" />
                                        <span v-else class="block px-1 text-xs">
                                            {{ file.name }} · {{ size(file.size) }}
                                            <template v-if="file.preview?.status === 'pending' || file.preview?.status === 'processing'"> · Generating drawing preview…</template>
                                            <template v-else-if="file.preview?.status === 'failed'"> · Preview generation failed</template>
                                            <template v-else-if="file.preview?.status === 'unsupported'"> · {{ file.preview.message }}</template>
                                            <template v-else-if="file.preview?.status === 'ready' && ['dwg', 'dxf'].includes(file.extension)"> · View Drawing</template>
                                        </span>
                                    </button>
                                </div>
                                <p class="mt-1 text-[10px] opacity-70">
                                    {{ new Date(message.created_at).toLocaleString() }}
                                    <span v-if="message.edited_at"> · edited</span>
                                    <button v-if="!message.deleted" type="button" class="ml-2 underline" @click="replyTo = message">Reply</button>
                                </p>
                            </div>
                        </div>
                    </div>
                    <form class="border-t border-line p-2" @submit.prevent="send">
                        <p v-if="replyTo" class="mb-1 flex items-center justify-between truncate px-1 text-xs text-slate-500">
                            <span>Replying to {{ replyTo.body || 'attachment' }}</span>
                            <button type="button" class="ml-2" @click="replyTo = null">Cancel</button>
                        </p>
                        <LargeFileUploader :key="uploaderKey" class="mb-2" module="chat" source-type="conversation" :source-id="active.id" :max-files="5" persist @completed="onUploaded" @removed="onRemoved" />
                        <p v-if="error" class="mb-1 px-1 text-xs text-red-600">{{ error }}</p>
                        <div class="flex items-end gap-2">
                            <textarea v-model="body" rows="1" maxlength="8000" placeholder="Write a message" class="max-h-28 min-h-10 flex-1 resize-none rounded-lg border-slate-300 text-sm" @keydown.enter.exact.prevent="send" />
                            <AppButton type="submit" size="sm" :loading="sending" :disabled="sending">Send</AppButton>
                        </div>
                    </form>
                </template>
            </section>
        </div>
        <UniversalFileViewer
            :show="!!viewing"
            source="chat"
            :file-id="viewing?.id ?? null"
            :gallery="attachmentGallery"
            @close="viewing = null"
        />
    </AppLayout>
</template>
