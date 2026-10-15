<script setup lang="ts">
import { computed, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        open?: boolean;
        show?: boolean;
        title: string;
    }>(),
    {
        open: false,
        show: false,
    },
);

const emit = defineEmits<{ close: [] }>();

const isOpen = computed(() => Boolean(props.open || props.show));

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('close');
}

watch(
    isOpen,
    (open) => {
        if (typeof document === 'undefined') return;
        if (open) document.addEventListener('keydown', onKey);
        else document.removeEventListener('keydown', onKey);
    },
);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition" enter-from-class="opacity-0" leave-active-class="transition" leave-to-class="opacity-0"
        >
            <div v-if="isOpen" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 pt-20" @click.self="emit('close')">
                <div class="w-full max-w-lg rounded-xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
                        <h3 class="text-sm font-semibold">{{ title }}</h3>
                        <button class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200" @click="emit('close')">✕</button>
                    </div>
                    <div class="p-5">
                        <slot />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
