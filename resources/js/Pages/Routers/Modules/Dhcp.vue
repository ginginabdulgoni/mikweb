<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface DhcpLease {
    '.id': string;
    address: string;
    'mac-address': string;
    'client-id'?: string;
    server?: string;
    'active-address'?: string;
    'active-mac-address'?: string;
    'host-name'?: string;
    status?: string;
    dynamic?: string;
    disabled?: string;
    comment?: string;
    'expires-after'?: string;
}

interface DhcpServer {
    '.id': string;
    name: string;
    interface: string;
    'lease-time'?: string;
    'address-pool'?: string;
    authoritative?: string;
    disabled?: string;
}

interface DhcpNetwork {
    '.id': string;
    address: string;
    gateway?: string;
    'dns-server'?: string;
    comment?: string;
}

interface IpPool {
    '.id': string;
    name: string;
    ranges: string;
    'next-pool'?: string;
}

const props = defineProps<{
    router: any;
    servers: DhcpServer[];
    leases: DhcpLease[];
    networks: DhcpNetwork[];
    pools: IpPool[];
    interfaces?: string[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

type DhcpTab = 'leases' | 'servers' | 'networks' | 'pools';
const urlParams = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
const initialTab = (urlParams.get('tab') as DhcpTab) || 'leases';
const activeTab = ref<DhcpTab>(['leases', 'servers', 'networks', 'pools'].includes(initialTab) ? initialTab : 'leases');

const search = ref('');
const statusFilter = ref<'all' | 'dynamic' | 'static' | 'bound' | 'disabled'>('all');
const serverFilter = ref('all');
const selectedId = ref<string | null>(null);

// ── Multi-selection for Leases Batch Operations ──────────────────────
const selectedLeaseIds = ref<string[]>([]);
const isProcessingBatch = ref(false);

// ── Modals ───────────────────────────────────────────────────────────
const showAddLease = ref(false);
const showEditLease = ref(false);
const editingLease = ref<DhcpLease | null>(null);
const showAddServer = ref(false);
const showAddNetwork = ref(false);
const showAddPool = ref(false);

const leaseForm = useForm({
    id: '',
    address: '',
    mac_address: '',
    server: 'all',
    comment: '',
});

const serverForm = useForm({
    name: 'dhcp1',
    interface: props.interfaces?.[0] ?? 'bridge1',
    address_pool: props.pools?.[0]?.name ?? '',
    lease_time: '12h',
});

const networkForm = useForm({
    address: '192.168.88.0/24',
    gateway: '192.168.88.1',
    dns_server: '192.168.88.1,8.8.8.8',
    comment: '',
});

const poolForm = useForm({
    name: 'pool-custom',
    ranges: '192.168.88.100-192.168.88.200',
    next_pool: '',
});

// ── Top KPI Statistics Summary ───────────────────────────────────────
const stats = computed(() => {
    const totalLeases = props.leases.length;
    const boundCount = props.leases.filter((l) => (l.status || 'bound') === 'bound' && l.disabled !== 'true').length;
    const staticCount = props.leases.filter((l) => l.dynamic !== 'true').length;
    const dynamicCount = props.leases.filter((l) => l.dynamic === 'true').length;
    const disabledCount = props.leases.filter((l) => l.disabled === 'true').length;
    const waitingCount = props.leases.filter((l) => l.status && l.status !== 'bound' && l.disabled !== 'true').length;
    const serversCount = props.servers.filter((s) => s.disabled !== 'true').length;
    const poolsCount = props.pools.length;

    return {
        totalLeases,
        boundCount,
        staticCount,
        dynamicCount,
        disabledCount,
        waitingCount,
        serversCount,
        poolsCount,
    };
});

// ── Filtered data ────────────────────────────────────────────────────
const filteredLeases = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.leases.filter((l) => {
        const isDyn = l.dynamic === 'true';
        const isDis = l.disabled === 'true';
        const isBnd = (l.status || 'bound') === 'bound';

        if (statusFilter.value === 'dynamic' && !isDyn) return false;
        if (statusFilter.value === 'static' && isDyn) return false;
        if (statusFilter.value === 'bound' && (!isBnd || isDis)) return false;
        if (statusFilter.value === 'disabled' && !isDis) return false;
        if (serverFilter.value !== 'all' && (l.server || 'all') !== serverFilter.value) return false;

        if (!q) return true;
        return (
            l.address.toLowerCase().includes(q) ||
            l['mac-address'].toLowerCase().includes(q) ||
            (l['host-name'] ?? '').toLowerCase().includes(q) ||
            (l.comment ?? '').toLowerCase().includes(q) ||
            (l.server ?? '').toLowerCase().includes(q)
        );
    });
});

// ── Sorting & Pagination for Leases ──────────────────────────────────
type LeaseSortField = 'address' | 'mac-address' | 'host-name' | 'server' | 'status' | 'dynamic';
const leaseSortField = ref<LeaseSortField>('address');
const leaseSortAsc = ref(true);
const leasePerPage = ref<number>(25);
const leasePage = ref(1);

function sortLeasesBy(field: LeaseSortField) {
    if (leaseSortField.value === field) {
        leaseSortAsc.value = !leaseSortAsc.value;
    } else {
        leaseSortField.value = field;
        leaseSortAsc.value = true;
    }
}

const sortedLeases = computed(() => {
    const list = [...filteredLeases.value];
    list.sort((a, b) => {
        const valA = (a[leaseSortField.value] ?? '').toString().toLowerCase();
        const valB = (b[leaseSortField.value] ?? '').toString().toLowerCase();

        if (leaseSortField.value === 'address') {
            const numA = (a.address || '').split('.').reduce((acc, octet) => (acc << 8) + parseInt(octet || '0', 10), 0);
            const numB = (b.address || '').split('.').reduce((acc, octet) => (acc << 8) + parseInt(octet || '0', 10), 0);
            return leaseSortAsc.value ? numA - numB : numB - numA;
        }

        if (valA < valB) return leaseSortAsc.value ? -1 : 1;
        if (valA > valB) return leaseSortAsc.value ? 1 : -1;
        return 0;
    });
    return list;
});

const totalLeasePages = computed(() => {
    if (leasePerPage.value === 0) return 1;
    return Math.ceil(sortedLeases.value.length / leasePerPage.value) || 1;
});

const paginatedLeases = computed(() => {
    if (leasePerPage.value === 0) return sortedLeases.value;
    const start = (leasePage.value - 1) * leasePerPage.value;
    return sortedLeases.value.slice(start, start + leasePerPage.value);
});

watch([search, statusFilter, serverFilter, leasePerPage], () => {
    leasePage.value = 1;
});

// Checkbox selection states
const isAllLeasesSelected = computed(() => {
    if (paginatedLeases.value.length === 0) return false;
    return paginatedLeases.value.every((l) => selectedLeaseIds.value.includes(l['.id']));
});

function toggleSelectAllLeases() {
    if (isAllLeasesSelected.value) {
        const currentIds = new Set(paginatedLeases.value.map((l) => l['.id']));
        selectedLeaseIds.value = selectedLeaseIds.value.filter((id) => !currentIds.has(id));
    } else {
        const newIds = new Set([...selectedLeaseIds.value, ...paginatedLeases.value.map((l) => l['.id'])]);
        selectedLeaseIds.value = Array.from(newIds);
    }
}

function toggleSelectLease(id: string) {
    const idx = selectedLeaseIds.value.indexOf(id);
    if (idx >= 0) {
        selectedLeaseIds.value.splice(idx, 1);
    } else {
        selectedLeaseIds.value.push(id);
    }
}

// ── Batch Operations ─────────────────────────────────────────────────
function batchLeasesAction(action: 'make_static' | 'enable' | 'disable' | 'destroy') {
    if (selectedLeaseIds.value.length === 0) return;

    if (action === 'destroy' && !confirm(`Hapus ${selectedLeaseIds.value.length} DHCP lease terpilih?`)) {
        return;
    }

    isProcessingBatch.value = true;
    inertia.post(
        route('routers.ip.dhcp.leases.batch', props.router.id),
        { action, ids: selectedLeaseIds.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isProcessingBatch.value = false;
                selectedLeaseIds.value = [];
                selectedId.value = null;
            },
        },
    );
}

// ── Other Tabs Data ──────────────────────────────────────────────────
const filteredServers = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.servers.filter((s) => {
        if (!q) return true;
        return s.name.toLowerCase().includes(q) || s.interface.toLowerCase().includes(q);
    });
});

const filteredNetworks = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.networks.filter((n) => {
        if (!q) return true;
        return n.address.toLowerCase().includes(q) || (n.gateway ?? '').toLowerCase().includes(q) || (n.comment ?? '').toLowerCase().includes(q);
    });
});

const filteredPools = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.pools.filter((p) => {
        if (!q) return true;
        return p.name.toLowerCase().includes(q) || p.ranges.toLowerCase().includes(q);
    });
});

const selectedItem = computed(() => {
    if (!selectedId.value) return null;
    if (activeTab.value === 'leases') return props.leases.find((l) => l['.id'] === selectedId.value) ?? null;
    if (activeTab.value === 'servers') return props.servers.find((s) => s['.id'] === selectedId.value) ?? null;
    if (activeTab.value === 'networks') return props.networks.find((n) => n['.id'] === selectedId.value) ?? null;
    if (activeTab.value === 'pools') return props.pools.find((p) => p['.id'] === selectedId.value) ?? null;
    return null;
});

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
function downloadCsv(headers: string[], rows: (string | number)[][], filename: string) {
    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportLeasesCsv() {
    const headers = ['IP Address', 'MAC Address', 'Host Name', 'Server', 'Status', 'Type', 'Disabled', 'Comment'];
    const rows = sortedLeases.value.map((l) => [
        `"${l.address}"`,
        `"${l['mac-address']}"`,
        `"${(l['host-name'] || '').replace(/"/g, '""')}"`,
        `"${l.server || 'all'}"`,
        `"${l.status || 'bound'}"`,
        `"${l.dynamic === 'true' ? 'Dynamic' : 'Static'}"`,
        `"${l.disabled === 'true' ? 'yes' : 'no'}"`,
        `"${(l.comment || '').replace(/"/g, '""')}"`,
    ]);
    downloadCsv(headers, rows, `dhcp-leases-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
}

function exportServersCsv() {
    const headers = ['Name', 'Interface', 'Address Pool', 'Lease Time', 'Authoritative', 'Disabled'];
    const rows = filteredServers.value.map((s) => [
        `"${s.name}"`,
        `"${s.interface}"`,
        `"${s['address-pool'] || '-'}"`,
        `"${s['lease-time'] || '12h'}"`,
        `"${s.authoritative || 'yes'}"`,
        `"${s.disabled === 'true' ? 'yes' : 'no'}"`,
    ]);
    downloadCsv(headers, rows, `dhcp-servers-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
}

function exportNetworksCsv() {
    const headers = ['Address', 'Gateway', 'DNS Server', 'Comment'];
    const rows = filteredNetworks.value.map((n) => [
        `"${n.address}"`,
        `"${n.gateway || '-'}"`,
        `"${n['dns-server'] || '-'}"`,
        `"${(n.comment || '').replace(/"/g, '""')}"`,
    ]);
    downloadCsv(headers, rows, `dhcp-networks-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
}

function exportPoolsCsv() {
    const headers = ['Name', 'Ranges', 'Next Pool'];
    const rows = filteredPools.value.map((p) => [
        `"${p.name}"`,
        `"${p.ranges}"`,
        `"${p['next-pool'] || '-'}"`,
    ]);
    downloadCsv(headers, rows, `ip-pools-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
}

// ── Actions ──────────────────────────────────────────────────────────
function refreshData() {
    inertia.reload({ preserveScroll: true });
}

function openAddModal() {
    if (activeTab.value === 'leases') {
        leaseForm.reset();
        showAddLease.value = true;
    } else if (activeTab.value === 'servers') {
        serverForm.reset();
        showAddServer.value = true;
    } else if (activeTab.value === 'networks') {
        networkForm.reset();
        showAddNetwork.value = true;
    } else if (activeTab.value === 'pools') {
        poolForm.reset();
        showAddPool.value = true;
    }
}

function openEditLease(item: DhcpLease) {
    editingLease.value = item;
    leaseForm.id = item['.id'];
    leaseForm.address = item.address;
    leaseForm.mac_address = item['mac-address'];
    leaseForm.server = item.server || 'all';
    leaseForm.comment = item.comment || '';
    showEditLease.value = true;
}

function saveNewLease() {
    leaseForm.post(route('routers.ip.dhcp.leases.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddLease.value = false;
            leaseForm.reset();
        },
    });
}

function updateExistingLease() {
    leaseForm.put(route('routers.ip.dhcp.leases.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditLease.value = false;
        },
    });
}

function makeStatic(item: DhcpLease) {
    inertia.post(
        route('routers.ip.dhcp.leases.make-static', props.router.id),
        { id: item['.id'] },
        { preserveScroll: true },
    );
}

function toggleLease(item: DhcpLease, disable: boolean) {
    inertia.post(
        route('routers.ip.dhcp.leases.toggle', props.router.id),
        { id: item['.id'], disable },
        { preserveScroll: true },
    );
}

function toggleServer(item: DhcpServer, disable: boolean) {
    inertia.post(
        route('routers.ip.dhcp.servers.toggle', props.router.id),
        { id: item['.id'], disable },
        { preserveScroll: true },
    );
}

function removeSelected() {
    if (!selectedItem.value) return;
    const id = selectedItem.value['.id'];

    if (!confirm(`Remove item?`)) return;

    if (activeTab.value === 'leases') {
        inertia.delete(route('routers.ip.dhcp.leases.destroy', props.router.id), {
            data: { id },
            preserveScroll: true,
            onSuccess: () => { selectedId.value = null; },
        });
    } else if (activeTab.value === 'servers') {
        inertia.delete(route('routers.ip.dhcp.servers.destroy', props.router.id), {
            data: { id },
            preserveScroll: true,
            onSuccess: () => { selectedId.value = null; },
        });
    } else if (activeTab.value === 'networks') {
        inertia.delete(route('routers.ip.dhcp.networks.destroy', props.router.id), {
            data: { id },
            preserveScroll: true,
            onSuccess: () => { selectedId.value = null; },
        });
    } else if (activeTab.value === 'pools') {
        inertia.delete(route('routers.ip.dhcp.pools.destroy', props.router.id), {
            data: { id },
            preserveScroll: true,
            onSuccess: () => { selectedId.value = null; },
        });
    }
}

function saveNewServer() {
    serverForm.post(route('routers.ip.dhcp.servers.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddServer.value = false;
            serverForm.reset();
        },
    });
}

function saveNewNetwork() {
    networkForm.post(route('routers.ip.dhcp.networks.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddNetwork.value = false;
            networkForm.reset();
        },
    });
}

function saveNewPool() {
    poolForm.post(route('routers.ip.dhcp.pools.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddPool.value = false;
            poolForm.reset();
        },
    });
}
</script>

<template>
    <Head :title="`DHCP Server — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- ── Top KPI Statistics Summary Grid ────────────────────────── -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Leases -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Total Leases</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.totalLeases }}</span>
                        <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">Leases</span>
                    </div>
                </div>

                <!-- Bound / Active -->
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3.5 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">
                        <StatusDot color="emerald" :pulse="true" />
                        <span>Aktif (Bound)</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-emerald-800 dark:text-emerald-200">{{ stats.boundCount }}</span>
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400">Connected</span>
                    </div>
                </div>

                <!-- Static Leases -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-zinc-500">
                        <span class="inline-block h-2 w-2 rounded-full bg-purple-500"></span>
                        <span>Static Leases</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-purple-700 dark:text-purple-400">{{ stats.staticCount }}</span>
                        <span class="rounded bg-purple-50 px-1.5 py-0.5 text-[10px] font-medium text-purple-700 dark:bg-purple-950 dark:text-purple-300">Reserved</span>
                    </div>
                </div>

                <!-- Dynamic Leases -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-zinc-500">
                        <span class="inline-block h-2 w-2 rounded-full bg-sky-500"></span>
                        <span>Dynamic Leases</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-sky-700 dark:text-sky-400">{{ stats.dynamicCount }}</span>
                        <span class="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-950 dark:text-sky-300">Auto Pool</span>
                    </div>
                </div>

                <!-- Waiting / Disabled -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-zinc-500">
                        <StatusDot color="zinc" />
                        <span>Waiting / Disabled</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-500 dark:text-zinc-400">{{ stats.waitingCount + stats.disabledCount }}</span>
                        <span class="text-[10px] text-zinc-400">{{ stats.disabledCount }} Dis</span>
                    </div>
                </div>

                <!-- DHCP Servers & Pools -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Servers & Pools</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-lg font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.serversCount }} Srv / {{ stats.poolsCount }} Pool</span>
                        <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Active</span>
                    </div>
                </div>
            </div>

            <!-- ── Main WinBox Window Card ────────────────────────────────── -->
            <div class="flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- WinBox Header with Tabs -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold text-indigo-600 dark:text-indigo-400">🌐</span>
                            <h2 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">DHCP Server & Leases</h2>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /ip/dhcp-server
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                                <StatusDot color="emerald" :pulse="true" />
                                {{ stats.boundCount }} Bound
                            </span>
                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ leases.length }} Total Leases
                            </span>
                        </div>
                    </div>

                    <div class="flex gap-1 overflow-x-auto text-xs">
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'leases'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'leases'; selectedId = null"
                        >
                            Leases ({{ leases.length }})
                        </button>
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'servers'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'servers'; selectedId = null"
                        >
                            DHCP Servers ({{ servers.length }})
                        </button>
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'networks'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'networks'; selectedId = null"
                        >
                            Networks ({{ networks.length }})
                        </button>
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'pools'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'pools'; selectedId = null"
                        >
                            IP Pools ({{ pools.length }})
                        </button>
                    </div>
                </div>

                <!-- WinBox Action Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage" size="sm" variant="primary" class="gap-1.5" @click="openAddModal">
                            <span>＋</span> Add
                        </Btn>

                        <!-- Make Static action for dynamic lease -->
                        <template v-if="can.manage && activeTab === 'leases' && selectedItem">
                            <Btn
                                v-if="selectedItem.dynamic === 'true'"
                                size="sm"
                                variant="secondary"
                                class="bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/40 dark:text-indigo-300"
                                title="Convert dynamic lease to permanent static reservation"
                                @click="makeStatic(selectedItem)"
                            >
                                📌 Make Static
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedItem.disabled === 'false'"
                                @click="toggleLease(selectedItem, false)"
                            >
                                ✓ Enable
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedItem.disabled === 'true'"
                                @click="toggleLease(selectedItem, true)"
                            >
                                ✕ Disable
                            </Btn>
                            <Btn size="sm" variant="secondary" @click="openEditLease(selectedItem)">
                                ✏ Edit
                            </Btn>
                        </template>

                        <template v-if="can.manage && activeTab === 'servers' && selectedItem">
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedItem.disabled === 'false'"
                                @click="toggleServer(selectedItem, false)"
                            >
                                ✓ Enable
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedItem.disabled === 'true'"
                                @click="toggleServer(selectedItem, true)"
                            >
                                ✕ Disable
                            </Btn>
                        </template>

                        <Btn
                            v-if="can.manage"
                            size="sm"
                            variant="secondary"
                            class="text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/30"
                            :disabled="!selectedItem"
                            @click="removeSelected"
                        >
                            🗑 Remove
                        </Btn>

                        <div class="mx-1 h-4 w-px bg-zinc-300 dark:bg-zinc-700"></div>

                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh
                        </Btn>

                        <!-- CSV Export Button -->
                        <Btn
                            size="sm"
                            variant="ghost"
                            class="gap-1.5 text-zinc-600 dark:text-zinc-300"
                            title="Export data ke format CSV"
                            @click="activeTab === 'leases' ? exportLeasesCsv() : activeTab === 'servers' ? exportServersCsv() : activeTab === 'networks' ? exportNetworksCsv() : exportPoolsCsv()"
                        >
                            <span>📥</span> Export CSV
                        </Btn>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <template v-if="activeTab === 'leases'">
                            <select
                                v-model="statusFilter"
                                class="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <option value="all">Semua Status</option>
                                <option value="bound">Bound (Aktif)</option>
                                <option value="dynamic">Dynamic Only (D)</option>
                                <option value="static">Static Only (S)</option>
                                <option value="disabled">Disabled (X)</option>
                            </select>

                            <select
                                v-if="servers.length > 0"
                                v-model="serverFilter"
                                class="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <option value="all">Semua Server</option>
                                <option v-for="s in servers" :key="s['.id']" :value="s.name">{{ s.name }}</option>
                            </select>
                        </template>

                        <div class="relative">
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Cari IP, MAC, host..."
                                class="w-48 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 text-xs focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                            />
                            <span v-if="search" class="absolute right-2 top-1 cursor-pointer text-xs text-zinc-400 hover:text-zinc-600" @click="search = ''">✕</span>
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
                        v-if="can.manage && activeTab === 'leases' && selectedLeaseIds.length > 0"
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/95 px-4 py-2 backdrop-blur-xs dark:border-indigo-900/60 dark:bg-indigo-950/80"
                    >
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white dark:bg-indigo-500">
                                {{ selectedLeaseIds.length }}
                            </span>
                            <span class="text-xs font-semibold text-indigo-950 dark:text-indigo-100">
                                Lease dipilih
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-indigo-700 shadow-xs hover:bg-indigo-50 dark:bg-zinc-800 dark:text-indigo-300 dark:hover:bg-zinc-700"
                                title="Jadikan static permanent reservation"
                                @click="batchLeasesAction('make_static')"
                            >
                                📌 Make Static
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-emerald-700 shadow-xs hover:bg-emerald-50 dark:bg-zinc-800 dark:text-emerald-300 dark:hover:bg-zinc-700"
                                @click="batchLeasesAction('enable')"
                            >
                                ✓ Enable
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-amber-700 shadow-xs hover:bg-amber-50 dark:bg-zinc-800 dark:text-amber-300 dark:hover:bg-zinc-700"
                                @click="batchLeasesAction('disable')"
                            >
                                ✕ Disable
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-rose-700 shadow-xs hover:bg-rose-50 dark:bg-zinc-800 dark:text-rose-300 dark:hover:bg-zinc-700"
                                @click="batchLeasesAction('destroy')"
                            >
                                🗑 Hapus
                            </button>
                            <button
                                type="button"
                                class="text-xs text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
                                @click="selectedLeaseIds = []"
                            >
                                Batal
                            </button>
                        </div>
                    </div>
                </transition>

                <!-- Table View -->
                <div class="flex-1 overflow-auto">
                    <!-- 1. Leases Tab -->
                    <table v-if="activeTab === 'leases'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th v-if="can.manage" class="w-8 px-3 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        :checked="isAllLeasesSelected"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelectAllLeases"
                                    />
                                </th>
                                <th class="w-12 px-3 py-2 text-center">Flags</th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortLeasesBy('address')">
                                    <div class="flex items-center gap-1">
                                        <span>IP Address</span>
                                        <span v-if="leaseSortField === 'address'">{{ leaseSortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortLeasesBy('mac-address')">
                                    <div class="flex items-center gap-1">
                                        <span>MAC Address</span>
                                        <span v-if="leaseSortField === 'mac-address'">{{ leaseSortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortLeasesBy('host-name')">
                                    <div class="flex items-center gap-1">
                                        <span>Host Name</span>
                                        <span v-if="leaseSortField === 'host-name'">{{ leaseSortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortLeasesBy('server')">
                                    <div class="flex items-center gap-1">
                                        <span>Server</span>
                                        <span v-if="leaseSortField === 'server'">{{ leaseSortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortLeasesBy('status')">
                                    <div class="flex items-center gap-1">
                                        <span>Status</span>
                                        <span v-if="leaseSortField === 'status'">{{ leaseSortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="px-3 py-2">Comment</th>
                                <th class="px-3 py-2 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="l in paginatedLeases"
                                :key="l['.id']"
                                class="group cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedId === l['.id'] ? 'bg-indigo-100/60 dark:bg-indigo-950/40' : '',
                                    selectedLeaseIds.includes(l['.id']) ? 'bg-indigo-50/80 dark:bg-indigo-950/50' : '',
                                    l.disabled === 'true' ? 'opacity-50 line-through' : ''
                                ]"
                                @click="selectedId = l['.id']"
                                @dblclick="can.manage && openEditLease(l)"
                            >
                                <td v-if="can.manage" class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="selectedLeaseIds.includes(l['.id'])"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelectLease(l['.id'])"
                                    />
                                </td>
                                <td class="px-3 py-2 text-center font-sans font-bold">
                                    <span v-if="l.dynamic === 'true'" class="text-indigo-600 dark:text-indigo-400" title="Dynamic (D)">D </span>
                                    <span v-if="l.disabled === 'true'" class="text-rose-500" title="Disabled (X)">X </span>
                                    <span v-if="l.dynamic !== 'true' && l.disabled !== 'true'" class="text-emerald-500" title="Static (S)">S </span>
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ l.address }}</span>
                                        <button
                                            type="button"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] text-zinc-400 hover:text-indigo-600 transition-opacity"
                                            title="Copy IP"
                                            @click.stop="copyText(l.address, 'ip-' + l['.id'])"
                                        >
                                            {{ copiedKey === 'ip-' + l['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ l['mac-address'] }}</span>
                                        <button
                                            type="button"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] text-zinc-400 hover:text-indigo-600 transition-opacity"
                                            title="Copy MAC"
                                            @click.stop="copyText(l['mac-address'], 'mac-' + l['.id'])"
                                        >
                                            {{ copiedKey === 'mac-' + l['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-sans text-zinc-800 dark:text-zinc-200">{{ l['host-name'] || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-500">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        {{ l.server || 'all' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-sans capitalize">
                                    <span
                                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-medium"
                                        :class="(l.status || 'bound') === 'bound' && l.disabled !== 'true'
                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                            : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'"
                                    >
                                        <StatusDot :color="(l.status || 'bound') === 'bound' && l.disabled !== 'true' ? 'emerald' : 'zinc'" />
                                        {{ l.status || 'bound' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ l.comment || '-' }}</td>
                                <td class="px-3 py-2 text-right" @click.stop>
                                    <div class="flex items-center justify-end gap-1 font-sans">
                                        <button
                                            v-if="can.manage && l.dynamic === 'true'"
                                            type="button"
                                            class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/60 dark:text-indigo-300"
                                            title="Make Permanent Static Lease"
                                            @click="makeStatic(l)"
                                        >
                                            Make Static
                                        </button>
                                        <button
                                            v-if="can.manage"
                                            type="button"
                                            class="rounded px-1.5 py-0.5 text-[10px] font-medium transition-colors"
                                            :class="l.disabled === 'true'
                                                ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300'
                                                : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300'"
                                            @click="toggleLease(l, l.disabled !== 'true')"
                                        >
                                            {{ l.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </button>
                                        <button
                                            v-if="can.manage"
                                            type="button"
                                            class="rounded px-1.5 py-0.5 text-[10px] text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800"
                                            title="Edit lease"
                                            @click="openEditLease(l)"
                                        >
                                            ✏
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="filteredLeases.length === 0">
                                <td colspan="9" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No DHCP leases found matching filter criteria.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 2. DHCP Servers Tab -->
                    <table v-else-if="activeTab === 'servers'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">#</th>
                                <th class="px-3 py-2">Name</th>
                                <th class="px-3 py-2">Interface</th>
                                <th class="px-3 py-2">Address Pool</th>
                                <th class="px-3 py-2">Lease Time</th>
                                <th class="px-3 py-2">Authoritative</th>
                                <th class="px-3 py-2 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="s in filteredServers"
                                :key="s['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedId === s['.id'] ? 'bg-indigo-100/60 dark:bg-indigo-950/40' : '',
                                    s.disabled === 'true' ? 'opacity-50 line-through' : ''
                                ]"
                                @click="selectedId = s['.id']"
                            >
                                <td class="px-3 py-2 text-center font-sans">
                                    <StatusDot :color="s.disabled === 'true' ? 'zinc' : 'emerald'" />
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ s.name }}</td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ s.interface }}</td>
                                <td class="px-3 py-2 font-semibold text-indigo-700 dark:text-indigo-400">{{ s['address-pool'] || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ s['lease-time'] || '12h' }}</td>
                                <td class="px-3 py-2 font-sans capitalize text-zinc-500">{{ s.authoritative || 'yes' }}</td>
                                <td class="px-3 py-2 text-right" @click.stop>
                                    <button
                                        v-if="can.manage"
                                        type="button"
                                        class="rounded px-2 py-0.5 font-sans text-[10px] font-medium"
                                        :class="s.disabled === 'true' ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-600'"
                                        @click="toggleServer(s, s.disabled !== 'true')"
                                    >
                                        {{ s.disabled === 'true' ? 'Enable' : 'Disable' }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="filteredServers.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No DHCP servers configured.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 3. DHCP Networks Tab -->
                    <table v-else-if="activeTab === 'networks'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="px-3 py-2">Address</th>
                                <th class="px-3 py-2">Gateway</th>
                                <th class="px-3 py-2">DNS Server</th>
                                <th class="px-3 py-2">Comment</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="n in filteredNetworks"
                                :key="n['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="selectedId === n['.id'] ? 'bg-indigo-100/60 dark:bg-indigo-950/40' : ''"
                                @click="selectedId = n['.id']"
                            >
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ n.address }}</td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ n.gateway || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ n['dns-server'] || '-' }}</td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ n.comment || '-' }}</td>
                            </tr>
                            <tr v-if="filteredNetworks.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No DHCP networks defined.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 4. IP Pools Tab -->
                    <table v-else-if="activeTab === 'pools'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="px-3 py-2">Name</th>
                                <th class="px-3 py-2">Ranges</th>
                                <th class="px-3 py-2">Next Pool</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="p in filteredPools"
                                :key="p['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="selectedId === p['.id'] ? 'bg-indigo-100/60 dark:bg-indigo-950/40' : ''"
                                @click="selectedId = p['.id']"
                            >
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ p.name }}</td>
                                <td class="px-3 py-2 text-indigo-700 dark:text-indigo-400">{{ p.ranges }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ p['next-pool'] || 'none' }}</td>
                            </tr>
                            <tr v-if="filteredPools.length === 0">
                                <td colspan="3" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No IP pools defined.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── Pagination & WinBox Status Footer ──────────────────────── -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50/80 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-4">
                        <span>
                            Total: <strong>{{ activeTab === 'leases' ? filteredLeases.length : activeTab === 'servers' ? filteredServers.length : activeTab === 'networks' ? filteredNetworks.length : filteredPools.length }}</strong> items
                        </span>
                        <span v-if="selectedItem" class="font-mono text-indigo-600 dark:text-indigo-400">
                            Selected: {{ selectedItem.address || selectedItem.name }} ({{ selectedItem['.id'] }})
                        </span>
                    </div>

                    <!-- Pagination Controls (Only on Leases tab) -->
                    <div v-if="activeTab === 'leases'" class="flex items-center gap-2">
                        <div class="flex items-center gap-1.5 text-xs text-zinc-500">
                            <span>Per halaman:</span>
                            <select
                                v-model.number="leasePerPage"
                                class="rounded border border-zinc-300 bg-white px-2 py-0.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option :value="15">15</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="0">Semua</option>
                            </select>
                        </div>

                        <div v-if="leasePerPage > 0" class="flex items-center gap-1">
                            <button
                                type="button"
                                :disabled="leasePage <= 1"
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-zinc-100 disabled:opacity-40 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                @click="leasePage--"
                            >
                                ‹ Prev
                            </button>
                            <span class="px-1 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                {{ leasePage }} / {{ totalLeasePages }}
                            </span>
                            <button
                                type="button"
                                :disabled="leasePage >= totalLeasePages"
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-zinc-100 disabled:opacity-40 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                @click="leasePage++"
                            >
                                Next ›
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: Add Static Lease -->
        <Modal :show="showAddLease" title="New Static DHCP Lease" @close="showAddLease = false">
            <form class="space-y-3" @submit.prevent="saveNewLease">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">IP Address</label>
                    <input
                        v-model="leaseForm.address"
                        type="text"
                        required
                        placeholder="e.g. 192.168.88.50"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">MAC Address</label>
                    <input
                        v-model="leaseForm.mac_address"
                        type="text"
                        required
                        placeholder="e.g. AA:BB:CC:DD:EE:FF"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Server</label>
                    <select
                        v-model="leaseForm.server"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="all">all</option>
                        <option v-for="s in servers" :key="s['.id']" :value="s.name">{{ s.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="leaseForm.comment"
                        type="text"
                        placeholder="Optional note / device name"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showAddLease = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="leaseForm.processing">Add Static Lease</Btn>
                </div>
            </form>
        </Modal>

        <!-- Modal: Edit Lease -->
        <Modal :show="showEditLease" title="Edit DHCP Lease" @close="showEditLease = false">
            <form class="space-y-3" @submit.prevent="updateExistingLease">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">IP Address</label>
                    <input
                        v-model="leaseForm.address"
                        type="text"
                        required
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">MAC Address</label>
                    <input
                        v-model="leaseForm.mac_address"
                        type="text"
                        required
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Server</label>
                    <select
                        v-model="leaseForm.server"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="all">all</option>
                        <option v-for="s in servers" :key="s['.id']" :value="s.name">{{ s.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="leaseForm.comment"
                        type="text"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showEditLease = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="leaseForm.processing">Save Changes</Btn>
                </div>
            </form>
        </Modal>

        <!-- Modal: Add DHCP Server -->
        <Modal :show="showAddServer" title="New DHCP Server" @close="showAddServer = false">
            <form class="space-y-3" @submit.prevent="saveNewServer">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Server Name</label>
                    <input
                        v-model="serverForm.name"
                        type="text"
                        required
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Interface</label>
                    <select
                        v-model="serverForm.interface"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option v-for="iface in interfaces" :key="iface" :value="iface">{{ iface }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Address Pool</label>
                    <select
                        v-model="serverForm.address_pool"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="">(none)</option>
                        <option v-for="p in pools" :key="p['.id']" :value="p.name">{{ p.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Lease Time</label>
                    <input
                        v-model="serverForm.lease_time"
                        type="text"
                        placeholder="12h, 1d"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showAddServer = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="serverForm.processing">Add Server</Btn>
                </div>
            </form>
        </Modal>

        <!-- Modal: Add Network -->
        <Modal :show="showAddNetwork" title="New DHCP Network" @close="showAddNetwork = false">
            <form class="space-y-3" @submit.prevent="saveNewNetwork">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Network Address (CIDR)</label>
                    <input
                        v-model="networkForm.address"
                        type="text"
                        required
                        placeholder="192.168.88.0/24"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Gateway</label>
                    <input
                        v-model="networkForm.gateway"
                        type="text"
                        placeholder="192.168.88.1"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">DNS Servers (comma separated)</label>
                    <input
                        v-model="networkForm.dns_server"
                        type="text"
                        placeholder="192.168.88.1, 8.8.8.8"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="networkForm.comment"
                        type="text"
                        placeholder="LAN Subnet"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showAddNetwork = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="networkForm.processing">Add Network</Btn>
                </div>
            </form>
        </Modal>

        <!-- Modal: Add Pool -->
        <Modal :show="showAddPool" title="New IP Pool" @close="showAddPool = false">
            <form class="space-y-3" @submit.prevent="saveNewPool">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Pool Name</label>
                    <input
                        v-model="poolForm.name"
                        type="text"
                        required
                        placeholder="e.g. dhcp-pool-lan"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Address Ranges</label>
                    <input
                        v-model="poolForm.ranges"
                        type="text"
                        required
                        placeholder="e.g. 192.168.88.10-192.168.88.254"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Next Pool (optional)</label>
                    <input
                        v-model="poolForm.next_pool"
                        type="text"
                        placeholder="none"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showAddPool = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="poolForm.processing">Add IP Pool</Btn>
                </div>
            </form>
        </Modal>
    </RouterWorkspace>
</template>
