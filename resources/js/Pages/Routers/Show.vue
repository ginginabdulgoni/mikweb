<script setup lang="ts">
import { Head, Link, router as inertia } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';
import StatusDot from '@/components/ui/StatusDot.vue';
import TrafficChart from '@/components/TrafficChart.vue';
import type { RouterDetail } from '@/types';

interface InterfaceItem {
    name: string;
    type?: string;
    comment?: string | null;
}

interface ConnLog {
    event: string;
    transport: string;
    success: boolean;
    duration_ms: number | null;
    message: string | null;
    created_at: string | null;
}

const props = defineProps<{
    router: RouterDetail;
    interfaces?: InterfaceItem[];
    connectionLogs: ConnLog[];
    can: { update: boolean; delete: boolean; test: boolean };
}>();

function test() {
    inertia.post(route('routers.test', props.router.id), {}, { preserveScroll: true });
}
function sync() {
    inertia.post(route('routers.sync', props.router.id), {}, { preserveScroll: true });
}
function destroy() {
    if (confirm(`Delete router "${props.router.name}"? This cannot be undone.`)) {
        inertia.delete(route('routers.destroy', props.router.id));
    }
}

const facts = (r: RouterDetail): [string, string | null][] => [
    ['Identity', r.identity],
    ['RouterOS', r.routeros_version],
    ['Model', r.hardware_model ?? r.board_name],
    ['Architecture', r.architecture],
    ['Serial', r.serial_number],
    ['Transport', r.preferred_transport],
];
</script>

<template>
    <Head :title="router.name" />
    <AppLayout :title="router.name">
        <div class="w-full space-y-6">
            <!-- Header -->
            <div class="flex flex-wrap items-center gap-4">
                <StatusDot :color="router.status_color" :pulse="router.status === 'online'" />
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-xl font-semibold">{{ router.name }}</h2>
                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ router.status_label }}
                        </span>
                    </div>
                    <div class="font-mono text-sm text-zinc-500">{{ router.host }}</div>
                </div>
                <div class="ml-auto flex gap-2">
                    <Btn v-if="can.test" variant="primary" @click="test">Test connection</Btn>
                    <Btn v-if="can.test" @click="sync">Sync info</Btn>
                    <Link v-if="can.update" :href="route('routers.edit', router.id)"><Btn>Edit</Btn></Link>
                    <Btn v-if="can.delete" variant="danger" @click="destroy">Delete</Btn>
                </div>
            </div>

            <div
                v-if="router.status !== 'online' && router.last_error"
                class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-700 dark:text-red-300"
            >
                Last error: {{ router.last_error }}
            </div>

            <!-- Live Traffic Chart -->
            <div v-if="router.status === 'online'">
                <TrafficChart
                    :router-id="router.id"
                    :interfaces="interfaces"
                    height-class="h-64"
                />
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Facts -->
                <div class="lg:col-span-2 space-y-6">
                    <section class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                        <h3 class="border-b border-zinc-200 px-5 py-3 text-sm font-semibold dark:border-zinc-800">Device</h3>
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 p-5 text-sm">
                            <div v-for="[label, value] in facts(router)" :key="label">
                                <dt class="text-xs uppercase tracking-wide text-zinc-500">{{ label }}</dt>
                                <dd class="mt-0.5">{{ value ?? '—' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Connection log -->
                    <section class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                        <h3 class="border-b border-zinc-200 px-5 py-3 text-sm font-semibold dark:border-zinc-800">Recent connection log</h3>
                        <div v-if="connectionLogs.length === 0" class="px-5 py-8 text-center text-sm text-zinc-500">
                            No attempts yet. Click <strong>Test connection</strong> to reach the device.
                        </div>
                        <ul v-else class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                            <li v-for="(log, i) in connectionLogs" :key="i" class="flex items-center gap-3 px-5 py-2.5">
                                <span :class="log.success ? 'text-emerald-500' : 'text-red-500'">{{ log.success ? '✓' : '✗' }}</span>
                                <span class="font-medium capitalize">{{ log.event }}</span>
                                <span class="text-xs text-zinc-400">{{ log.transport }}</span>
                                <span class="flex-1 truncate text-xs text-zinc-500">{{ log.message }}</span>
                                <span v-if="log.duration_ms != null" class="text-xs tabular-nums text-zinc-400">{{ log.duration_ms }}ms</span>
                            </li>
                        </ul>
                    </section>
                </div>

                <!-- Side: capability + credentials -->
                <div class="space-y-6">
                    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                        <h3 class="mb-3 text-sm font-semibold">Capabilities</h3>
                        <div v-if="router.capability" class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-zinc-500">RouterOS major</span><span>v{{ router.capability.routeros_major ?? '?' }}</span></div>
                            <div class="flex justify-between"><span class="text-zinc-500">REST API</span><span>{{ router.capability.supports_rest ? 'Yes' : 'No' }}</span></div>
                            <div class="flex justify-between"><span class="text-zinc-500">WiFi package</span><span>{{ router.capability.has_wifi_package ? 'Yes' : 'No' }}</span></div>
                            <div class="flex justify-between"><span class="text-zinc-500">Legacy wireless</span><span>{{ router.capability.has_wireless_package ? 'Yes' : 'No' }}</span></div>
                            <div class="flex justify-between"><span class="text-zinc-500">CAPsMAN</span><span>{{ router.capability.has_capsman ? 'Yes' : 'No' }}</span></div>
                        </div>
                        <p v-else class="text-sm text-zinc-500">Run <strong>Sync info</strong> to detect capabilities.</p>
                    </section>

                    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                        <h3 class="mb-3 text-sm font-semibold">Credentials</h3>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-zinc-500">API</span>
                            <span v-if="router.credentials.api.configured" class="text-emerald-600 dark:text-emerald-400">
                                {{ router.credentials.api.username }} ✓
                            </span>
                            <span v-else class="text-amber-600 dark:text-amber-400">Not set</span>
                        </div>
                        <p class="mt-2 text-xs text-zinc-400">Secrets are encrypted and never displayed.</p>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
