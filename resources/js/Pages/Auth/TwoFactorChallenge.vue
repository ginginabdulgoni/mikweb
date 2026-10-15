<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Btn from '@/components/ui/Btn.vue';

const form = useForm({ code: '' });

function submit() {
    form.post(route('two-factor.challenge'), { onFinish: () => form.reset('code') });
}
</script>

<template>
    <Head title="Two-factor challenge" />
    <div class="flex min-h-screen items-center justify-center bg-zinc-50 px-4 dark:bg-zinc-950">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600 text-lg font-bold text-white">M</div>
                <div class="text-lg font-semibold tracking-tight">Two-factor verification</div>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <p class="mb-4 text-sm text-zinc-500">Enter the 6-digit code from your authenticator app, or a recovery code.</p>
                <form class="space-y-4" @submit.prevent="submit">
                    <input
                        v-model="form.code"
                        inputmode="text"
                        autofocus
                        placeholder="123456"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-center font-mono text-lg tracking-widest focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                    />
                    <p v-if="form.errors.code" class="text-xs text-red-500">{{ form.errors.code }}</p>
                    <Btn type="submit" variant="primary" :disabled="form.processing" class="w-full">Verify</Btn>
                </form>
            </div>
        </div>
    </div>
</template>
