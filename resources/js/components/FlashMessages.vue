<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types';

const page = usePage<PageProps>();
const success = computed(() => page.props.flash?.success);
const error = computed(() => page.props.flash?.error);
</script>

<template>
    <div v-if="success || error" class="mb-4 space-y-2">
        <div
            v-if="success"
            class="flex items-start gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-300"
        >
            <span class="mt-0.5">✓</span>
            <span>{{ success }}</span>
        </div>
        <div
            v-if="error"
            class="flex items-start gap-2 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-800 dark:text-red-300"
        >
            <span class="mt-0.5">⚠</span>
            <span>{{ error }}</span>
        </div>
    </div>
</template>
