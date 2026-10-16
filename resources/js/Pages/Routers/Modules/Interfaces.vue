<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface Iface {
    '.id': string;
    name: string;
    type: string;
    running?: string;
    disabled?: string;
    'rx-byte'?: string;
    'tx-byte'?: string;
    'mac-address'?: string;
    mtu?: string;
    comment?: string;
}

const props = defineProps<{
    router: any;
    interfaces: Iface[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

const selectedId = ref<string | null>(null);
const searchQuery = ref('');
const statusFilter = ref<'all' | 'running' | 'disabled'>('all');
const typeFilter = ref('all');

// Multi-selection for Batch Operations
const selectedInterfaceIds = ref<string[]>([]);
const isProcessingBatch = ref(false);

function fmtBytes(v?: string | number): string {
    const n = Number(v ?? 0);
    if (!n) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(n) / Math.log(1024));
    return `${(n / 1024 ** i).toFixed(1)} ${u[i]}`;
}

// ── Top KPI Statistics Summary ───────────────────────────────────────
const stats = computed(() => {
    const total = props.interfaces.length;
    const running = props.interfaces.filter((i) => i.running === 'true').length;
    const disabled = props.interfaces.filter((i) => i.disabled === 'true').length;
    const ether = props.interfaces.filter((i) => i.type === 'ether').length;
    const bridgeVlan = props.interfaces.filter((i) => ['bridge', 'vlan'].includes(i.type)).length;

    let totalRx = 0;
    let totalTx = 0;
    for (const iface of props.interfaces) {
        totalRx += Number(iface['rx-byte'] || 0);
        totalTx += Number(iface['tx-byte'] || 0);
    }

    return {
        total,
        running,
        disabled,
        ether,
        bridgeVlan,
        totalTrafficFormatted: fmtBytes(totalRx + totalTx),
        rxFormatted: fmtBytes(totalRx),
        txFormatted: fmtBytes(totalTx),
    };
});

const types = computed(() => Array.from(new Set(props.interfaces.map((i) => i.type))).sort());

// ── Filtered & Sorted Interfaces ─────────────────────────────────────
type SortField = 'name' | 'type' | 'running' | 'rx-byte' | 'tx-byte' | 'mtu' | 'mac-address';
const sortField = ref<SortField>('name');
const sortAsc = ref(true);
const perPage = ref<number>(25);
const currentPage = ref(1);

function sortBy(field: SortField) {
    if (sortField.value === field) {
        sortAsc.value = !sortAsc.value;
    } else {
        sortField.value = field;
        sortAsc.value = true;
    }
}

const filteredInterfaces = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.interfaces.filter((i) => {
        if (statusFilter.value === 'running' && i.running !== 'true') return false;
        if (statusFilter.value === 'disabled' && i.disabled !== 'true') return false;
        if (typeFilter.value !== 'all' && i.type !== typeFilter.value) return false;
        if (!q) return true;
        return (
            i.name.toLowerCase().includes(q) ||
            i.type.toLowerCase().includes(q) ||
            (i['mac-address'] ?? '').toLowerCase().includes(q) ||
            (i.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedInterfaces = computed(() => {
    const list = [...filteredInterfaces.value];
    list.sort((a, b) => {
        if (['rx-byte', 'tx-byte', 'mtu'].includes(sortField.value)) {
            const numA = Number(a[sortField.value] || 0);
            const numB = Number(b[sortField.value] || 0);
            return sortAsc.value ? numA - numB : numB - numA;
        }

        const valA = (a[sortField.value] ?? '').toString().toLowerCase();
        const valB = (b[sortField.value] ?? '').toString().toLowerCase();
        if (valA < valB) return sortAsc.value ? -1 : 1;
        if (valA > valB) return sortAsc.value ? 1 : -1;
        return 0;
    });
    return list;
});

const totalPages = computed(() => {
    if (perPage.value === 0) return 1;
    return Math.ceil(sortedInterfaces.value.length / perPage.value) || 1;
});

const paginatedInterfaces = computed(() => {
    if (perPage.value === 0) return sortedInterfaces.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedInterfaces.value.slice(start, start + perPage.value);
});

watch([searchQuery, statusFilter, typeFilter, perPage], () => {
    currentPage.value = 1;
});

watch(
    () => props.router?.id,
    () => {
        selectedId.value = null;
        selectedInterfaceIds.value = [];
        currentPage.value = 1;
    },
);

// Checkbox selection states
const isAllSelected = computed(() => {
    if (paginatedInterfaces.value.length === 0) return false;
    return paginatedInterfaces.value.every((i) => selectedInterfaceIds.value.includes(i['.id']));
});

function toggleSelectAll() {
    if (isAllSelected.value) {
        const currentIds = new Set(paginatedInterfaces.value.map((i) => i['.id']));
        selectedInterfaceIds.value = selectedInterfaceIds.value.filter((id) => !currentIds.has(id));
    } else {
        const newIds = new Set([...selectedInterfaceIds.value, ...paginatedInterfaces.value.map((i) => i['.id'])]);
        selectedInterfaceIds.value = Array.from(newIds);
    }
}

function toggleSelect(id: string) {
    const idx = selectedInterfaceIds.value.indexOf(id);
    if (idx >= 0) selectedInterfaceIds.value.splice(idx, 1);
    else selectedInterfaceIds.value.push(id);
}

// ── Batch Operations ─────────────────────────────────────────────────
function batchAction(action: 'enable' | 'disable' | 'reset_counters') {
    if (selectedInterfaceIds.value.length === 0) return;

    isProcessingBatch.value = true;
    inertia.post(
        route('routers.interfaces.batch', props.router.id),
        { action, ids: selectedInterfaceIds.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isProcessingBatch.value = false;
                selectedInterfaceIds.value = [];
                selectedId.value = null;
            },
        },
    );
}

// ── Single Operations ────────────────────────────────────────────────
function post(url: string, id: string) {
    inertia.post(url, { id }, { preserveScroll: true });
}

function handleToggleSelected(enable: boolean) {
    if (!selectedId.value) return;
    const url = enable
        ? route('routers.interfaces.enable', props.router.id)
        : route('routers.interfaces.disable', props.router.id);
    post(url, selectedId.value);
}

function handleResetSelected() {
    if (!selectedId.value) return;
    post(route('routers.interfaces.reset', props.router.id), selectedId.value);
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// ── Clipboard Helper ─────────────────────────────────────────────────
const copiedKey = ref<string | null>(null);
function copyText(text: string, key: string) {
    navigator.clipboard.writeText(text);
    copiedKey.value = key;
    setTimeout(() => {
        if (copiedKey.value === key) copiedKey.value = null;
    }, 1500);
}

// ── CSV Export Helper ────────────────────────────────────────────────
function exportCsv() {
    const headers = ['Name', 'Type', 'Running', 'Disabled', 'MTU', 'RX Bytes', 'TX Bytes', 'MAC Address', 'Comment'];
    const rows = sortedInterfaces.value.map((i) => [
        `"${i.name}"`,
        `"${i.type}"`,
        `"${i.running === 'true' ? 'yes' : 'no'}"`,
        `"${i.disabled === 'true' ? 'yes' : 'no'}"`,
        `"${i.mtu || 1500}"`,
        `"${i['rx-byte'] || 0}"`,
        `"${i['tx-byte'] || 0}"`,
        `"${i['mac-address'] || ''}"`,
        `"${(i.comment || '').replace(/"/g, '""')}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `interfaces-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<template>
    <Head :title="`Interfaces — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- ── Top KPI Statistics Summary Grid ────────────────────────── -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Interfaces -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Total Interfaces</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.total }}</span>
                        <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">Ports</span>
                    </div>
                </div>

                <!-- Running / Link Up -->
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3.5 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">
                        <StatusDot color="emerald" :pulse="true" />
                        <span>Link Up (Running)</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-emerald-800 dark:text-emerald-200">{{ stats.running }}</span>
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400">Connected</span>
                    </div>
                </div>

                <!-- Disabled -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-zinc-500">
                        <StatusDot color="zinc" />
                        <span>Disabled</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-500 dark:text-zinc-400">{{ stats.disabled }}</span>
                        <span class="text-[10px] text-zinc-400">Nonaktif</span>
                    </div>
                </div>

                <!-- Ethernet Ports -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Ethernet Ports</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.ether }}</span>
                        <span class="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-950 dark:text-sky-300">Physical</span>
                    </div>
                </div>

                <!-- Bridge / VLAN -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Bridge & VLAN</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.bridgeVlan }}</span>
                        <span class="rounded bg-violet-50 px-1.5 py-0.5 text-[10px] font-medium text-violet-700 dark:bg-violet-950 dark:text-violet-300">Virtual</span>
                    </div>
                </div>

                <!-- Total Network Traffic -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Total Traffic</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-lg font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.totalTrafficFormatted }}</span>
                        <span class="text-[10px] font-mono text-zinc-400">RX + TX</span>
                    </div>
                </div>
            </div>

            <!-- ── Main WinBox Window Card ────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Window Header / Bar -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold text-indigo-600 dark:text-indigo-400">⇄</span>
                            <h2 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">Interface List & Traffic Status</h2>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /interface
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                                <StatusDot color="emerald" :pulse="true" />
                                {{ stats.running }} Running
                            </span>
                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ interfaces.length }} Total Interfaces
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Toolbar (Winbox-style) -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn
                            v-if="can.manage"
                            size="sm"
                            :disabled="!selectedId"
                            title="Enable selected"
                            @click="handleToggleSelected(true)"
                        >
                            ✓ Enable
                        </Btn>
                        <Btn
                            v-if="can.manage"
                            size="sm"
                            :disabled="!selectedId"
                            title="Disable selected"
                            @click="handleToggleSelected(false)"
                        >
                            ✕ Disable
                        </Btn>
                        <Btn
                            v-if="can.manage"
                            size="sm"
                            variant="ghost"
                            :disabled="!selectedId"
                            title="Reset counters"
                            @click="handleResetSelected"
                        >
                            0 Reset Counter
                        </Btn>
                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh
                        </Btn>

                        <div class="mx-1 h-4 w-px bg-zinc-300 dark:bg-zinc-700"></div>

                        <!-- CSV Export -->
                        <Btn
                            size="sm"
                            variant="ghost"
                            class="gap-1.5 text-zinc-600 dark:text-zinc-300"
                            title="Export interface table to CSV"
                            @click="exportCsv"
                        >
                            <span>📥</span> Export CSV
                        </Btn>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <!-- Status Filter Pills -->
                        <div class="flex items-center rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1 transition-all"
                                :class="statusFilter === 'all' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="statusFilter = 'all'"
                            >
                                All ({{ interfaces.length }})
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1 transition-all"
                                :class="statusFilter === 'running' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="statusFilter = 'running'"
                            >
                                Running ({{ stats.running }})
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1 transition-all"
                                :class="statusFilter === 'disabled' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="statusFilter = 'disabled'"
                            >
                                Disabled ({{ stats.disabled }})
                            </button>
                        </div>

                        <!-- Type filter dropdown -->
                        <select
                            v-model="typeFilter"
                            class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        >
                            <option value="all">Semua Type</option>
                            <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                        </select>

                        <!-- Search Input -->
                        <div class="relative">
                            <input
                                v-model="searchQuery"
                                type="search"
                                placeholder="Cari interface..."
                                class="w-40 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 pl-8 text-xs placeholder-zinc-400 transition-all focus:w-56 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                            <span class="pointer-events-none absolute left-2.5 top-2 text-xs text-zinc-400">⌕</span>
                        </div>
                    </div>
                </div>

                <!-- ── Batch Actions Sticky Floating Bar ────────────────────── -->
                <transition
                    enter-active-class="transition duration-150 ease-out"
                    enter-from-class="opacity-0 -translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 -translate-y-2"
                >
                    <div
                        v-if="can.manage && selectedInterfaceIds.length > 0"
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/95 px-4 py-2 backdrop-blur-xs dark:border-indigo-900/60 dark:bg-indigo-950/80"
                    >
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white dark:bg-indigo-500">
                                {{ selectedInterfaceIds.length }}
                            </span>
                            <span class="text-xs font-semibold text-indigo-950 dark:text-indigo-100">
                                Interface dipilih
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-emerald-700 shadow-xs hover:bg-emerald-50 dark:bg-zinc-800 dark:text-emerald-300 dark:hover:bg-zinc-700"
                                @click="batchAction('enable')"
                            >
                                ✓ Enable
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-amber-700 shadow-xs hover:bg-amber-50 dark:bg-zinc-800 dark:text-amber-300 dark:hover:bg-zinc-700"
                                @click="batchAction('disable')"
                            >
                                ✕ Disable
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-indigo-700 shadow-xs hover:bg-indigo-50 dark:bg-zinc-800 dark:text-indigo-300 dark:hover:bg-zinc-700"
                                @click="batchAction('reset_counters')"
                            >
                                0 Reset Counters
                            </button>
                            <button
                                type="button"
                                class="text-xs text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
                                @click="selectedInterfaceIds = []"
                            >
                                Batal
                            </button>
                        </div>
                    </div>
                </transition>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th v-if="can.manage" class="w-8 px-3 py-2.5 text-center">
                                    <input
                                        type="checkbox"
                                        :checked="isAllSelected"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelectAll"
                                    />
                                </th>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortBy('running')">
                                    <div class="flex items-center gap-1">
                                        <span>State</span>
                                        <span v-if="sortField === 'running'">{{ sortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortBy('name')">
                                    <div class="flex items-center gap-1">
                                        <span>Name</span>
                                        <span v-if="sortField === 'name'">{{ sortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortBy('type')">
                                    <div class="flex items-center gap-1">
                                        <span>Type</span>
                                        <span v-if="sortField === 'type'">{{ sortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortBy('mtu')">
                                    <div class="flex items-center gap-1">
                                        <span>MTU</span>
                                        <span v-if="sortField === 'mtu'">{{ sortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortBy('rx-byte')">
                                    <div class="flex items-center gap-1">
                                        <span>RX Bytes</span>
                                        <span v-if="sortField === 'rx-byte'">{{ sortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortBy('tx-byte')">
                                    <div class="flex items-center gap-1">
                                        <span>TX Bytes</span>
                                        <span v-if="sortField === 'tx-byte'">{{ sortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="hidden cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 lg:table-cell dark:hover:text-zinc-200" @click="sortBy('mac-address')">
                                    <div class="flex items-center gap-1">
                                        <span>MAC Address</span>
                                        <span v-if="sortField === 'mac-address'">{{ sortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredInterfaces.length === 0">
                                <td colspan="10" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada interface yang cocok.
                                </td>
                            </tr>
                            <tr
                                v-for="(i, idx) in paginatedInterfaces"
                                :key="i['.id']"
                                class="group cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="[
                                    selectedId === i['.id'] && 'bg-indigo-50/70 dark:bg-indigo-950/40',
                                    selectedInterfaceIds.includes(i['.id']) && 'bg-indigo-50/80 dark:bg-indigo-950/50',
                                    i.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="selectedId = i['.id']"
                            >
                                <td v-if="can.manage" class="px-3 py-2.5 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="selectedInterfaceIds.includes(i['.id'])"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelect(i['.id'])"
                                    />
                                </td>
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ (currentPage - 1) * perPage + idx + 1 }}</td>
                                <td class="px-4 py-2.5">
                                    <StatusDot
                                        :color="i.disabled === 'true' ? 'zinc' : i.running === 'true' ? 'emerald' : 'amber'"
                                        :label="i.disabled === 'true' ? 'disabled' : i.running === 'true' ? 'running' : 'down'"
                                    />
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ i.name }}</span>
                                        <button
                                            type="button"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] text-zinc-400 hover:text-indigo-600 transition-opacity"
                                            title="Copy Interface Name"
                                            @click.stop="copyText(i.name, 'name-' + i['.id'])"
                                        >
                                            {{ copiedKey === 'name-' + i['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ i.type }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    {{ i.mtu || 1500 }}
                                </td>
                                <td class="px-4 py-2.5 font-mono tabular-nums text-zinc-700 dark:text-zinc-300">
                                    {{ fmtBytes(i['rx-byte']) }}
                                </td>
                                <td class="px-4 py-2.5 font-mono tabular-nums text-zinc-700 dark:text-zinc-300">
                                    {{ fmtBytes(i['tx-byte']) }}
                                </td>
                                <td class="hidden px-4 py-2.5 font-mono text-zinc-500 lg:table-cell">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ i['mac-address'] || '—' }}</span>
                                        <button
                                            v-if="i['mac-address']"
                                            type="button"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] text-zinc-400 hover:text-indigo-600 transition-opacity"
                                            title="Copy MAC"
                                            @click.stop="copyText(i['mac-address'], 'mac-' + i['.id'])"
                                        >
                                            {{ copiedKey === 'mac-' + i['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end gap-1">
                                        <Btn
                                            v-if="i.disabled === 'true'"
                                            size="sm"
                                            class="bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300"
                                            @click="post(route('routers.interfaces.enable', router.id), i['.id'])"
                                        >
                                            Enable
                                        </Btn>
                                        <Btn
                                            v-else
                                            size="sm"
                                            @click="post(route('routers.interfaces.disable', router.id), i['.id'])"
                                        >
                                            Disable
                                        </Btn>
                                        <Btn
                                            size="sm"
                                            variant="ghost"
                                            title="Reset Counter"
                                            @click="post(route('routers.interfaces.reset', router.id), i['.id'])"
                                        >
                                            0 Reset
                                        </Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── Pagination & Status Footer ────────────────────────────── -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div>
                        <strong class="text-zinc-700 dark:text-zinc-300">{{ filteredInterfaces.length }}</strong> interfaces
                        <span v-if="selectedId" class="text-indigo-600 dark:text-indigo-400">
                            • Selected ({{ selectedId }})
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1.5 text-xs text-zinc-500">
                            <span>Per halaman:</span>
                            <select
                                v-model.number="perPage"
                                class="rounded border border-zinc-300 bg-white px-2 py-0.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option :value="15">15</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="0">Semua</option>
                            </select>
                        </div>

                        <div v-if="perPage > 0" class="flex items-center gap-1">
                            <button
                                type="button"
                                :disabled="currentPage <= 1"
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-zinc-100 disabled:opacity-40 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                @click="currentPage--"
                            >
                                ‹ Prev
                            </button>
                            <span class="px-1 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                {{ currentPage }} / {{ totalPages }}
                            </span>
                            <button
                                type="button"
                                :disabled="currentPage >= totalPages"
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-zinc-100 disabled:opacity-40 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                @click="currentPage++"
                            >
                                Next ›
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RouterWorkspace>
</template>
