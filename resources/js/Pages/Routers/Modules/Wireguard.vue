<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface WgInterface {
    '.id': string;
    name: string;
    'listen-port'?: string;
    'public-key'?: string;
    mtu?: string;
    running?: string;
    disabled?: string;
    comment?: string;
}

interface WgPeer {
    '.id': string;
    interface: string;
    'public-key': string;
    'endpoint-address'?: string;
    'endpoint-port'?: string;
    'current-endpoint-address'?: string;
    'allowed-address': string;
    'latest-handshake'?: string;
    rx?: string;
    tx?: string;
    disabled?: string;
    comment?: string;
}

const props = defineProps<{
    router: any;
    interfaces: WgInterface[];
    peers: WgPeer[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

const activeTab = ref<'interfaces' | 'peers'>('interfaces');
const search = ref('');
const peerIfaceFilter = ref('all');
const peerStatusFilter = ref<'all' | 'active' | 'disabled'>('all');

const selectedIfaceId = ref<string | null>(null);
const selectedPeerId = ref<string | null>(null);

// ── Multi Selection & Batch Bar for Peers ────────────────────────────────
const selectedPeerIds = ref<string[]>([]);
const batchLoading = ref(false);

// ── Copy feedback toast ──────────────────────────────────────────────────
const copiedText = ref<string | null>(null);
let copyTimeout: ReturnType<typeof setTimeout> | null = null;
function copyValue(text: string, label: string) {
    if (!text) return;
    navigator.clipboard.writeText(text);
    if (copyTimeout) clearTimeout(copyTimeout);
    copiedText.value = `${label}: ${text}`;
    copyTimeout = setTimeout(() => {
        copiedText.value = null;
    }, 2000);
}

// ── Helpers ──────────────────────────────────────────────────────────────
function formatBytes(bytesStr?: string): string {
    const bytes = Number(bytesStr ?? 0);
    if (isNaN(bytes) || bytes <= 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// ── Sorting ─────────────────────────────────────────────────────────────
type IfaceSortField = 'name' | 'listen-port' | 'mtu' | 'disabled';
const ifaceSortField = ref<IfaceSortField>('name');
const ifaceSortDirection = ref<'asc' | 'desc'>('asc');

function toggleIfaceSort(field: IfaceSortField) {
    if (ifaceSortField.value === field) {
        ifaceSortDirection.value = ifaceSortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        ifaceSortField.value = field;
        ifaceSortDirection.value = 'asc';
    }
}

type PeerSortField = 'interface' | 'allowed-address' | 'endpoint-address' | 'latest-handshake' | 'rx' | 'disabled';
const peerSortField = ref<PeerSortField>('interface');
const peerSortDirection = ref<'asc' | 'desc'>('asc');

function togglePeerSort(field: PeerSortField) {
    if (peerSortField.value === field) {
        peerSortDirection.value = peerSortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        peerSortField.value = field;
        peerSortDirection.value = 'asc';
    }
}

// ── Pagination ───────────────────────────────────────────────────────────
const perPage = ref<number>(25);
const currentIfacePage = ref<number>(1);
const currentPeerPage = ref<number>(1);

// ── Modals & Forms ───────────────────────────────────────────────────────
const showAddIface = ref(false);
const showEditIface = ref(false);
const showAddPeer = ref(false);
const showEditPeer = ref(false);

const ifaceForm = useForm({
    id: '',
    name: 'wireguard1',
    listen_port: 13231,
    mtu: 1420,
    comment: '',
});

const peerForm = useForm({
    id: '',
    interface: 'wireguard1',
    public_key: '',
    allowed_address: '10.10.0.2/32',
    endpoint_address: '',
    endpoint_port: 13231,
    persistent_keepalive: '25s',
    comment: '',
});

const ifaceList = computed(() => props.interfaces ?? []);
const peerList = computed(() => props.peers ?? []);

// ── KPI Statistics ───────────────────────────────────────────────────────
const kpiStats = computed(() => {
    const ifaces = ifaceList.value;
    const peers = peerList.value;

    const totalTraffic = peers.reduce((acc, p) => {
        const rx = Number(p.rx ?? 0);
        const tx = Number(p.tx ?? 0);
        return acc + (isNaN(rx) ? 0 : rx) + (isNaN(tx) ? 0 : tx);
    }, 0);

    const activePeers = peers.filter(
        (p) => p['latest-handshake'] && p['latest-handshake'].trim() !== '' && p['latest-handshake'] !== '-',
    ).length;

    return {
        totalIfaces: ifaces.length,
        runningIfaces: ifaces.filter((i) => i.disabled !== 'true').length,
        totalPeers: peers.length,
        activeHandshakes: activePeers,
        totalTraffic: formatBytes(totalTraffic.toString()),
        disabledPeers: peers.filter((p) => p.disabled === 'true').length,
    };
});

// ── Filtered & Sorted Interfaces ─────────────────────────────────────────
const filteredIfaces = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return ifaceList.value;
    return ifaceList.value.filter(
        (i) =>
            i.name.toLowerCase().includes(q) ||
            (i['public-key'] ?? '').toLowerCase().includes(q) ||
            (i.comment ?? '').toLowerCase().includes(q),
    );
});

const sortedIfaces = computed(() => {
    const list = [...filteredIfaces.value];
    const f = ifaceSortField.value;
    const dir = ifaceSortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        const valA = (a[f] ?? '').toLowerCase();
        const valB = (b[f] ?? '').toLowerCase();
        return valA.localeCompare(valB) * dir;
    });
});

const totalIfacePages = computed(() => {
    if (perPage.value <= 0) return 1;
    return Math.max(1, Math.ceil(sortedIfaces.value.length / perPage.value));
});

const paginatedIfaces = computed(() => {
    if (perPage.value <= 0) return sortedIfaces.value;
    const start = (currentIfacePage.value - 1) * perPage.value;
    return sortedIfaces.value.slice(start, start + perPage.value);
});

// ── Filtered & Sorted Peers ──────────────────────────────────────────────
const filteredPeers = computed(() => {
    const q = search.value.trim().toLowerCase();
    const iface = peerIfaceFilter.value;
    const st = peerStatusFilter.value;

    return peerList.value.filter((p) => {
        if (iface !== 'all' && p.interface !== iface) return false;
        if (st === 'active' && (p.disabled === 'true' || !p['latest-handshake'] || p['latest-handshake'] === '-')) {
            return false;
        }
        if (st === 'disabled' && p.disabled !== 'true') return false;
        if (!q) return true;
        return (
            p.interface.toLowerCase().includes(q) ||
            p['public-key'].toLowerCase().includes(q) ||
            p['allowed-address'].toLowerCase().includes(q) ||
            (p['endpoint-address'] ?? '').toLowerCase().includes(q) ||
            (p.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedPeers = computed(() => {
    const list = [...filteredPeers.value];
    const f = peerSortField.value;
    const dir = peerSortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (f === 'rx') {
            const rxA = Number(a.rx ?? 0);
            const rxB = Number(b.rx ?? 0);
            return (rxA - rxB) * dir;
        }
        const valA = (a[f] ?? '').toLowerCase();
        const valB = (b[f] ?? '').toLowerCase();
        return valA.localeCompare(valB) * dir;
    });
});

const totalPeerPages = computed(() => {
    if (perPage.value <= 0) return 1;
    return Math.max(1, Math.ceil(sortedPeers.value.length / perPage.value));
});

const paginatedPeers = computed(() => {
    if (perPage.value <= 0) return sortedPeers.value;
    const start = (currentPeerPage.value - 1) * perPage.value;
    return sortedPeers.value.slice(start, start + perPage.value);
});

// ── Multi Selection Controls ─────────────────────────────────────────────
const isAllPeersSelected = computed(() => {
    if (sortedPeers.value.length === 0) return false;
    return sortedPeers.value.every((p) => selectedPeerIds.value.includes(p['.id']));
});

function toggleSelectAllPeers() {
    if (isAllPeersSelected.value) {
        selectedPeerIds.value = [];
    } else {
        selectedPeerIds.value = sortedPeers.value.map((p) => p['.id']);
    }
}

function toggleSelectPeer(id: string) {
    const idx = selectedPeerIds.value.indexOf(id);
    if (idx >= 0) {
        selectedPeerIds.value.splice(idx, 1);
    } else {
        selectedPeerIds.value.push(id);
    }
}

// ── Batch Operations ─────────────────────────────────────────────────────
function executePeerBatch(action: 'enable' | 'disable' | 'destroy') {
    if (selectedPeerIds.value.length === 0) return;
    if (action === 'destroy' && !confirm(`Hapus permanen ${selectedPeerIds.value.length} WireGuard peer yang dipilih?`)) {
        return;
    }

    batchLoading.value = true;
    inertia.post(
        route('routers.wireguard.peers.batch', props.router.id),
        {
            action,
            ids: selectedPeerIds.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                batchLoading.value = false;
                selectedPeerIds.value = [];
            },
        },
    );
}

// ── Single Operations: Interface ─────────────────────────────────────────
function openAddIface() {
    ifaceForm.reset();
    ifaceForm.id = '';
    ifaceForm.name = `wireguard${ifaceList.value.length + 1}`;
    ifaceForm.listen_port = 13231 + ifaceList.value.length;
    ifaceForm.mtu = 1420;
    showAddIface.value = true;
}

function openEditIface(item: WgInterface) {
    ifaceForm.id = item['.id'];
    ifaceForm.name = item.name;
    ifaceForm.listen_port = item['listen-port'] ? parseInt(item['listen-port'], 10) : 13231;
    ifaceForm.mtu = item.mtu ? parseInt(item.mtu, 10) : 1420;
    ifaceForm.comment = item.comment || '';
    showEditIface.value = true;
}

function saveIface() {
    ifaceForm.post(route('routers.wireguard.interfaces.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddIface.value = false;
            ifaceForm.reset();
        },
    });
}

function updateIface() {
    ifaceForm.put(route('routers.wireguard.interfaces.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditIface.value = false;
        },
    });
}

function toggleIface(item: WgInterface, disable: boolean) {
    inertia.post(
        route('routers.wireguard.interfaces.toggle', props.router.id),
        { id: item['.id'], disable },
        { preserveScroll: true },
    );
}

function removeSingleIface(item: WgInterface) {
    if (!confirm(`Hapus WireGuard interface "${item.name}"?`)) return;

    inertia.delete(route('routers.wireguard.interfaces.destroy', props.router.id), {
        data: { id: item['.id'] },
        preserveScroll: true,
        onSuccess: () => {
            selectedIfaceId.value = null;
        },
    });
}

// ── Single Operations: Peer ──────────────────────────────────────────────
function openAddPeer() {
    peerForm.reset();
    peerForm.id = '';
    peerForm.interface = ifaceList.value[0]?.name ?? 'wireguard1';
    showAddPeer.value = true;
}

function openEditPeer(item: WgPeer) {
    peerForm.id = item['.id'];
    peerForm.interface = item.interface;
    peerForm.public_key = item['public-key'];
    peerForm.allowed_address = item['allowed-address'];
    peerForm.endpoint_address = item['endpoint-address'] || '';
    peerForm.endpoint_port = item['endpoint-port'] ? parseInt(item['endpoint-port'], 10) : 13231;
    peerForm.comment = item.comment || '';
    showEditPeer.value = true;
}

function savePeer() {
    peerForm.post(route('routers.wireguard.peers.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddPeer.value = false;
            peerForm.reset();
        },
    });
}

function updatePeer() {
    peerForm.put(route('routers.wireguard.peers.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditPeer.value = false;
        },
    });
}

function togglePeer(item: WgPeer, disable: boolean) {
    inertia.post(
        route('routers.wireguard.peers.toggle', props.router.id),
        { id: item['.id'], disable },
        { preserveScroll: true },
    );
}

function removeSinglePeer(item: WgPeer) {
    if (!confirm(`Hapus WireGuard peer dengan IP "${item['allowed-address']}"?`)) return;

    inertia.delete(route('routers.wireguard.peers.destroy', props.router.id), {
        data: { id: item['.id'] },
        preserveScroll: true,
        onSuccess: () => {
            selectedPeerId.value = null;
        },
    });
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// ── CSV Export ───────────────────────────────────────────────────────────
function exportCsv() {
    if (activeTab.value === 'interfaces') {
        const rows = sortedIfaces.value.map((i) => [
            i.name,
            i['listen-port'] || '13231',
            i['public-key'] || '',
            i.mtu || '1420',
            i.disabled === 'true' ? 'disabled' : 'running',
            i.comment || '',
        ]);
        const headers = ['Interface', 'Listen Port', 'Public Key', 'MTU', 'Status', 'Comment'];
        const csvContent =
            'data:text/csv;charset=utf-8,' +
            [headers.join(','), ...rows.map((e) => e.map((val) => `"${(val || '').replace(/"/g, '""')}"`).join(','))].join(
                '\n',
            );

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement('a');
        link.setAttribute('href', encodedUri);
        link.setAttribute('download', `wireguard_interfaces_${props.router.name}_${new Date().toISOString().slice(0, 10)}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    } else {
        const rows = sortedPeers.value.map((p) => [
            p.interface,
            p['public-key'],
            p['allowed-address'],
            p['endpoint-address'] ? `${p['endpoint-address']}:${p['endpoint-port'] || '13231'}` : '',
            p['latest-handshake'] || '',
            p.rx || '0',
            p.tx || '0',
            p.disabled === 'true' ? 'disabled' : 'active',
            p.comment || '',
        ]);
        const headers = ['Interface', 'Public Key', 'Allowed IPs', 'Endpoint', 'Latest Handshake', 'RX Bytes', 'TX Bytes', 'Status', 'Comment'];
        const csvContent =
            'data:text/csv;charset=utf-8,' +
            [headers.join(','), ...rows.map((e) => e.map((val) => `"${(val || '').replace(/"/g, '""')}"`).join(','))].join(
                '\n',
            );

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement('a');
        link.setAttribute('href', encodedUri);
        link.setAttribute('download', `wireguard_peers_${props.router.name}_${new Date().toISOString().slice(0, 10)}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
}
</script>

<template>
    <Head :title="`WireGuard VPN — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- Toast Feedback -->
            <transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-y-2 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="translate-y-2 opacity-0"
            >
                <div
                    v-if="copiedText"
                    class="fixed bottom-4 right-4 z-50 rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-medium text-white shadow-lg dark:bg-zinc-100 dark:text-zinc-900"
                >
                    ✓ Disalin: {{ copiedText }}
                </div>
            </transition>

            <!-- 6 KPI Stat Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div class="rounded-xl border border-zinc-200 bg-white p-3 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">WG Interfaces</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ kpiStats.totalIfaces }}</div>
                    <div class="mt-1 text-[10px] text-zinc-400">Tunnel listen ports</div>
                </div>

                <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <div class="text-[11px] font-medium text-emerald-700 dark:text-emerald-400">Running Interfaces</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-emerald-700 dark:text-emerald-300">{{ kpiStats.runningIfaces }}</div>
                    <div class="mt-1 text-[10px] text-emerald-600/70 dark:text-emerald-400/60">Aktif beroperasi</div>
                </div>

                <div class="rounded-xl border border-indigo-200 bg-indigo-50/40 p-3 shadow-xs dark:border-indigo-900/40 dark:bg-indigo-950/20">
                    <div class="text-[11px] font-medium text-indigo-700 dark:text-indigo-400">Total Peers</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-indigo-700 dark:text-indigo-300">{{ kpiStats.totalPeers }}</div>
                    <div class="mt-1 text-[10px] text-indigo-600/70 dark:text-indigo-400/60">Client / site tunnel</div>
                </div>

                <div class="rounded-xl border border-cyan-200 bg-cyan-50/40 p-3 shadow-xs dark:border-cyan-900/40 dark:bg-cyan-950/20">
                    <div class="text-[11px] font-medium text-cyan-700 dark:text-cyan-400">Active Handshake</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-cyan-700 dark:text-cyan-300">{{ kpiStats.activeHandshakes }}</div>
                    <div class="mt-1 text-[10px] text-cyan-600/70 dark:text-cyan-400/60">Terkoneksi real-time</div>
                </div>

                <div class="rounded-xl border border-violet-200 bg-violet-50/40 p-3 shadow-xs dark:border-violet-900/40 dark:bg-violet-950/20">
                    <div class="text-[11px] font-medium text-violet-700 dark:text-violet-400">Tunnel Traffic</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-violet-700 dark:text-violet-300 truncate">{{ kpiStats.totalTraffic }}</div>
                    <div class="mt-1 text-[10px] text-violet-600/70 dark:text-violet-400/60">Total RX + TX</div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-zinc-50/60 p-3 shadow-xs dark:border-zinc-800 dark:bg-zinc-900/50">
                    <div class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">Disabled Peers</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-zinc-600 dark:text-zinc-400">{{ kpiStats.disabledPeers }}</div>
                    <div class="mt-1 text-[10px] text-zinc-400">Tunnel dinonaktifkan</div>
                </div>
            </div>

            <!-- Main Panel -->
            <div class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header Tabs -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">WireGuard VPN</span>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /interface/wireguard
                            </span>
                        </div>
                    </div>

                    <div class="flex gap-1 overflow-x-auto text-xs">
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'interfaces'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'interfaces'"
                        >
                            Interfaces ({{ ifaceList.length }})
                        </button>
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'peers'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'peers'"
                        >
                            Peers ({{ peerList.length }})
                        </button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <template v-if="activeTab === 'interfaces' && can.manage">
                            <Btn size="sm" variant="primary" class="gap-1.5" @click="openAddIface">
                                <span>＋</span> Tambah Interface
                            </Btn>
                        </template>

                        <template v-if="activeTab === 'peers' && can.manage">
                            <Btn size="sm" variant="primary" class="gap-1.5" @click="openAddPeer">
                                <span>＋</span> Tambah Peer
                            </Btn>
                        </template>

                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh
                        </Btn>

                        <Btn size="sm" variant="secondary" title="Export CSV" @click="exportCsv">
                            ⬇ Export CSV
                        </Btn>

                        <div v-if="activeTab === 'peers'" class="mx-1 h-4 w-px bg-zinc-300 dark:bg-zinc-700"></div>

                        <!-- Filter Interface for Peers -->
                        <div v-if="activeTab === 'peers'" class="flex items-center gap-1 text-xs text-zinc-500">
                            <span>Interface:</span>
                            <select
                                v-model="peerIfaceFilter"
                                class="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <option value="all">Semua Interface</option>
                                <option v-for="i in ifaceList" :key="i.name" :value="i.name">{{ i.name }}</option>
                            </select>
                        </div>

                        <!-- Filter Status for Peers -->
                        <div v-if="activeTab === 'peers'" class="flex items-center gap-1 text-xs text-zinc-500">
                            <span>Status:</span>
                            <select
                                v-model="peerStatusFilter"
                                class="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <option value="all">Semua Status</option>
                                <option value="active">Active Handshake</option>
                                <option value="disabled">Disabled</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari interface, key, ip..."
                            class="w-52 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>

                <!-- Floating Sticky Batch Bar for Peers -->
                <transition
                    enter-active-class="transition duration-150 ease-out"
                    enter-from-class="-translate-y-2 opacity-0"
                    enter-to-class="translate-y-0 opacity-100"
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="translate-y-0 opacity-100"
                    leave-to-class="-translate-y-2 opacity-0"
                >
                    <div
                        v-if="activeTab === 'peers' && selectedPeerIds.length > 0"
                        class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-200 bg-indigo-50 px-4 py-2 dark:border-indigo-900/50 dark:bg-indigo-950/40"
                    >
                        <div class="flex items-center gap-2 text-xs font-medium text-indigo-900 dark:text-indigo-200">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">
                                {{ selectedPeerIds.length }}
                            </span>
                            <span>peer dipilih</span>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="batchLoading"
                                @click="executePeerBatch('enable')"
                            >
                                ✓ Aktifkan
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="batchLoading"
                                @click="executePeerBatch('disable')"
                            >
                                ✕ Nonaktifkan
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                class="text-rose-600 hover:bg-rose-100 dark:text-rose-400 dark:hover:bg-rose-950/40"
                                :disabled="batchLoading"
                                @click="executePeerBatch('destroy')"
                            >
                                🗑 Hapus Terpilih
                            </Btn>
                            <button
                                class="ml-2 text-xs text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
                                @click="selectedPeerIds = []"
                            >
                                Batal
                            </button>
                        </div>
                    </div>
                </transition>

                <!-- Tables -->
                <div class="flex-1 overflow-auto">
                    <!-- 1. Interfaces Table -->
                    <table v-if="activeTab === 'interfaces'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-2 py-2 text-center">St</th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleIfaceSort('name')">
                                    Name
                                    <span v-if="ifaceSortField === 'name'">{{ ifaceSortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleIfaceSort('listen-port')">
                                    Listen Port
                                    <span v-if="ifaceSortField === 'listen-port'">{{ ifaceSortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="px-3 py-2">Public Key</th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleIfaceSort('mtu')">
                                    MTU
                                    <span v-if="ifaceSortField === 'mtu'">{{ ifaceSortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="px-3 py-2">Comment</th>
                                <th v-if="can.manage" class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr v-if="paginatedIfaces.length === 0">
                                <td :colspan="can.manage ? 7 : 6" class="px-4 py-8 text-center font-sans text-xs text-zinc-500">
                                    Belum ada WireGuard interface yang dibuat.
                                </td>
                            </tr>
                            <tr
                                v-for="i in paginatedIfaces"
                                :key="i['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedIfaceId === i['.id'] ? 'bg-indigo-100/50 dark:bg-indigo-950/40' : '',
                                    i.disabled === 'true' ? 'opacity-60' : ''
                                ]"
                                @click="selectedIfaceId = i['.id']"
                                @dblclick="can.manage && openEditIface(i)"
                            >
                                <td class="px-2 py-2 text-center font-sans">
                                    <StatusDot :color="i.disabled === 'true' ? 'gray' : 'emerald'" />
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-bold text-zinc-900 dark:text-zinc-100" :class="{ 'line-through': i.disabled === 'true' }">
                                            {{ i.name }}
                                        </span>
                                        <button
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin nama interface"
                                            @click.stop="copyValue(i.name, 'Interface')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-semibold text-indigo-700 dark:text-indigo-400">
                                    {{ i['listen-port'] || '13231' }}
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-1.5">
                                        <span class="max-w-xs truncate text-zinc-600 dark:text-zinc-400" :title="i['public-key']">
                                            {{ i['public-key'] || '-' }}
                                        </span>
                                        <button
                                            v-if="i['public-key']"
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin Public Key"
                                            @click.stop="copyValue(i['public-key'], 'Public Key')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ i.mtu || '1420' }}</td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ i.comment || '-' }}</td>
                                <td v-if="can.manage" class="px-3 py-2 text-right" @click.stop>
                                    <div class="flex items-center justify-end gap-1 font-sans">
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700"
                                            :title="i.disabled === 'true' ? 'Aktifkan' : 'Nonaktifkan'"
                                            @click="toggleIface(i, i.disabled !== 'true')"
                                        >
                                            {{ i.disabled === 'true' ? '✓' : '✕' }}
                                        </button>
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700"
                                            title="Edit interface"
                                            @click="openEditIface(i)"
                                        >
                                            ✏
                                        </button>
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-rose-600 hover:bg-rose-100 dark:text-rose-400 dark:hover:bg-rose-950/50"
                                            title="Hapus interface"
                                            @click="removeSingleIface(i)"
                                        >
                                            🗑
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 2. Peers Table -->
                    <table v-else class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        :checked="isAllPeersSelected"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelectAllPeers"
                                    />
                                </th>
                                <th class="w-8 px-2 py-2 text-center">St</th>
                                <th class="cursor-pointer px-3 py-2" @click="togglePeerSort('interface')">
                                    Interface
                                    <span v-if="peerSortField === 'interface'">{{ peerSortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="px-3 py-2">Public Key</th>
                                <th class="cursor-pointer px-3 py-2" @click="togglePeerSort('allowed-address')">
                                    Allowed IPs
                                    <span v-if="peerSortField === 'allowed-address'">{{ peerSortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="togglePeerSort('endpoint-address')">
                                    Endpoint
                                    <span v-if="peerSortField === 'endpoint-address'">{{ peerSortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="togglePeerSort('latest-handshake')">
                                    Handshake
                                    <span v-if="peerSortField === 'latest-handshake'">{{ peerSortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="togglePeerSort('rx')">
                                    Traffic (RX / TX)
                                    <span v-if="peerSortField === 'rx'">{{ peerSortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="px-3 py-2">Comment</th>
                                <th v-if="can.manage" class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr v-if="paginatedPeers.length === 0">
                                <td :colspan="can.manage ? 10 : 9" class="px-4 py-8 text-center font-sans text-xs text-zinc-500">
                                    Tidak ada WireGuard peer yang cocok dengan kriteria filter.
                                </td>
                            </tr>
                            <tr
                                v-for="p in paginatedPeers"
                                :key="p['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedPeerIds.includes(p['.id']) ? 'bg-indigo-100/50 dark:bg-indigo-950/40' : '',
                                    p.disabled === 'true' ? 'opacity-60' : ''
                                ]"
                                @click="selectedPeerId = p['.id']"
                                @dblclick="can.manage && openEditPeer(p)"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="selectedPeerIds.includes(p['.id'])"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelectPeer(p['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2 text-center font-sans">
                                    <StatusDot :color="p.disabled === 'true' ? 'gray' : p['latest-handshake'] && p['latest-handshake'] !== '-' ? 'emerald' : 'amber'" />
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ p.interface }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-1.5">
                                        <span class="max-w-xs truncate text-zinc-500" :title="p['public-key']">{{ p['public-key'] }}</span>
                                        <button
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin Public Key"
                                            @click.stop="copyValue(p['public-key'], 'Public Key')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-semibold text-indigo-700 dark:text-indigo-400">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ p['allowed-address'] }}</span>
                                        <button
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin Allowed IPs"
                                            @click.stop="copyValue(p['allowed-address'], 'Allowed IPs')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">
                                    <div v-if="p['endpoint-address']" class="flex items-center gap-1.5">
                                        <span>{{ p['endpoint-address'] }}:{{ p['endpoint-port'] || '13231' }}</span>
                                        <button
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin Endpoint"
                                            @click.stop="copyValue(`${p['endpoint-address']}:${p['endpoint-port'] || '13231'}`, 'Endpoint')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                    <span v-else class="text-zinc-400">-</span>
                                </td>
                                <td class="px-3 py-2">
                                    <span
                                        v-if="p['latest-handshake'] && p['latest-handshake'] !== '-'"
                                        class="inline-flex rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
                                    >
                                        {{ p['latest-handshake'] }}
                                    </span>
                                    <span v-else class="text-zinc-400">-</span>
                                </td>
                                <td class="px-3 py-2 tabular-nums text-zinc-600 dark:text-zinc-400">
                                    ↓ {{ formatBytes(p.rx) }} / ↑ {{ formatBytes(p.tx) }}
                                </td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ p.comment || '-' }}</td>
                                <td v-if="can.manage" class="px-3 py-2 text-right" @click.stop>
                                    <div class="flex items-center justify-end gap-1 font-sans">
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700"
                                            :title="p.disabled === 'true' ? 'Aktifkan' : 'Nonaktifkan'"
                                            @click="togglePeer(p, p.disabled !== 'true')"
                                        >
                                            {{ p.disabled === 'true' ? '✓' : '✕' }}
                                        </button>
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700"
                                            title="Edit peer"
                                            @click="openEditPeer(p)"
                                        >
                                            ✏
                                        </button>
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-rose-600 hover:bg-rose-100 dark:text-rose-400 dark:hover:bg-rose-950/50"
                                            title="Hapus peer"
                                            @click="removeSinglePeer(p)"
                                        >
                                            🗑
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer with Pagination -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 bg-zinc-50/80 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-3">
                        <span>
                            Total: <strong>{{ activeTab === 'interfaces' ? sortedIfaces.length : sortedPeers.length }}</strong> item
                        </span>
                        <span v-if="activeTab === 'peers' && selectedPeerIds.length > 0" class="font-medium text-indigo-600 dark:text-indigo-400">
                            ({{ selectedPeerIds.length }} dipilih)
                        </span>
                    </div>

                    <!-- Pagination Controls -->
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1 text-[11px]">
                            <span>Baris:</span>
                            <select
                                v-model="perPage"
                                class="rounded border border-zinc-300 bg-white px-1.5 py-0.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                                @change="currentIfacePage = 1; currentPeerPage = 1"
                            >
                                <option :value="15">15</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="-1">Semua</option>
                            </select>
                        </div>

                        <div v-if="perPage > 0 && (activeTab === 'interfaces' ? totalIfacePages : totalPeerPages) > 1" class="flex items-center gap-1">
                            <button
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs disabled:opacity-40 dark:border-zinc-700"
                                :disabled="activeTab === 'interfaces' ? currentIfacePage <= 1 : currentPeerPage <= 1"
                                @click="activeTab === 'interfaces' ? currentIfacePage-- : currentPeerPage--"
                            >
                                ‹
                            </button>
                            <span class="text-[11px] font-medium text-zinc-700 dark:text-zinc-300">
                                {{ activeTab === 'interfaces' ? currentIfacePage : currentPeerPage }} /
                                {{ activeTab === 'interfaces' ? totalIfacePages : totalPeerPages }}
                            </span>
                            <button
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs disabled:opacity-40 dark:border-zinc-700"
                                :disabled="activeTab === 'interfaces' ? currentIfacePage >= totalIfacePages : currentPeerPage >= totalPeerPages"
                                @click="activeTab === 'interfaces' ? currentIfacePage++ : currentPeerPage++"
                            >
                                ›
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: Add Interface -->
            <Modal :show="showAddIface" title="Tambah WireGuard Interface" @close="showAddIface = false">
                <form class="space-y-3" @submit.prevent="saveIface">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Name</label>
                        <input
                            v-model="ifaceForm.name"
                            type="text"
                            required
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Listen Port</label>
                            <input
                                v-model.number="ifaceForm.listen_port"
                                type="number"
                                min="1"
                                max="65535"
                                required
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">MTU</label>
                            <input
                                v-model.number="ifaceForm.mtu"
                                type="number"
                                min="1280"
                                max="1500"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="ifaceForm.comment"
                            type="text"
                            placeholder="Catatan opsional"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <Btn type="button" size="sm" variant="secondary" @click="showAddIface = false">Batal</Btn>
                        <Btn type="submit" size="sm" variant="primary" :disabled="ifaceForm.processing">Simpan Interface</Btn>
                    </div>
                </form>
            </Modal>

            <!-- Modal: Edit Interface -->
            <Modal :show="showEditIface" title="Edit WireGuard Interface" @close="showEditIface = false">
                <form class="space-y-3" @submit.prevent="updateIface">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Name</label>
                        <input
                            v-model="ifaceForm.name"
                            type="text"
                            required
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Listen Port</label>
                            <input
                                v-model.number="ifaceForm.listen_port"
                                type="number"
                                min="1"
                                max="65535"
                                required
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">MTU</label>
                            <input
                                v-model.number="ifaceForm.mtu"
                                type="number"
                                min="1280"
                                max="1500"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="ifaceForm.comment"
                            type="text"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <Btn type="button" size="sm" variant="secondary" @click="showEditIface = false">Batal</Btn>
                        <Btn type="submit" size="sm" variant="primary" :disabled="ifaceForm.processing">Simpan Perubahan</Btn>
                    </div>
                </form>
            </Modal>

            <!-- Modal: Add Peer -->
            <Modal :show="showAddPeer" title="Tambah WireGuard Peer" @close="showAddPeer = false">
                <form class="space-y-3" @submit.prevent="savePeer">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">WireGuard Interface</label>
                        <select
                            v-model="peerForm.interface"
                            required
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        >
                            <option v-for="i in ifaceList" :key="i.name" :value="i.name">{{ i.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Peer Public Key</label>
                        <input
                            v-model="peerForm.public_key"
                            type="text"
                            required
                            placeholder="Base64 public key (44 karakter)"
                            class="mt-1 block w-full font-mono text-xs rounded-lg border border-zinc-300 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Allowed Addresses (Subnet yang diizinkan)</label>
                        <input
                            v-model="peerForm.allowed_address"
                            type="text"
                            required
                            placeholder="e.g. 10.10.0.2/32 atau 0.0.0.0/0"
                            class="mt-1 block w-full font-mono text-xs rounded-lg border border-zinc-300 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Endpoint Address (Opsional)</label>
                            <input
                                v-model="peerForm.endpoint_address"
                                type="text"
                                placeholder="e.g. 203.0.113.10 atau vpn.isp.net"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Endpoint Port</label>
                            <input
                                v-model.number="peerForm.endpoint_port"
                                type="number"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="peerForm.comment"
                            type="text"
                            placeholder="e.g. Kantor Cabang / Laptop Admin"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <Btn type="button" size="sm" variant="secondary" @click="showAddPeer = false">Batal</Btn>
                        <Btn type="submit" size="sm" variant="primary" :disabled="peerForm.processing">Simpan Peer</Btn>
                    </div>
                </form>
            </Modal>

            <!-- Modal: Edit Peer -->
            <Modal :show="showEditPeer" title="Edit WireGuard Peer" @close="showEditPeer = false">
                <form class="space-y-3" @submit.prevent="updatePeer">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">WireGuard Interface</label>
                        <select
                            v-model="peerForm.interface"
                            required
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        >
                            <option v-for="i in ifaceList" :key="i.name" :value="i.name">{{ i.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Peer Public Key</label>
                        <input
                            v-model="peerForm.public_key"
                            type="text"
                            required
                            class="mt-1 block w-full font-mono text-xs rounded-lg border border-zinc-300 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Allowed Addresses</label>
                        <input
                            v-model="peerForm.allowed_address"
                            type="text"
                            required
                            class="mt-1 block w-full font-mono text-xs rounded-lg border border-zinc-300 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Endpoint Address</label>
                            <input
                                v-model="peerForm.endpoint_address"
                                type="text"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Endpoint Port</label>
                            <input
                                v-model.number="peerForm.endpoint_port"
                                type="number"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="peerForm.comment"
                            type="text"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <Btn type="button" size="sm" variant="secondary" @click="showEditPeer = false">Batal</Btn>
                        <Btn type="submit" size="sm" variant="primary" :disabled="peerForm.processing">Simpan Perubahan</Btn>
                    </div>
                </form>
            </Modal>
        </div>
    </RouterWorkspace>
</template>
