<script setup lang="ts">
import { computed } from 'vue';
import { Head, router as inertia, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';
import type { PageProps } from '@/types';

const props = defineProps<{
    twoFactorEnabled: boolean;
    enrolment: { secret: string; otpauth: string } | null;
}>();

const page = usePage<PageProps & { recoveryCodes?: string[] }>();
const recoveryCodes = computed(() => (page.props as any).recoveryCodes as string[] | undefined);

const confirmForm = useForm({ code: '' });

function startEnable() {
    inertia.post(route('settings.2fa.enable'), {}, { preserveScroll: true });
}
function confirmEnable() {
    confirmForm.post(route('settings.2fa.confirm'), { preserveScroll: true, onSuccess: () => confirmForm.reset() });
}
function disable() {
    if (window.confirm('Disable two-factor authentication?')) inertia.delete(route('settings.2fa.disable'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Security" />
    <AppLayout title="Account security">
        <div class="mx-auto max-w-2xl space-y-6">
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-sm font-semibold">Two-factor authentication</h2>
                        <p class="mt-1 text-sm text-zinc-500">Add a TOTP code (Google Authenticator, Authy, 1Password…) to your login.</p>
                    </div>
                    <span
                        class="rounded-full px-2.5 py-1 text-xs font-medium"
                        :class="twoFactorEnabled ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800'"
                    >{{ twoFactorEnabled ? 'Enabled' : 'Disabled' }}</span>
                </div>

                <!-- Enabled -->
                <div v-if="twoFactorEnabled" class="mt-4">
                    <div v-if="recoveryCodes" class="mb-4 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4">
                        <p class="mb-2 text-sm font-medium text-amber-700 dark:text-amber-300">Save your recovery codes — shown once.</p>
                        <div class="grid grid-cols-2 gap-1 font-mono text-sm">
                            <span v-for="c in recoveryCodes" :key="c">{{ c }}</span>
                        </div>
                    </div>
                    <Btn variant="danger" @click="disable">Disable 2FA</Btn>
                </div>

                <!-- Enrolment -->
                <div v-else class="mt-4">
                    <div v-if="!enrolment">
                        <Btn variant="primary" @click="startEnable">Enable 2FA</Btn>
                    </div>
                    <div v-else class="space-y-4">
                        <div class="rounded-lg bg-zinc-50 p-4 text-sm dark:bg-zinc-800/50">
                            <p class="mb-2 text-zinc-500">Add this secret to your authenticator app:</p>
                            <code class="block break-all font-mono text-indigo-600 dark:text-indigo-400">{{ enrolment.secret }}</code>
                            <p class="mt-2 break-all text-xs text-zinc-400">{{ enrolment.otpauth }}</p>
                        </div>
                        <form class="flex items-end gap-2" @submit.prevent="confirmEnable">
                            <div class="flex-1">
                                <label class="mb-1 block text-sm font-medium">Enter the current code</label>
                                <input v-model="confirmForm.code" placeholder="123456" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono tracking-widest dark:border-zinc-700 dark:bg-zinc-800" />
                            </div>
                            <Btn type="submit" variant="primary" :disabled="confirmForm.processing">Confirm</Btn>
                        </form>
                        <p v-if="confirmForm.errors.code" class="text-xs text-red-500">{{ confirmForm.errors.code }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
