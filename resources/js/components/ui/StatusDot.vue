<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ color: string; label?: string; pulse?: boolean }>();

const dotClass = computed(() => {
    const map: Record<string, string> = {
        emerald: 'bg-emerald-500',
        red: 'bg-red-500',
        amber: 'bg-amber-500',
        zinc: 'bg-zinc-400',
    };
    return map[props.color] ?? 'bg-zinc-400';
});
</script>

<template>
    <span class="inline-flex items-center gap-1.5">
        <span class="relative flex h-2.5 w-2.5">
            <span
                v-if="pulse"
                class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60"
                :class="dotClass"
            />
            <span class="relative inline-flex h-2.5 w-2.5 rounded-full" :class="dotClass" />
        </span>
        <span v-if="label" class="text-xs font-medium">{{ label }}</span>
    </span>
</template>
