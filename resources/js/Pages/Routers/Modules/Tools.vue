<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import axios from '@/lib/http';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface NetwatchEntry {
    '.id': string;
    host: string;
    interval?: string;
    timeout?: string;
    status?: string;
    since?: string;
    'up-script'?: string;
    'down-script'?: string;
    disabled?: string;
    comment?: string;
}

const props = defineProps<{
    router: any;
    netwatch?: NetwatchEntry[];
    interfaces?: string[];
    deviceError?: string | null;
    can?: { manage: boolean };
}>();

const activeTab = ref<'ping' | 'traceroute' | 'torch' | 'netwatch'>('ping');

// ── Diagnostic Tool state ───────────────────────────────────────────
const pingAddress = ref('8.8.8.8');
const pingCount = ref(4);
const pingRunning = ref(false);
const pingError = ref<string | null>(null);
const pingRows = ref<Record<string, string>[]>([]);

const traceAddress = ref('8.8.8.8');
const traceRunning = ref(false);
const traceError = ref<string | null>(null);
const traceRows = ref<Record<string, string>[]>([]);

// ── Torch state ──────────────────────────────────────────────────────
const torchInterface = ref<string>(props.interfaces?.[0] ?? 'ether1');
const torchRunning = ref(false);
const torchError = ref<string | null>(null);
const torchRows = ref<Record<string, string>[]>([]);

function fmtRate(bps?: string | number): string {
    const n = Number(bps ?? 0);
    if (!n) return '0 bps';
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(2) + ' Mbps';
    if (n >= 1_000) return (n / 1_000).toFixed(1) + ' Kbps';
    return Math.round(n) + ' bps';
}

async function runTorch() {
    torchRunning.value = true;
    torchError.value = null;
    try {
        const url = route('routers.tools.torch', props.router.id);
        const { data } = await axios.post<{ rows: Record<string, string>[] }>(url, {
            interface: torchInterface.value,
        });
        torchRows.value = data.rows ?? [];
    } catch (e: any) {
        torchError.value = e?.response?.data?.error ?? 'Torch execution failed.';
    } finally {
        torchRunning.value = false;
    }
}

async function runPing() {
    pingRunning.value = true;
    pingError.value = null;
    pingRows.value = [];
    try {
        const url = route('routers.tools.ping', props.router.id);
        const { data } = await axios.post<{ rows: Record<string, string>[] }>(url, {
            address: pingAddress.value,
            count: pingCount.value,
        });
        pingRows.value = data.rows ?? [];
    } catch (e: any) {
        pingError.value = e?.response?.data?.error ?? 'Ping execution failed.';
    } finally {
        pingRunning.value = false;
    }
}

async function runTraceroute() {
    traceRunning.value = true;
    traceError.value = null;
    traceRows.value = [];
    try {
        const url = route('routers.tools.traceroute', props.router.id);
        const { data } = await axios.post<{ rows: Record<string, string>[] }>(url, {
            address: traceAddress.value,
        });
        traceRows.value = data.rows ?? [];
    } catch (e: any) {
        traceError.value = e?.response?.data?.error ?? 'Traceroute execution failed.';
    } finally {
        traceRunning.value = false;
    }
}

// ── Netwatch state ──────────────────────────────────────────────────
const selectedNetwatchId = ref<string | null>(null);
const searchNetwatch = ref('');
const showAddNetwatch = ref(false);
const showEditNetwatch = ref(false);

const netwatchForm = useForm({
    id: '',
    host: '',
    interval: '1m',
    timeout: '1000ms',
    up_script: '',
    down_script: '',
    comment: '',
});

const netwatchList = computed(() => props.netwatch ?? []);

const filteredNetwatch = computed(() => {
    const q = searchNetwatch.value.trim().toLowerCase();
    if (!q) return netwatchList.value;
    return netwatchList.value.filter(
        (n) =>
            n.host.toLowerCase().includes(q) ||
            (n.status ?? '').toLowerCase().includes(q) ||
            (n.comment ?? '').toLowerCase().includes(q),
    );
});

const selectedNetwatch = computed(() => netwatchList.value.find((n) => n['.id'] === selectedNetwatchId.value) ?? null);

function openAddNetwatch() {
    netwatchForm.reset();
    netwatchForm.id = '';
    netwatchForm.interval = '1m';
    netwatchForm.timeout = '1000ms';
    showAddNetwatch.value = true;
}

function openEditNetwatch(item: NetwatchEntry) {
    netwatchForm.id = item['.id'];
    netwatchForm.host = item.host;
    netwatchForm.interval = item.interval || '1m';
    netwatchForm.timeout = item.timeout || '1000ms';
    netwatchForm.up_script = item['up-script'] || '';
    netwatchForm.down_script = item['down-script'] || '';
    netwatchForm.comment = item.comment || '';
    showEditNetwatch.value = true;
}

function saveNetwatch() {
    netwatchForm.post(route('routers.tools.netwatch.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddNetwatch.value = false;
            netwatchForm.reset();
        },
    });
}

function updateNetwatch() {
    netwatchForm.put(route('routers.tools.netwatch.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditNetwatch.value = false;
            netwatchForm.reset();
        },
    });
}

function toggleNetwatch(item: NetwatchEntry, disable: boolean) {
    inertia.post(
        route('routers.tools.netwatch.toggle', props.router.id),
        { id: item['.id'], disable },
        { preserveScroll: true },
    );
}

function removeSelectedNetwatch() {
    if (!selectedNetwatch.value) return;
    if (!confirm(`Remove Netwatch monitor for ${selectedNetwatch.value.host}?`)) return;

    inertia.delete(route('routers.tools.netwatch.destroy', props.router.id), {
        data: { id: selectedNetwatch.value['.id'] },
        preserveScroll: true,
        onSuccess: () => {
            selectedNetwatchId.value = null;
        },
    });
}

function refreshNetwatch() {
    inertia.reload({ preserveScroll: true });
}
</script>

<template>
    <Head :title="`Tools & Netwatch — ${router.name}`" />

    <RouterWorkspace :router="router">
        <div class="flex h-full flex-col">
            <!-- Winbox Window Tabs -->
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/75 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/50">
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        class="rounded px-3 py-1 text-xs font-semibold transition-colors"
                        :class="activeTab === 'ping' ? 'bg-indigo-600 text-white shadow-xs' : 'text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                        @click="activeTab = 'ping'"
                    >
                        Ping
                    </button>
                    <button
                        type="button"
                        class="rounded px-3 py-1 text-xs font-semibold transition-colors"
                        :class="activeTab === 'traceroute' ? 'bg-indigo-600 text-white shadow-xs' : 'text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                        @click="activeTab = 'traceroute'"
                    >
                        Traceroute
                    </button>
                    <button
                        type="button"
                        class="rounded px-3 py-1 text-xs font-semibold transition-colors"
                        :class="activeTab === 'torch' ? 'bg-indigo-600 text-white shadow-xs' : 'text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                        @click="activeTab = 'torch'"
                    >
                        Torch (Packet Sniffer)
                    </button>
                    <button
                        type="button"
                        class="rounded px-3 py-1 text-xs font-semibold transition-colors"
                        :class="activeTab === 'netwatch' ? 'bg-indigo-600 text-white shadow-xs' : 'text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                        @click="activeTab = 'netwatch'"
                    >
                        Netwatch ({{ netwatchList.length }})
                    </button>
                </div>

                <div v-if="activeTab === 'netwatch'" class="flex items-center gap-1.5">
                    <Btn size="sm" variant="primary" @click="openAddNetwatch">+ Add Host</Btn>
                    <Btn
                        size="sm"
                        variant="secondary"
                        :disabled="!selectedNetwatch || selectedNetwatch.disabled !== 'true'"
                        @click="selectedNetwatch && toggleNetwatch(selectedNetwatch, false)"
                    >
                        ✓ Enable
                    </Btn>
                    <Btn
                        size="sm"
                        variant="secondary"
                        :disabled="!selectedNetwatch || selectedNetwatch.disabled === 'true'"
                        @click="selectedNetwatch && toggleNetwatch(selectedNetwatch, true)"
                    >
                        ✕ Disable
                    </Btn>
                    <Btn size="sm" variant="secondary" :disabled="!selectedNetwatch" @click="selectedNetwatch && openEditNetwatch(selectedNetwatch)">
                        ✏ Edit
                    </Btn>
                    <Btn size="sm" variant="danger" :disabled="!selectedNetwatch" @click="removeSelectedNetwatch">
                        🗑 Remove
                    </Btn>
                    <Btn size="sm" variant="secondary" @click="refreshNetwatch">↻ Refresh</Btn>
                </div>
            </div>

            <!-- Content Area -->
            <div class="flex-1 overflow-auto bg-white p-4 dark:bg-zinc-950">
                <!-- 1. PING TAB -->
                <div v-if="activeTab === 'ping'" class="w-full space-y-4">
                    <div class="flex items-center gap-2">
                        <input
                            v-model="pingAddress"
                            type="text"
                            placeholder="Target Host or IP (e.g. 8.8.8.8 or gateway)"
                            class="flex-1 rounded border border-zinc-300 bg-white px-3 py-1.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            @keydown.enter="runPing"
                        />
                        <select
                            v-model.number="pingCount"
                            class="rounded border border-zinc-300 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        >
                            <option :value="4">4 packets</option>
                            <option :value="6">6 packets</option>
                            <option :value="10">10 packets</option>
                        </select>
                        <Btn variant="primary" size="sm" :disabled="pingRunning" @click="runPing">
                            {{ pingRunning ? 'Pinging...' : 'Start Ping' }}
                        </Btn>
                    </div>

                    <div v-if="pingError" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                        {{ pingError }}
                    </div>

                    <div v-if="pingRows.length > 0" class="rounded border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full text-left font-mono text-xs">
                            <thead class="bg-zinc-100 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                                <tr>
                                    <th class="px-2 py-1.5">Seq</th>
                                    <th class="px-2 py-1.5">Host</th>
                                    <th class="px-2 py-1.5">Size</th>
                                    <th class="px-2 py-1.5">TTL</th>
                                    <th class="px-2 py-1.5">Time</th>
                                    <th class="px-2 py-1.5">Packet Loss</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-900">
                                <tr v-for="(r, idx) in pingRows" :key="idx" class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                                    <td class="px-2 py-1 text-zinc-400">{{ r.seq ?? idx }}</td>
                                    <td class="px-2 py-1 font-semibold text-zinc-800 dark:text-zinc-200">{{ r.host }}</td>
                                    <td class="px-2 py-1 text-zinc-500">{{ r.size ?? '56' }} bytes</td>
                                    <td class="px-2 py-1 text-zinc-500">{{ r.ttl }}</td>
                                    <td class="px-2 py-1 font-bold text-emerald-600 dark:text-emerald-400">{{ r.time }}</td>
                                    <td class="px-2 py-1" :class="r['packet-loss'] === '0' ? 'text-emerald-600' : 'text-red-600 font-bold'">
                                        {{ r['packet-loss'] }}%
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 2. TRACEROUTE TAB -->
                <div v-else-if="activeTab === 'traceroute'" class="w-full space-y-4">
                    <div class="flex items-center gap-2">
                        <input
                            v-model="traceAddress"
                            type="text"
                            placeholder="Target Host or IP (e.g. 1.1.1.1 or cloudflare.com)"
                            class="flex-1 rounded border border-zinc-300 bg-white px-3 py-1.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            @keydown.enter="runTraceroute"
                        />
                        <Btn variant="primary" size="sm" :disabled="traceRunning" @click="runTraceroute">
                            {{ traceRunning ? 'Tracing...' : 'Trace Route' }}
                        </Btn>
                    </div>

                    <div v-if="traceError" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                        {{ traceError }}
                    </div>

                    <div v-if="traceRows.length > 0" class="rounded border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full text-left font-mono text-xs">
                            <thead class="bg-zinc-100 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                                <tr>
                                    <th class="w-12 px-2 py-1.5 text-center">Hop</th>
                                    <th class="px-2 py-1.5">Address</th>
                                    <th class="px-2 py-1.5">Latency</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-900">
                                <tr v-for="(r, idx) in traceRows" :key="idx" class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                                    <td class="px-2 py-1 text-center font-bold text-zinc-400">{{ r.hop ?? idx + 1 }}</td>
                                    <td class="px-2 py-1 font-semibold text-indigo-600 dark:text-indigo-400">{{ r.address }}</td>
                                    <td class="px-2 py-1 text-emerald-600 dark:text-emerald-400">{{ r.time }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 3. TORCH TAB (LIVE PACKET SNIFFER) -->
                <div v-else-if="activeTab === 'torch'" class="w-full space-y-4">
                    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">Interface:</span>
                            <select
                                v-if="interfaces && interfaces.length > 0"
                                v-model="torchInterface"
                                class="rounded border border-zinc-300 bg-white px-2.5 py-1 text-xs font-mono font-medium dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option v-for="iface in interfaces" :key="iface" :value="iface">{{ iface }}</option>
                            </select>
                            <input
                                v-else
                                v-model="torchInterface"
                                placeholder="interface (e.g. ether1)"
                                class="w-32 rounded border border-zinc-300 bg-white px-2 py-1 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                        </div>

                        <Btn variant="primary" size="sm" :disabled="torchRunning" @click="runTorch">
                            {{ torchRunning ? 'Sniffing Traffic...' : '🔦 Run Torch' }}
                        </Btn>

                        <span class="text-xs text-zinc-500">
                            Mengendus paket & pemakaian bandwidth real-time per IP/port pada interface yang dipilih.
                        </span>
                    </div>

                    <div v-if="torchError" class="rounded border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                        {{ torchError }}
                    </div>

                    <div v-if="torchRows.length > 0" class="overflow-x-auto rounded border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full text-left font-mono text-xs">
                            <thead class="bg-zinc-100 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                                <tr>
                                    <th class="px-2.5 py-1.5">Src Address</th>
                                    <th class="px-2.5 py-1.5">Src Port</th>
                                    <th class="px-2.5 py-1.5">Dst Address</th>
                                    <th class="px-2.5 py-1.5">Dst Port</th>
                                    <th class="px-2.5 py-1.5">Protocol</th>
                                    <th class="px-2.5 py-1.5 text-right">Tx Rate</th>
                                    <th class="px-2.5 py-1.5 text-right">Rx Rate</th>
                                    <th class="px-2.5 py-1.5 text-right">Packets</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-900">
                                <tr v-for="(r, idx) in torchRows" :key="idx" class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                                    <td class="px-2.5 py-1.5 font-semibold text-zinc-800 dark:text-zinc-200">{{ r['src-address'] ?? '—' }}</td>
                                    <td class="px-2.5 py-1.5 text-zinc-500">{{ r['src-port'] ?? '—' }}</td>
                                    <td class="px-2.5 py-1.5 font-semibold text-zinc-800 dark:text-zinc-200">{{ r['dst-address'] ?? '—' }}</td>
                                    <td class="px-2.5 py-1.5 text-zinc-500">{{ r['dst-port'] ?? '—' }}</td>
                                    <td class="px-2.5 py-1.5">
                                        <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] uppercase font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                            {{ r.protocol ?? 'ip' }}
                                        </span>
                                    </td>
                                    <td class="px-2.5 py-1.5 text-right font-medium text-indigo-600 dark:text-indigo-400">{{ fmtRate(r['tx-rate']) }}</td>
                                    <td class="px-2.5 py-1.5 text-right font-medium text-emerald-600 dark:text-emerald-400">{{ fmtRate(r['rx-rate']) }}</td>
                                    <td class="px-2.5 py-1.5 text-right text-zinc-400">{{ r.packets ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 4. NETWATCH TAB -->
                <div v-else class="space-y-2">
                    <div class="rounded border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full text-left font-mono text-xs">
                            <thead class="sticky top-0 bg-zinc-100 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                                <tr>
                                    <th class="w-8 px-2 py-1.5 text-center">#</th>
                                    <th class="px-2 py-1.5">Host</th>
                                    <th class="px-2 py-1.5">Status</th>
                                    <th class="px-2 py-1.5">Interval</th>
                                    <th class="px-2 py-1.5">Timeout</th>
                                    <th class="px-2 py-1.5">Since</th>
                                    <th class="px-2 py-1.5">Comment</th>
                                    <th class="w-20 px-2 py-1.5 text-right font-sans">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-900">
                                <tr
                                    v-for="(n, idx) in filteredNetwatch"
                                    :key="n['.id']"
                                    class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/30"
                                    :class="[
                                        selectedNetwatchId === n['.id'] ? 'bg-indigo-100/70 font-semibold text-indigo-900 dark:bg-indigo-950/70 dark:text-indigo-200' : '',
                                        n.disabled === 'true' ? 'opacity-50 italic text-zinc-400' : ''
                                    ]"
                                    @click="selectedNetwatchId = n['.id']"
                                    @dblclick="openEditNetwatch(n)"
                                >
                                    <td class="px-2 py-1 text-center text-zinc-400">{{ idx + 1 }}</td>
                                    <td class="px-2 py-1 font-bold text-zinc-800 dark:text-zinc-200">{{ n.host }}</td>
                                    <td class="px-2 py-1">
                                        <span
                                            class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium"
                                            :class="n.disabled === 'true'
                                                ? 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800'
                                                : n.status === 'up'
                                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                                    : 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400'"
                                        >
                                            <StatusDot :color="n.disabled === 'true' ? 'zinc' : n.status === 'up' ? 'emerald' : 'rose'" />
                                            {{ n.disabled === 'true' ? 'disabled' : (n.status || 'unknown') }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-1 text-zinc-600 dark:text-zinc-400">{{ n.interval || '1m' }}</td>
                                    <td class="px-2 py-1 text-zinc-600 dark:text-zinc-400">{{ n.timeout || '1000ms' }}</td>
                                    <td class="px-2 py-1 text-zinc-500">{{ n.since || '-' }}</td>
                                    <td class="px-2 py-1 font-sans italic text-zinc-500">{{ n.comment || '-' }}</td>
                                    <td class="px-2 py-1 text-right font-sans">
                                        <div class="flex items-center justify-end gap-1">
                                            <button
                                                type="button"
                                                :title="n.disabled === 'true' ? 'Enable' : 'Disable'"
                                                class="rounded p-0.5 text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                                @click.stop="toggleNetwatch(n, n.disabled !== 'true')"
                                            >
                                                {{ n.disabled === 'true' ? '✓' : '✕' }}
                                            </button>
                                            <button
                                                type="button"
                                                title="Edit"
                                                class="rounded p-0.5 text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                                @click.stop="openEditNetwatch(n)"
                                            >
                                                ✏
                                            </button>
                                            <button
                                                type="button"
                                                title="Remove"
                                                class="rounded p-0.5 text-zinc-400 hover:text-red-600 dark:hover:text-red-400"
                                                @click.stop="selectedNetwatchId = n['.id']; removeSelectedNetwatch()"
                                            >
                                                🗑
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredNetwatch.length === 0">
                                    <td colspan="8" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                        No Netwatch host monitors configured. Click "+ Add Host" to monitor an IP.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Status Footer -->
            <div class="flex items-center justify-between border-t border-zinc-200 bg-zinc-50 px-4 py-1 text-[11px] text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900">
                <span>Tools mode: {{ activeTab.toUpperCase() }}</span>
                <span class="font-mono text-[10px] text-zinc-400">/tool/{{ activeTab }}</span>
            </div>
        </div>

        <!-- Add Netwatch Modal -->
        <Modal :show="showAddNetwatch" title="New Netwatch Monitor" @close="showAddNetwatch = false">
            <form class="space-y-3" @submit.prevent="saveNetwatch">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Host IP or Address</label>
                    <input
                        v-model="netwatchForm.host"
                        type="text"
                        required
                        placeholder="e.g. 8.8.8.8 or 10.0.0.1"
                        class="mt-1 block w-full font-mono text-xs rounded border border-zinc-300 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Ping Interval</label>
                        <input
                            v-model="netwatchForm.interval"
                            type="text"
                            placeholder="1m"
                            class="mt-1 block w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Timeout</label>
                        <input
                            v-model="netwatchForm.timeout"
                            type="text"
                            placeholder="1000ms"
                            class="mt-1 block w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">On UP Script (Optional)</label>
                    <textarea
                        v-model="netwatchForm.up_script"
                        rows="2"
                        placeholder=":log info &quot;Host UP&quot;"
                        class="mt-1 block w-full font-mono text-xs rounded border border-zinc-300 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    ></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">On DOWN Script (Optional)</label>
                    <textarea
                        v-model="netwatchForm.down_script"
                        rows="2"
                        placeholder=":log error &quot;Host DOWN!&quot;"
                        class="mt-1 block w-full font-mono text-xs rounded border border-zinc-300 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    ></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="netwatchForm.comment"
                        type="text"
                        placeholder="e.g. ISP Gateway Ping"
                        class="mt-1 block w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                    <Btn type="button" size="sm" variant="secondary" @click="showAddNetwatch = false">Cancel</Btn>
                    <Btn type="submit" size="sm" variant="primary" :disabled="netwatchForm.processing">Create Monitor</Btn>
                </div>
            </form>
        </Modal>

        <!-- Edit Netwatch Modal -->
        <Modal :show="showEditNetwatch" title="Edit Netwatch Monitor" @close="showEditNetwatch = false">
            <form class="space-y-3" @submit.prevent="updateNetwatch">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Host IP or Address</label>
                    <input
                        v-model="netwatchForm.host"
                        type="text"
                        required
                        class="mt-1 block w-full font-mono text-xs rounded border border-zinc-300 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Ping Interval</label>
                        <input
                            v-model="netwatchForm.interval"
                            type="text"
                            class="mt-1 block w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Timeout</label>
                        <input
                            v-model="netwatchForm.timeout"
                            type="text"
                            class="mt-1 block w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">On UP Script</label>
                    <textarea
                        v-model="netwatchForm.up_script"
                        rows="2"
                        class="mt-1 block w-full font-mono text-xs rounded border border-zinc-300 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    ></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">On DOWN Script</label>
                    <textarea
                        v-model="netwatchForm.down_script"
                        rows="2"
                        class="mt-1 block w-full font-mono text-xs rounded border border-zinc-300 px-2 py-1 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    ></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="netwatchForm.comment"
                        type="text"
                        class="mt-1 block w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                    <Btn type="button" size="sm" variant="secondary" @click="showEditNetwatch = false">Cancel</Btn>
                    <Btn type="submit" size="sm" variant="primary" :disabled="netwatchForm.processing">Save Changes</Btn>
                </div>
            </form>
        </Modal>
    </RouterWorkspace>
</template>
