<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

defineProps({
    align: { type: String, default: 'right' },
    width: { type: String, default: 'w-56' },
});

const open = ref(false);
const root = ref(null);

const onClick = (e) => {
    if (root.value && !root.value.contains(e.target)) {
        open.value = false;
    }
};
const onKey = (e) => e.key === 'Escape' && (open.value = false);

onMounted(() => {
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onClick);
    document.removeEventListener('keydown', onKey);
});

defineExpose({ close: () => (open.value = false) });
</script>

<template>
    <div ref="root" class="relative">
        <div @click="open = !open">
            <slot name="trigger" :open="open" />
        </div>
        <Transition
            enter-active-class="duration-100 ease-out"
            enter-from-class="scale-95 opacity-0"
            leave-active-class="duration-75 ease-in"
            leave-to-class="scale-95 opacity-0"
        >
            <div
                v-if="open"
                class="absolute z-40 mt-2 overflow-hidden rounded-lg border border-line bg-white shadow-lg"
                :class="[width, align === 'right' ? 'right-0 origin-top-right' : 'left-0 origin-top-left']"
                @click="open = false"
            >
                <slot :close="() => (open = false)" />
            </div>
        </Transition>
    </div>
</template>
