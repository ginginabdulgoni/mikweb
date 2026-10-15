<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
        size?: 'sm' | 'md';
        type?: 'button' | 'submit';
        disabled?: boolean;
    }>(),
    { variant: 'secondary', size: 'md', type: 'button', disabled: false },
);

const classes = computed(() => {
    const base =
        'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/60 disabled:cursor-not-allowed disabled:opacity-50';
    const sizes = { sm: 'px-2.5 py-1.5 text-xs', md: 'px-3.5 py-2 text-sm' };
    const variants = {
        primary: 'bg-indigo-600 text-white hover:bg-indigo-500',
        secondary:
            'bg-white text-zinc-800 ring-1 ring-inset ring-zinc-300 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-100 dark:ring-zinc-700 dark:hover:bg-zinc-700',
        ghost: 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100',
        danger: 'bg-red-600 text-white hover:bg-red-500',
    };
    return `${base} ${sizes[props.size]} ${variants[props.variant]}`;
});
</script>

<template>
    <button :type="type" :class="classes" :disabled="disabled">
        <slot />
    </button>
</template>
