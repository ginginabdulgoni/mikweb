<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';

interface RouterHeader {
    id: number;
    name: string;
    host: string;
    identity: string | null;
    status: string;
    status_label: string;
    status_color: string;
    routeros_major: number | null;
}

defineProps<{ router: RouterHeader; deviceError?: string | null }>();
</script>

<template>
    <AppLayout :title="router.name">
        <div class="w-full space-y-4">
            <div
                v-if="deviceError"
                class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2.5 text-sm text-amber-700 dark:text-amber-300"
            >
                Could not read from the device: {{ deviceError }}
            </div>

            <slot />
        </div>
    </AppLayout>
</template>
