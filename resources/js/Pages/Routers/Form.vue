<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';
import type { RouterDetail } from '@/types';

const props = defineProps<{
    router: RouterDetail | null;
    transports: { value: string; label: string }[];
}>();

const isEdit = !!props.router;

const form = useForm({
    name: props.router?.name ?? '',
    host: props.router?.host ?? '',
    preferred_transport: props.router?.preferred_transport ?? 'api',
    api_port: props.router?.api_port ?? 8728,
    api_ssl_port: props.router?.api_ssl_port ?? 8729,
    ssh_port: props.router?.ssh_port ?? 22,
    use_tls: props.router?.use_tls ?? false,
    verify_tls: props.router?.verify_tls ?? true,
    snmp_enabled: props.router?.snmp_enabled ?? false,
    snmp_version: props.router?.snmp_version ?? 'v2c',
    snmp_port: props.router?.snmp_port ?? 161,
    site: props.router?.site ?? '',
    isp: props.router?.isp ?? '',
    location: props.router?.location ?? '',
    poll_interval_seconds: props.router?.poll_interval_seconds ?? 30,
    monitoring_enabled: props.router?.monitoring_enabled ?? true,
    notes: props.router?.notes ?? '',
    credential: {
        username: props.router?.credentials.api.username ?? '',
        password: '',
    },
    snmp_community: '',
});

function submit() {
    if (isEdit && props.router) {
        form.put(route('routers.update', props.router.id));
    } else {
        form.post(route('routers.store'));
    }
}

const inputClass =
    'w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800';
</script>

<template>
    <Head :title="isEdit ? 'Edit router' : 'Add router'" />
    <AppLayout :title="isEdit ? 'Edit router' : 'Add router'">
        <div class="mx-auto max-w-3xl">
            <form class="space-y-6" @submit.prevent="submit">
                <!-- Identity -->
                <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="mb-4 text-sm font-semibold">Connection</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Name</label>
                            <input v-model="form.name" :class="inputClass" required />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Host / IP</label>
                            <input v-model="form.host" :class="inputClass" placeholder="192.0.2.1" required />
                            <p v-if="form.errors.host" class="mt-1 text-xs text-red-500">{{ form.errors.host }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Transport</label>
                            <select v-model="form.preferred_transport" :class="inputClass">
                                <option v-for="t in transports" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-sm font-medium">API port</label>
                                <input v-model.number="form.api_port" type="number" :class="inputClass" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium">API-SSL port</label>
                                <input v-model.number="form.api_ssl_port" type="number" :class="inputClass" />
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-5">
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.use_tls" type="checkbox" /> Use TLS</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.verify_tls" type="checkbox" /> Verify certificate</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.monitoring_enabled" type="checkbox" /> Monitoring enabled</label>
                    </div>
                </section>

                <!-- Credentials -->
                <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="mb-1 text-sm font-semibold">API credentials</h2>
                    <p class="mb-4 text-xs text-zinc-500">
                        Stored encrypted.
                        <span v-if="isEdit">Leave the password blank to keep the current one.</span>
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Username</label>
                            <input v-model="form.credential.username" :class="inputClass" autocomplete="off" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Password</label>
                            <input v-model="form.credential.password" type="password" :class="inputClass" autocomplete="new-password" />
                        </div>
                    </div>
                </section>

                <!-- Metadata -->
                <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 class="mb-4 text-sm font-semibold">Site metadata</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Site</label>
                            <input v-model="form.site" :class="inputClass" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">ISP / Customer</label>
                            <input v-model="form.isp" :class="inputClass" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-medium">Notes</label>
                            <textarea v-model="form.notes" rows="2" :class="inputClass" />
                        </div>
                    </div>
                </section>

                <div class="flex items-center gap-3">
                    <Btn type="submit" variant="primary" :disabled="form.processing">
                        {{ form.processing ? 'Saving…' : isEdit ? 'Save changes' : 'Create router' }}
                    </Btn>
                    <Link :href="isEdit && router ? route('routers.show', router.id) : route('routers.index')">
                        <Btn variant="ghost">Cancel</Btn>
                    </Link>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
