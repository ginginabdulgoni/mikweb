<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface BridgeItem {
    '.id': string;
    name: string;
    mtu?: string;
    'mac-address'?: string;
    arp?: string;
    running?: string;
    disabled?: string;
    comment?: string;
}

interface BridgePort {
    '.id': string;
    interface: string;
    bridge: string;
    priority?: string;
    'path-cost'?: string;
    horizon?: string;
    disabled?: string;
    comment?: string;
}

const props = defineProps<{
    router: any;
    bridges: BridgeItem[];
    ports: BridgePort[];
    interfaces?: string[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

type BridgeTab = 'bridges' | 'ports';
const activeTab = ref<BridgeTab>('bridges');

// Selection & Batch
const selectedBridgeIds = ref<string[]>([]);
const selectedPortIds = ref<string[]>([]);
const isBatchOperating = ref(false);

// Filter & Search
const search = ref('');

// Sorting & Pagination
const bridgeSortColumn = ref<string>('name');
const bridgeSortDir = ref<'asc' | 'desc'>('asc');
const portSortColumn = ref<string>('interface');
const portSortDir = ref<'asc' | 'desc'>('asc');

const currentPage = ref(1);
const perPage = ref<number | 'all'>(25);

// Modals
const showAddBridge = ref(false);
const showAddPort = ref(false);

const bridgeForm = useForm({
    name: 'bridge2',
    arp: 'enabled',
    comment: '',
});

const portForm = useForm({
    interface: props.interfaces?.[0] ?? 'ether2',
    bridge: props.bridges?.[0]?.name ?? 'bridge1',
    priority: '0x80',
    comment: '',
});

// Copied tooltip state
const copiedField = ref<string | null>(null);
function copyToClipboard(text: string, label: string) {
    if (!text) return;
    navigator.clipboard.writeText(text);
    copiedField.value = `${label}:${text}`;
    setTimeout(() => {
        if (copiedField.value === `${label}:${text}`) {
            copiedField.value = null;
        }
    }, 1800);
}

// KPI Stats
const stats = computed(() => {
    const bList = props.bridges || [];
    const pList = props.ports || [];

    const totalBridges = bList.length;
    const runningBridges = bList.filter((b) => b.running === 'true').length;
    const disabledBridges = bList.filter((b) => b.disabled === 'true').length;

    const totalPorts = pList.length;
    const disabledPorts = pList.filter((p) => p.disabled === 'true').length;
    const activePorts = totalPorts - disabledPorts;

    return {
        totalBridges,
        runningBridges,
        disabledBridges,
        totalPorts,
        activePorts,
        disabledPorts,
    };
});

// Filtered Lists
const filteredBridges = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = props.bridges || [];
    if (!q) return list;
    return list.filter((b) =>
        b.name.toLowerCase().includes(q) ||
        (b['mac-address'] ?? '').toLowerCase().includes(q) ||
        (b.comment ?? '').toLowerCase().includes(q)
    );
});

const sortedBridges = computed(() => {
    const list = [...filteredBridges.value];
    const col = bridgeSortColumn.value;
    const dir = bridgeSortDir.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (col === 'name') return a.name.localeCompare(b.name) * dir;
        if (col === 'mac') return (a['mac-address'] ?? '').localeCompare(b['mac-address'] ?? '') * dir;
        if (col === 'mtu') return (parseInt(a.mtu ?? '1500', 10) - parseInt(b.mtu ?? '1500', 10)) * dir;
        if (col === 'arp') return (a.arp ?? '').localeCompare(b.arp ?? '') * dir;
        if (col === 'status') {
            const valA = a.disabled === 'true' ? 1 : 0;
            const valB = b.disabled === 'true' ? 1 : 0;
            return (valA - valB) * dir;
        }
        if (col === 'comment') return (a.comment ?? '').localeCompare(b.comment ?? '') * dir;
        return 0;
    });
});

const filteredPorts = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = props.ports || [];
    if (!q) return list;
    return list.filter((p) =>
        p.interface.toLowerCase().includes(q) ||
        p.bridge.toLowerCase().includes(q) ||
        (p.comment ?? '').toLowerCase().includes(q)
    );
});

const sortedPorts = computed(() => {
    const list = [...filteredPorts.value];
    const col = portSortColumn.value;
    const dir = portSortDir.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (col === 'interface') return a.interface.localeCompare(b.interface) * dir;
        if (col === 'bridge') return a.bridge.localeCompare(b.bridge) * dir;
        if (col === 'priority') return (a.priority ?? '').localeCompare(b.priority ?? '') * dir;
        if (col === 'path-cost') return (parseInt(a['path-cost'] ?? '10', 10) - parseInt(b['path-cost'] ?? '10', 10)) * dir;
        if (col === 'status') {
            const valA = a.disabled === 'true' ? 1 : 0;
            const valB = b.disabled === 'true' ? 1 : 0;
            return (valA - valB) * dir;
        }
        if (col === 'comment') return (a.comment ?? '').localeCompare(b.comment ?? '') * dir;
        return 0;
    });
});

// Active dataset for pagination
const activeList = computed(() => (activeTab.value === 'bridges' ? sortedBridges.value : sortedPorts.value));

const totalPages = computed(() => {
    if (perPage.value === 'all') return 1;
    return Math.max(1, Math.ceil(activeList.value.length / perPage.value));
});

const paginatedBridges = computed(() => {
    if (perPage.value === 'all') return sortedBridges.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedBridges.value.slice(start, start + perPage.value);
});

const paginatedPorts = computed(() => {
    if (perPage.value === 'all') return sortedPorts.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedPorts.value.slice(start, start + perPage.value);
});

watch([search, activeTab, perPage], () => {
    currentPage.value = 1;
});

// Sort toggles
function toggleBridgeSort(col: string) {
    if (bridgeSortColumn.value === col) {
        bridgeSortDir.value = bridgeSortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        bridgeSortColumn.value = col;
        bridgeSortDir.value = 'asc';
    }
}

function togglePortSort(col: string) {
    if (portSortColumn.value === col) {
        portSortDir.value = portSortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        portSortColumn.value = col;
        portSortDir.value = 'asc';
    }
}

// Selection helpers
const isAllPageSelected = computed(() => {
    if (activeTab.value === 'bridges') {
        const pageItems = paginatedBridges.value;
        if (pageItems.length === 0) return false;
        return pageItems.every((item) => selectedBridgeIds.value.includes(item['.id']));
    } else {
        const pageItems = paginatedPorts.value;
        if (pageItems.length === 0) return false;
        return pageItems.every((item) => selectedPortIds.value.includes(item['.id']));
    }
});

function toggleSelectAllPage() {
    if (activeTab.value === 'bridges') {
        const pageIds = paginatedBridges.value.map((i) => i['.id']);
        if (isAllPageSelected.value) {
            selectedBridgeIds.value = selectedBridgeIds.value.filter((id) => !pageIds.includes(id));
        } else {
            selectedBridgeIds.value = Array.from(new Set([...selectedBridgeIds.value, ...pageIds]));
        }
    } else {
        const pageIds = paginatedPorts.value.map((i) => i['.id']);
        if (isAllPageSelected.value) {
            selectedPortIds.value = selectedPortIds.value.filter((id) => !pageIds.includes(id));
        } else {
            selectedPortIds.value = Array.from(new Set([...selectedPortIds.value, ...pageIds]));
        }
    }
}

function toggleSelectBridge(id: string) {
    const idx = selectedBridgeIds.value.indexOf(id);
    if (idx >= 0) selectedBridgeIds.value.splice(idx, 1);
    else selectedBridgeIds.value.push(id);
}

function toggleSelectPort(id: string) {
    const idx = selectedPortIds.value.indexOf(id);
    if (idx >= 0) selectedPortIds.value.splice(idx, 1);
    else selectedPortIds.value.push(id);
}

function clearSelection() {
    if (activeTab.value === 'bridges') selectedBridgeIds.value = [];
    else selectedPortIds.value = [];
}

// Batch Actions
function runBatchBridges(action: 'enable' | 'disable' | 'destroy') {
    if (selectedBridgeIds.value.length === 0) return;
    const actionLabel = action === 'destroy' ? 'menghapus' : action === 'enable' ? 'mengaktifkan' : 'menonaktifkan';
    if (!confirm(`Apakah Anda yakin ingin ${actionLabel} ${selectedBridgeIds.value.length} bridge terpilih?`)) {
        return;
    }

    isBatchOperating.value = true;
    inertia.post(
        route('routers.bridge.bridges.batch', props.router.id),
        { action, ids: selectedBridgeIds.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isBatchOperating.value = false;
                selectedBridgeIds.value = [];
            },
        },
    );
}

function runBatchPorts(action: 'enable' | 'disable' | 'destroy') {
    if (selectedPortIds.value.length === 0) return;
    const actionLabel = action === 'destroy' ? 'menghapus' : action === 'enable' ? 'mengaktifkan' : 'menonaktifkan';
    if (!confirm(`Apakah Anda yakin ingin ${actionLabel} ${selectedPortIds.value.length} port bridge terpilih?`)) {
        return;
    }

    isBatchOperating.value = true;
    inertia.post(
        route('routers.bridge.ports.batch', props.router.id),
        { action, ids: selectedPortIds.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isBatchOperating.value = false;
                selectedPortIds.value = [];
            },
        },
    );
}

// Single actions
function openAddModal() {
    if (activeTab.value === 'bridges') {
        bridgeForm.reset();
        showAddBridge.value = true;
    } else {
        portForm.reset();
        showAddPort.value = true;
    }
}

function saveBridge() {
    bridgeForm.post(route('routers.bridge.bridges.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddBridge.value = false;
            bridgeForm.reset();
        },
    });
}

function savePort() {
    portForm.post(route('routers.bridge.ports.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddPort.value = false;
            portForm.reset();
        },
    });
}

function toggleBridge(b: BridgeItem) {
    inertia.post(
        route('routers.bridge.bridges.toggle', props.router.id),
        { id: b['.id'], disable: b.disabled !== 'true' },
        { preserveScroll: true },
    );
}

function togglePort(p: BridgePort) {
    inertia.post(
        route('routers.bridge.ports.toggle', props.router.id),
        { id: p['.id'], disable: p.disabled !== 'true' },
        { preserveScroll: true },
    );
}

function removeBridge(b: BridgeItem) {
    if (!confirm(`Hapus bridge ${b.name}?`)) return;
    inertia.delete(route('routers.bridge.bridges.destroy', props.router.id), {
        data: { id: b['.id'] },
        preserveScroll: true,
        onSuccess: () => {
            selectedBridgeIds.value = selectedBridgeIds.value.filter((id) => id !== b['.id']);
        },
    });
}

function removePort(p: BridgePort) {
    if (!confirm(`Hapus port ${p.interface} dari bridge ${p.bridge}?`)) return;
    inertia.delete(route('routers.bridge.ports.destroy', props.router.id), {
        data: { id: p['.id'] },
        preserveScroll: true,
        onSuccess: () => {
            selectedPortIds.value = selectedPortIds.value.filter((id) => id !== p['.id']);
        },
    });
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// CSV Export
function exportCsv() {
    if (activeTab.value === 'bridges') {
        const rows = sortedBridges.value.map((b, idx) => ({
            '#': idx + 1,
            Name: b.name,
            'MAC Address': b['mac-address'] ?? '',
            MTU: b.mtu ?? '1500',
            ARP: b.arp ?? 'enabled',
            Status: b.disabled === 'true' ? 'Disabled' : 'Enabled',
            Running: b.running === 'true' ? 'Yes' : 'No',
            Comment: b.comment ?? '',
        }));

        if (rows.length === 0) return;
        const headers = Object.keys(rows[0]!).join(',');
        const csvContent = [headers, ...rows.map((r) => Object.values(r).map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))].join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `bridge-interfaces-${props.router.name || 'router'}-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
    } else {
        const rows = sortedPorts.value.map((p, idx) => ({
            '#': idx + 1,
            Interface: p.interface,
            Bridge: p.bridge,
            Priority: p.priority ?? '0x80',
            'Path Cost': p['path-cost'] ?? '10',
            Status: p.disabled === 'true' ? 'Disabled' : 'Enabled',
            Comment: p.comment ?? '',
        }));

        if (rows.length === 0) return;
        const headers = Object.keys(rows[0]!).join(',');
        const csvContent = [headers, ...rows.map((r) => Object.values(r).map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))].join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `bridge-ports-${props.router.name || 'router'}-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
    }
}
</script>

<template>
    <Head :title="`Bridge — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- 6 KPI Stat Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Bridges -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total Bridges</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        {{ stats.totalBridges }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Virtual Switch</span>
                </div>

                <!-- Running Bridges -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Running</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                        {{ stats.runningBridges }}
                    </p>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Bridge aktif</span>
                </div>

                <!-- Disabled Bridges -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Bridge Off</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-zinc-400"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-600 dark:text-zinc-300">
                        {{ stats.disabledBridges }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Non-aktif</span>
                </div>

                <!-- Total Ports -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Member Ports</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                        {{ stats.totalPorts }}
                    </p>
                    <span class="text-[11px] text-indigo-600 dark:text-indigo-400">Port tergabung</span>
                </div>

                <!-- Active Ports -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Active Ports</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                        {{ stats.activePorts }}
                    </p>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Forwarding data</span>
                </div>

                <!-- Disabled Ports -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Disabled Ports</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-amber-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
                        {{ stats.disabledPorts }}
                    </p>
                    <span class="text-[11px] text-amber-600 dark:text-amber-400">Port non-aktif</span>
                </div>
            </div>

            <!-- Main Card -->
            <div class="flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header Tabs -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Bridge Configuration</span>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /interface/bridge
                            </span>
                        </div>
                    </div>

                    <div class="flex gap-1 overflow-x-auto text-xs">
                        <button
                            type="button"
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'bridges'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'bridges'"
                        >
                            Bridges ({{ bridges.length }})
                        </button>
                        <button
                            type="button"
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'ports'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'ports'"
                        >
                            Ports ({{ ports.length }})
                        </button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage" size="sm" variant="primary" class="gap-1.5" @click="openAddModal">
                            <span>＋</span> {{ activeTab === 'bridges' ? 'Add Bridge' : 'Add Member Port' }}
                        </Btn>
                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh
                        </Btn>
                        <Btn size="sm" variant="ghost" title="Export CSV" @click="exportCsv">
                            ⤓ Export CSV
                        </Btn>
                    </div>

                    <div class="relative">
                        <input
                            v-model="search"
                            type="search"
                            :placeholder="activeTab === 'bridges' ? 'Cari nama bridge, MAC...' : 'Cari interface, bridge...'"
                            class="w-48 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 pl-8 text-xs placeholder-zinc-400 transition-all focus:w-64 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                        <span class="pointer-events-none absolute left-2.5 top-1.5 text-xs text-zinc-400">⌕</span>
                    </div>
                </div>

                <!-- Floating Sticky Batch Actions Bar -->
                <div
                    v-if="(activeTab === 'bridges' ? selectedBridgeIds.length : selectedPortIds.length) > 0"
                    class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/95 px-4 py-2.5 backdrop-blur-xs dark:border-indigo-900/60 dark:bg-indigo-950/90"
                >
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-bold text-white">
                            {{ activeTab === 'bridges' ? selectedBridgeIds.length : selectedPortIds.length }}
                        </span>
                        <span class="text-xs font-semibold text-indigo-950 dark:text-indigo-200">
                            {{ activeTab === 'bridges' ? selectedBridgeIds.length : selectedPortIds.length }} item dipilih
                        </span>
                    </div>

                    <div v-if="can.manage" class="flex flex-wrap items-center gap-1.5">
                        <template v-if="activeTab === 'bridges'">
                            <Btn size="sm" :disabled="isBatchOperating" @click="runBatchBridges('enable')">
                                ✓ Enable
                            </Btn>
                            <Btn size="sm" :disabled="isBatchOperating" @click="runBatchBridges('disable')">
                                ✕ Disable
                            </Btn>
                            <Btn size="sm" variant="danger" :disabled="isBatchOperating" @click="runBatchBridges('destroy')">
                                🗑 Delete
                            </Btn>
                        </template>
                        <template v-else>
                            <Btn size="sm" :disabled="isBatchOperating" @click="runBatchPorts('enable')">
                                ✓ Enable
                            </Btn>
                            <Btn size="sm" :disabled="isBatchOperating" @click="runBatchPorts('disable')">
                                ✕ Disable
                            </Btn>
                            <Btn size="sm" variant="danger" :disabled="isBatchOperating" @click="runBatchPorts('destroy')">
                                🗑 Delete
                            </Btn>
                        </template>
                        <button
                            type="button"
                            class="ml-2 text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
                            @click="clearSelection"
                        >
                            Batal
                        </button>
                    </div>
                </div>

                <!-- Tables -->
                <div class="flex-1 overflow-auto">
                    <!-- 1. Bridges Table -->
                    <table v-if="activeTab === 'bridges'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        class="rounded-sm border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        :checked="isAllPageSelected"
                                        @change="toggleSelectAllPage"
                                    />
                                </th>
                                <th class="w-8 px-2 py-2 text-center">#</th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleBridgeSort('name')">
                                    <div class="flex items-center gap-1">
                                        <span>Name</span>
                                        <span class="text-[10px] text-zinc-400">{{ bridgeSortColumn === 'name' ? (bridgeSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleBridgeSort('mac')">
                                    <div class="flex items-center gap-1">
                                        <span>MAC Address</span>
                                        <span class="text-[10px] text-zinc-400">{{ bridgeSortColumn === 'mac' ? (bridgeSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleBridgeSort('mtu')">
                                    <div class="flex items-center gap-1">
                                        <span>MTU</span>
                                        <span class="text-[10px] text-zinc-400">{{ bridgeSortColumn === 'mtu' ? (bridgeSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleBridgeSort('arp')">
                                    <div class="flex items-center gap-1">
                                        <span>ARP</span>
                                        <span class="text-[10px] text-zinc-400">{{ bridgeSortColumn === 'arp' ? (bridgeSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleBridgeSort('status')">
                                    <div class="flex items-center gap-1">
                                        <span>Status</span>
                                        <span class="text-[10px] text-zinc-400">{{ bridgeSortColumn === 'status' ? (bridgeSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleBridgeSort('comment')">
                                    <div class="flex items-center gap-1">
                                        <span>Comment</span>
                                        <span class="text-[10px] text-zinc-400">{{ bridgeSortColumn === 'comment' ? (bridgeSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="(b, idx) in paginatedBridges"
                                :key="b['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedBridgeIds.includes(b['.id']) && 'bg-indigo-100/60 dark:bg-indigo-950/40',
                                    b.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="toggleSelectBridge(b['.id'])"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded-sm border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        :checked="selectedBridgeIds.includes(b['.id'])"
                                        @change="toggleSelectBridge(b['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2 text-center font-sans text-zinc-400">
                                    {{ (currentPage - 1) * (perPage === 'all' ? 0 : perPage) + idx + 1 }}
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ b.name }}</span>
                                        <button
                                            type="button"
                                            title="Copy Name"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(b.name, 'name')"
                                        >
                                            <span v-if="copiedField === `name:${b.name}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ b['mac-address'] || '-' }}</span>
                                        <button
                                            v-if="b['mac-address']"
                                            type="button"
                                            title="Copy MAC"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(b['mac-address'], 'mac')"
                                        >
                                            <span v-if="copiedField === `mac:${b['mac-address']}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ b.mtu || '1500' }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ b.arp || 'enabled' }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-1">
                                        <span v-if="b.disabled === 'true'" class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-sans text-zinc-500 dark:bg-zinc-800">
                                            disabled
                                        </span>
                                        <span v-else class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-sans font-medium text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400">
                                            enabled
                                        </span>
                                        <span v-if="b.running === 'true'" class="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-sans font-medium text-blue-600 dark:bg-blue-950/40 dark:text-blue-400">
                                            running
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ b.comment || '-' }}</td>
                                <td class="px-3 py-2 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end items-center gap-1 font-sans">
                                        <Btn
                                            size="sm"
                                            :variant="b.disabled === 'true' ? 'primary' : 'ghost'"
                                            @click="toggleBridge(b)"
                                        >
                                            {{ b.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="danger" @click="removeBridge(b)">
                                            Del
                                        </Btn>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="paginatedBridges.length === 0">
                                <td colspan="9" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    Tidak ada bridge yang ditemukan.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 2. Ports Table -->
                    <table v-else class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        class="rounded-sm border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        :checked="isAllPageSelected"
                                        @change="toggleSelectAllPage"
                                    />
                                </th>
                                <th class="w-8 px-2 py-2 text-center">#</th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="togglePortSort('interface')">
                                    <div class="flex items-center gap-1">
                                        <span>Interface</span>
                                        <span class="text-[10px] text-zinc-400">{{ portSortColumn === 'interface' ? (portSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="togglePortSort('bridge')">
                                    <div class="flex items-center gap-1">
                                        <span>Bridge</span>
                                        <span class="text-[10px] text-zinc-400">{{ portSortColumn === 'bridge' ? (portSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="togglePortSort('priority')">
                                    <div class="flex items-center gap-1">
                                        <span>Priority</span>
                                        <span class="text-[10px] text-zinc-400">{{ portSortColumn === 'priority' ? (portSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="togglePortSort('path-cost')">
                                    <div class="flex items-center gap-1">
                                        <span>Path Cost</span>
                                        <span class="text-[10px] text-zinc-400">{{ portSortColumn === 'path-cost' ? (portSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="togglePortSort('status')">
                                    <div class="flex items-center gap-1">
                                        <span>Status</span>
                                        <span class="text-[10px] text-zinc-400">{{ portSortColumn === 'status' ? (portSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="togglePortSort('comment')">
                                    <div class="flex items-center gap-1">
                                        <span>Comment</span>
                                        <span class="text-[10px] text-zinc-400">{{ portSortColumn === 'comment' ? (portSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="(p, idx) in paginatedPorts"
                                :key="p['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedPortIds.includes(p['.id']) && 'bg-indigo-100/60 dark:bg-indigo-950/40',
                                    p.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="toggleSelectPort(p['.id'])"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded-sm border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        :checked="selectedPortIds.includes(p['.id'])"
                                        @change="toggleSelectPort(p['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2 text-center font-sans text-zinc-400">
                                    {{ (currentPage - 1) * (perPage === 'all' ? 0 : perPage) + idx + 1 }}
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ p.interface }}</span>
                                        <button
                                            type="button"
                                            title="Copy Interface"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(p.interface, 'iface')"
                                        >
                                            <span v-if="copiedField === `iface:${p.interface}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-indigo-700 dark:text-indigo-400">
                                    <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] dark:bg-indigo-950/50">
                                        {{ p.bridge }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ p.priority || '0x80' }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ p['path-cost'] || '10' }}</td>
                                <td class="px-3 py-2">
                                    <span v-if="p.disabled === 'true'" class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-sans text-zinc-500 dark:bg-zinc-800">
                                        disabled
                                    </span>
                                    <span v-else class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-sans font-medium text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400">
                                        active
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ p.comment || '-' }}</td>
                                <td class="px-3 py-2 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end items-center gap-1 font-sans">
                                        <Btn
                                            size="sm"
                                            :variant="p.disabled === 'true' ? 'primary' : 'ghost'"
                                            @click="togglePort(p)"
                                        >
                                            {{ p.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="danger" @click="removePort(p)">
                                            Del
                                        </Btn>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="paginatedPorts.length === 0">
                                <td colspan="9" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    Tidak ada member port bridge yang ditemukan.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Pagination & Range Bar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-4">
                        <span>
                            Menampilkan <strong>{{ activeList.length === 0 ? 0 : (currentPage - 1) * (perPage === 'all' ? activeList.length : perPage) + 1 }}</strong> - <strong>{{ perPage === 'all' ? activeList.length : Math.min(currentPage * perPage, activeList.length) }}</strong> dari <strong>{{ activeList.length }}</strong> {{ activeTab === 'bridges' ? 'bridge' : 'port' }}
                        </span>
                        <span v-if="(activeTab === 'bridges' ? selectedBridgeIds.length : selectedPortIds.length) > 0" class="text-indigo-600 dark:text-indigo-400 font-semibold">
                            ({{ activeTab === 'bridges' ? selectedBridgeIds.length : selectedPortIds.length }} terpilih)
                        </span>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs">Baris:</span>
                            <select
                                v-model="perPage"
                                class="rounded-lg border border-zinc-200 bg-white px-2 py-1 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option :value="15">15</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option value="all">Semua</option>
                            </select>
                        </div>

                        <div v-if="totalPages > 1" class="flex items-center gap-1">
                            <button
                                type="button"
                                :disabled="currentPage === 1"
                                class="rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-40 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                @click="currentPage--"
                            >
                                « Prev
                            </button>
                            <span class="px-2 text-xs font-medium text-zinc-600 dark:text-zinc-300">
                                {{ currentPage }} / {{ totalPages }}
                            </span>
                            <button
                                type="button"
                                :disabled="currentPage >= totalPages"
                                class="rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-40 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                @click="currentPage++"
                            >
                                Next »
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: Add Bridge -->
            <Modal :show="showAddBridge" title="New Bridge" @close="showAddBridge = false">
                <form class="space-y-3" @submit.prevent="saveBridge">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Bridge Name</label>
                        <input
                            v-model="bridgeForm.name"
                            type="text"
                            required
                            placeholder="e.g. bridge-lan"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">ARP Mode</label>
                        <select v-model="bridgeForm.arp" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="enabled">enabled</option>
                            <option value="disabled">disabled</option>
                            <option value="proxy-arp">proxy-arp</option>
                            <option value="reply-only">reply-only</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="bridgeForm.comment"
                            type="text"
                            placeholder="Note"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Btn type="button" variant="secondary" @click="showAddBridge = false">Cancel</Btn>
                        <Btn type="submit" variant="primary" :disabled="bridgeForm.processing">Create Bridge</Btn>
                    </div>
                </form>
            </Modal>

            <!-- Modal: Add Port -->
            <Modal :show="showAddPort" title="Add Member Port to Bridge" @close="showAddPort = false">
                <form class="space-y-3" @submit.prevent="savePort">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Interface</label>
                        <select v-model="portForm.interface" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <option v-for="iface in interfaces" :key="iface" :value="iface">{{ iface }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Bridge</label>
                        <select v-model="portForm.bridge" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <option v-for="b in bridges" :key="b['.id']" :value="b.name">{{ b.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Priority</label>
                        <input
                            v-model="portForm.priority"
                            type="text"
                            placeholder="0x80"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="portForm.comment"
                            type="text"
                            placeholder="Note"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Btn type="button" variant="secondary" @click="showAddPort = false">Cancel</Btn>
                        <Btn type="submit" variant="primary" :disabled="portForm.processing">Add Port</Btn>
                    </div>
                </form>
            </Modal>
        </div>
    </RouterWorkspace>
</template>
