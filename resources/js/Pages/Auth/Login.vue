<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Btn from '@/components/ui/Btn.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in" />

    <div class="flex min-h-screen items-center justify-center bg-zinc-50 px-4 dark:bg-zinc-950">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-600 text-lg font-bold text-white">M</div>
                <div>
                    <div class="text-lg font-semibold tracking-tight">MikWeb</div>
                    <div class="text-xs text-zinc-500">MikroTik management, from the browser</div>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <form class="space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="email">Email</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="username"
                            required
                            autofocus
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-500">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="password">Password</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                        />
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-500">{{ form.errors.password }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                        <input v-model="form.remember" type="checkbox" class="rounded border-zinc-300 dark:border-zinc-600" />
                        Remember me
                    </label>

                    <Btn type="submit" variant="primary" :disabled="form.processing" class="w-full">
                        {{ form.processing ? 'Signing in…' : 'Sign in' }}
                    </Btn>
                </form>
            </div>

            <p class="mt-4 text-center text-xs text-zinc-400">
                Demo: admin@mikweb.local / password
            </p>
        </div>
    </div>
</template>
