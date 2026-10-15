<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface DhcpClientEntry {
    '.id': string;
    interface: string;
    status?: string;
    address?: string;
    gateway?: string;
    'dhcp-server'?: string;
    'primary-dns'?: string;
    'expires-after'?: string;
    'use-peer-dns'?: string;
    'use-peer-ntp'?: string;
    'add-default-route'?: string;
    'default-route-distance'?: string;
    disabled?: string;
    comment?: string;
}

const props = defineProps<{
    router: any;
    clients: DhcpClientEntry[];
    interfaces?: string[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

// Selection & Batch
const selectedIds = ref<string[]>([]);
const isBatchOperating = ref(false);

// Filter & Search
const search = ref('');
const statusFilter = ref<'all' | 'bound' | 'searching' | 'disabled'>('all');

// Sort & Pagination
const sortColumn = ref<string>('interface');
const sortDirection = ref<'asc' | 'desc'>('asc');
const currentPage = ref(1);
const perPage = ref<number | 'all'>(25);

// Modals
const showAddModal = ref(false);
const showEditModal = ref(false);

const form = useForm({
    id: '',
    interface: props.interfaces?.[0] ?? 'ether1',
    use_peer_dns: 'yes',
    use_peer_ntp: 'yes',
    add_default_route: 'yes',
    default_route_distance: 1,
    comment: '',
});

// Copied feedback
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

// KPI Statistics
const stats = computed(() => {
    const list = props.clients || [];
    const total = list.length;
    const disabledCount = list.filter((c) => c.disabled === 'true').length;
    const boundCount = list.filter((c) => c.status === 'bound' && c.disabled !== 'true').length;
    const searchingCount = list.filter((c) => c.status === 'searching' && c.disabled !== 'true').length;
    const defaultRouteCount = list.filter((c) => c['add-default-route'] === 'yes').length;
    const peerDnsCount = list.filter((c) => c['use-peer-dns'] === 'yes').length;

    return {
        total,
        bound: boundCount,
        searching: searchingCount,
        disabled: disabledCount,
        defaultRoute: defaultRouteCount,
        peerDns: peerDnsCount,
    };
});

// Filtered & Sorted
const filteredClients = computed(() => {
    const q = search.value.trim().toLowerCase();
    return (props.clients || []).filter((item) => {
        const isDisabled = item.disabled === 'true';
        const st = (item.status ?? '').toLowerCase();

        if (statusFilter.value === 'disabled' && !isDisabled) return false;
        if (statusFilter.value === 'bound' && st !== 'bound') return false;
        if (statusFilter.value === 'searching' && st !== 'searching') return false;

        if (!q) return true;
        return (
            item.interface.toLowerCase().includes(q) ||
            (item.address ?? '').toLowerCase().includes(q) ||
            (item.gateway ?? '').toLowerCase().includes(q) ||
            (item.status ?? '').toLowerCase().includes(q) ||
            (item.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedClients = computed(() => {
    const list = [...filteredClients.value];
    const col = sortColumn.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (col === 'interface') return a.interface.localeCompare(b.interface) * dir;
        if (col === 'status') return (a.status ?? '').localeCompare(b.status ?? '') * dir;
        if (col === 'address') return (a.address ?? '').localeCompare(b.address ?? '') * dir;
        if (col === 'gateway') return (a.gateway ?? '').localeCompare(b.gateway ?? '') * dir;
        if (col === 'server') return (a['dhcp-server'] ?? '').localeCompare(b['dhcp-server'] ?? '') * dir;
        if (col === 'comment') return (a.comment ?? '').localeCompare(b.comment ?? '') * dir;
        return 0;
    });
});

// Pagination
const totalPages = computed(() => {
    if (perPage.value === 'all') return 1;
    return Math.max(1, Math.ceil(sortedClients.value.length / perPage.value));
});

const paginatedClients = computed(() => {
    if (perPage.value === 'all') return sortedClients.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedClients.value.slice(start, start + perPage.value);
});

watch([search, statusFilter, perPage], () => {
    currentPage.value = 1;
});

function toggleSort(col: string) {
    if (sortColumn.value === col) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortColumn.value = col;
        sortDirection.value = 'asc';
    }
}

// Selection helpers
const isAllPageSelected = computed(() => {
    const pageItems = paginatedClients.value;
    if (pageItems.length === 0) return false;
    return pageItems.every((item) => selectedIds.value.includes(item['.id']));
});

function toggleSelectAllPage() {
    const pageIds = paginatedClients.value.map((i) => i['.id']);
    if (isAllPageSelected.value) {
        selectedIds.value = selectedIds.value.filter((id) => !pageIds.includes(id));
    } else {
        selectedIds.value = Array.from(new Set([...selectedIds.value, ...pageIds]));
    }
}

function toggleSelectItem(id: string) {
    const idx = selectedIds.value.indexOf(id);
    if (idx >= 0) selectedIds.value.splice(idx, 1);
    else selectedIds.value.push(id);
}

function clearSelection() {
    selectedIds.value = [];
}

// Batch Actions
function runBatch(action: 'enable' | 'disable' | 'release' | 'renew' | 'destroy') {
    if (selectedIds.value.length === 0) return;
    const labels: Record<string, string> = {
        enable: 'mengaktifkan',
        disable: 'menonaktifkan',
        release: 'me-release lease untuk',
        renew: 'me-renew lease untuk',
        destroy: 'menghapus',
    };
    if (!confirm(`Apakah Anda yakin ingin ${labels[action]} ${selectedIds.value.length} DHCP client terpilih?`)) {
        return;
    }

    isBatchOperating.value = true;
    inertia.post(
        route('routers.ip.dhcp-client.batch', props.router.id),
        { action, ids: selectedIds.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isBatchOperating.value = false;
                selectedIds.value = [];
            },
        },
    );
}

// Single Item Actions
function openAdd() {
    form.reset();
    form.id = '';
    form.interface = props.interfaces?.[0] ?? 'ether1';
    form.use_peer_dns = 'yes';
    form.use_peer_ntp = 'yes';
    form.add_default_route = 'yes';
    form.default_route_distance = 1;
    showAddModal.value = true;
}

function openEdit(client: DhcpClientEntry) {
    form.id = client['.id'];
    form.interface = client.interface;
    form.use_peer_dns = client['use-peer-dns'] ?? 'yes';
    form.use_peer_ntp = client['use-peer-ntp'] ?? 'yes';
    form.add_default_route = client['add-default-route'] ?? 'yes';
    form.default_route_distance = client['default-route-distance'] ? parseInt(client['default-route-distance'], 10) : 1;
    form.comment = client.comment || '';
    showEditModal.value = true;
}

function saveClient() {
    form.post(route('routers.ip.dhcp-client.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddModal.value = false;
            form.reset();
        },
    });
}

function updateClient() {
    form.put(route('routers.ip.dhcp-client.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            form.reset();
        },
    });
}

function toggleClient(client: DhcpClientEntry, disable: boolean) {
    inertia.post(
        route('routers.ip.dhcp-client.toggle', props.router.id),
        { id: client['.id'], disable },
        { preserveScroll: true },
    );
}

function renewClient(client: DhcpClientEntry) {
    inertia.post(
        route('routers.ip.dhcp-client.renew', props.router.id),
        { id: client['.id'] },
        { preserveScroll: true },
    );
}

function releaseClient(client: DhcpClientEntry) {
    inertia.post(
        route('routers.ip.dhcp-client.release', props.router.id),
        { id: client['.id'] },
        { preserveScroll: true },
    );
}

function removeClient(client: DhcpClientEntry) {
    if (!confirm(`Remove DHCP client on interface ${client.interface}?`)) return;

    inertia.delete(route('routers.ip.dhcp-client.destroy', props.router.id), {
        data: { id: client['.id'] },
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = selectedIds.value.filter((id) => id !== client['.id']);
        },
    });
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// CSV Export
function exportCsv() {
    const rows = sortedClients.value.map((c, idx) => ({
        '#': idx + 1,
        Interface: c.interface,
        Status: c.disabled === 'true' ? 'Disabled' : (c.status || 'stopped'),
        'IP Address': c.address ?? '',
        Gateway: c.gateway ?? '',
        'DHCP Server': c['dhcp-server'] ?? '',
        'Primary DNS': c['primary-dns'] ?? '',
        'Expires After': c['expires-after'] ?? '',
        'Default Route': c['add-default-route'] ?? 'no',
        Comment: c.comment ?? '',
    }));

    if (rows.length === 0) return;
    const headers = Object.keys(rows[0]!).join(',');
    const csvContent = [headers, ...rows.map((r) => Object.values(r).map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))].join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `dhcp-clients-${props.router.name || 'router'}-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
}
</script>

<template>
    <Head :title="`DHCP Client — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- 6 KPI Stat Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Clients -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total Clients</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        {{ stats.total }}
                    </p>
                    <span class="text-[11px] text-zinc-500">WAN Interfaces</span>
                </div>

                <!-- Bound -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Bound</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                        {{ stats.bound }}
                    </p>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Lease didapatkan</span>
                </div>

                <!-- Searching -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Searching</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-amber-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
                        {{ stats.searching }}
                    </p>
                    <span class="text-[11px] text-amber-600 dark:text-amber-400">Mencari server</span>
                </div>

                <!-- Disabled -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Disabled</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-zinc-400"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-600 dark:text-zinc-300">
                        {{ stats.disabled }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Non-aktif</span>
                </div>

                <!-- Default Route -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Default Route</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                        {{ stats.defaultRoute }}
                    </p>
                    <span class="text-[11px] text-indigo-600 dark:text-indigo-400">0.0.0.0/0 auto</span>
                </div>

                <!-- Peer DNS -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Peer DNS</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-cyan-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-cyan-600 dark:text-cyan-400">
                        {{ stats.peerDns }}
                    </p>
                    <span class="text-[11px] text-cyan-600 dark:text-cyan-400">Auto DNS</span>
                </div>
            </div>

            <!-- Main Card -->
            <div class="flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">DHCP Client List</span>
                        <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            /ip/dhcp-client
                        </span>
                        <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ sortedClients.length }} of {{ clients.length }}
                        </span>
                    </div>

                    <!-- Filter buttons -->
                    <div class="flex items-center rounded-lg border border-zinc-200 bg-white p-0.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                        <button
                            type="button"
                            class="rounded px-2.5 py-1 transition-colors"
                            :class="statusFilter === 'all' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="statusFilter = 'all'"
                        >
                            All
                        </button>
                        <button
                            type="button"
                            class="rounded px-2.5 py-1 transition-colors"
                            :class="statusFilter === 'bound' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="statusFilter = 'bound'"
                        >
                            Bound ({{ stats.bound }})
                        </button>
                        <button
                            type="button"
                            class="rounded px-2.5 py-1 transition-colors"
                            :class="statusFilter === 'searching' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="statusFilter = 'searching'"
                        >
                            Searching ({{ stats.searching }})
                        </button>
                        <button
                            type="button"
                            class="rounded px-2.5 py-1 transition-colors"
                            :class="statusFilter === 'disabled' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="statusFilter = 'disabled'"
                        >
                            Disabled ({{ stats.disabled }})
                        </button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage" size="sm" variant="primary" @click="openAdd">+ Add Client</Btn>
                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">↻ Refresh</Btn>
                        <Btn size="sm" variant="ghost" title="Export CSV" @click="exportCsv">⤓ Export CSV</Btn>
                    </div>

                    <div class="relative">
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Cari interface, IP, status..."
                            class="w-48 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 pl-8 text-xs placeholder-zinc-400 transition-all focus:w-64 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                        <span class="pointer-events-none absolute left-2.5 top-1.5 text-xs text-zinc-400">⌕</span>
                    </div>
                </div>

                <!-- Floating Sticky Batch Actions Bar -->
                <div
                    v-if="selectedIds.length > 0"
                    class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/95 px-4 py-2.5 backdrop-blur-xs dark:border-indigo-900/60 dark:bg-indigo-950/90"
                >
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-bold text-white">
                            {{ selectedIds.length }}
                        </span>
                        <span class="text-xs font-semibold text-indigo-950 dark:text-indigo-200">
                            {{ selectedIds.length }} client dipilih
                        </span>
                    </div>

                    <div v-if="can.manage" class="flex flex-wrap items-center gap-1.5">
                        <Btn size="sm" :disabled="isBatchOperating" @click="runBatch('enable')">
                            ✓ Enable
                        </Btn>
                        <Btn size="sm" :disabled="isBatchOperating" @click="runBatch('disable')">
                            ✕ Disable
                        </Btn>
                        <Btn size="sm" :disabled="isBatchOperating" @click="runBatch('renew')">
                            ↻ Renew
                        </Btn>
                        <Btn size="sm" :disabled="isBatchOperating" @click="runBatch('release')">
                            ⇥ Release
                        </Btn>
                        <Btn size="sm" variant="danger" :disabled="isBatchOperating" @click="runBatch('destroy')">
                            🗑 Delete
                        </Btn>
                        <button
                            type="button"
                            class="ml-2 text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
                            @click="clearSelection"
                        >
                            Batal
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left font-mono text-xs">
                        <thead class="sticky top-0 bg-zinc-100 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        class="rounded-sm border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        :checked="isAllPageSelected"
                                        @change="toggleSelectAllPage"
                                    />
                                </th>
                                <th class="w-8 px-2 py-2 text-center font-sans">#</th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('interface')">
                                    <div class="flex items-center gap-1">
                                        <span>Interface</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'interface' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('status')">
                                    <div class="flex items-center gap-1">
                                        <span>Status</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'status' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('address')">
                                    <div class="flex items-center gap-1">
                                        <span>IP Address</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'address' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('gateway')">
                                    <div class="flex items-center gap-1">
                                        <span>Gateway</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'gateway' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('server')">
                                    <div class="flex items-center gap-1">
                                        <span>DHCP Server</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'server' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="px-3 py-2">Expires</th>
                                <th class="px-3 py-2">Default Route</th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('comment')">
                                    <div class="flex items-center gap-1">
                                        <span>Comment</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'comment' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="px-3 py-2 text-right font-sans">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-900">
                            <tr
                                v-for="(client, idx) in paginatedClients"
                                :key="client['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/30"
                                :class="[
                                    selectedIds.includes(client['.id']) && 'bg-indigo-100/70 font-semibold text-indigo-900 dark:bg-indigo-950/70 dark:text-indigo-200',
                                    client.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="toggleSelectItem(client['.id'])"
                                @dblclick="can.manage && openEdit(client)"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded-sm border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        :checked="selectedIds.includes(client['.id'])"
                                        @change="toggleSelectItem(client['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2 text-center font-sans text-zinc-400">
                                    {{ (currentPage - 1) * (perPage === 'all' ? 0 : perPage) + idx + 1 }}
                                </td>
                                <td class="px-3 py-2 font-bold text-zinc-800 dark:text-zinc-200">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ client.interface }}</span>
                                        <button
                                            type="button"
                                            title="Copy Interface"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(client.interface, 'iface')"
                                        >
                                            <span v-if="copiedField === `iface:${client.interface}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-sans">
                                    <span
                                        class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium"
                                        :class="client.disabled === 'true'
                                            ? 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800'
                                            : client.status === 'bound'
                                                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                                : 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400'"
                                    >
                                        <StatusDot :color="client.disabled === 'true' ? 'zinc' : client.status === 'bound' ? 'emerald' : 'amber'" />
                                        {{ client.disabled === 'true' ? 'disabled' : (client.status || 'stopped') }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-semibold text-indigo-600 dark:text-indigo-400">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ client.address || '-' }}</span>
                                        <button
                                            v-if="client.address"
                                            type="button"
                                            title="Copy IP"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(client.address, 'ip')"
                                        >
                                            <span v-if="copiedField === `ip:${client.address}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ client.gateway || '-' }}</span>
                                        <button
                                            v-if="client.gateway"
                                            type="button"
                                            title="Copy Gateway"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(client.gateway, 'gw')"
                                        >
                                            <span v-if="copiedField === `gw:${client.gateway}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-500">{{ client['dhcp-server'] || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ client['expires-after'] || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">
                                    <span v-if="client['add-default-route'] === 'yes'" class="text-emerald-600">yes (d={{ client['default-route-distance'] || '1' }})</span>
                                    <span v-else class="text-zinc-400">no</span>
                                </td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ client.comment || '-' }}</td>
                                <td class="px-3 py-2 text-right font-sans" @click.stop>
                                    <div v-if="can.manage" class="flex items-center justify-end gap-1">
                                        <Btn
                                            size="sm"
                                            :variant="client.disabled === 'true' ? 'primary' : 'ghost'"
                                            @click="toggleClient(client, client.disabled !== 'true')"
                                        >
                                            {{ client.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" title="Renew" @click="renewClient(client)">
                                            Renew
                                        </Btn>
                                        <Btn size="sm" variant="ghost" title="Edit" @click="openEdit(client)">
                                            Edit
                                        </Btn>
                                        <Btn size="sm" variant="danger" title="Remove" @click="removeClient(client)">
                                            Del
                                        </Btn>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="paginatedClients.length === 0">
                                <td colspan="11" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No DHCP client instances found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Pagination & Range Bar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-4">
                        <span>
                            Menampilkan <strong>{{ sortedClients.length === 0 ? 0 : (currentPage - 1) * (perPage === 'all' ? sortedClients.length : perPage) + 1 }}</strong> - <strong>{{ perPage === 'all' ? sortedClients.length : Math.min(currentPage * perPage, sortedClients.length) }}</strong> dari <strong>{{ sortedClients.length }}</strong> client
                        </span>
                        <span v-if="selectedIds.length > 0" class="text-indigo-600 dark:text-indigo-400 font-semibold">
                            ({{ selectedIds.length }} terpilih)
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

            <!-- Add Modal -->
            <Modal :show="showAddModal" title="New DHCP Client" @close="showAddModal = false">
                <form class="space-y-3" @submit.prevent="saveClient">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Interface</label>
                        <select
                            v-model="form.interface"
                            required
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        >
                            <option v-for="iface in (interfaces ?? ['ether1'])" :key="iface" :value="iface">{{ iface }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Use Peer DNS</label>
                            <select
                                v-model="form.use_peer_dns"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            >
                                <option value="yes">yes</option>
                                <option value="no">no</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Use Peer NTP</label>
                            <select
                                v-model="form.use_peer_ntp"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            >
                                <option value="yes">yes</option>
                                <option value="no">no</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Add Default Route</label>
                            <select
                                v-model="form.add_default_route"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            >
                                <option value="yes">yes</option>
                                <option value="no">no</option>
                                <option value="special-class">special-class</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Default Route Distance</label>
                            <input
                                v-model.number="form.default_route_distance"
                                type="number"
                                min="1"
                                max="255"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="form.comment"
                            type="text"
                            placeholder="e.g. ISP Uplink"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <Btn type="button" size="sm" variant="secondary" @click="showAddModal = false">Cancel</Btn>
                        <Btn type="submit" size="sm" variant="primary" :disabled="form.processing">Create Client</Btn>
                    </div>
                </form>
            </Modal>

            <!-- Edit Modal -->
            <Modal :show="showEditModal" title="Edit DHCP Client" @close="showEditModal = false">
                <form class="space-y-3" @submit.prevent="updateClient">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Interface</label>
                        <select
                            v-model="form.interface"
                            required
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        >
                            <option v-for="iface in (interfaces ?? ['ether1'])" :key="iface" :value="iface">{{ iface }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Use Peer DNS</label>
                            <select
                                v-model="form.use_peer_dns"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            >
                                <option value="yes">yes</option>
                                <option value="no">no</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Use Peer NTP</label>
                            <select
                                v-model="form.use_peer_ntp"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            >
                                <option value="yes">yes</option>
                                <option value="no">no</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Add Default Route</label>
                            <select
                                v-model="form.add_default_route"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            >
                                <option value="yes">yes</option>
                                <option value="no">no</option>
                                <option value="special-class">special-class</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Default Route Distance</label>
                            <input
                                v-model.number="form.default_route_distance"
                                type="number"
                                min="1"
                                max="255"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="form.comment"
                            type="text"
                            placeholder="e.g. ISP Uplink"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <Btn type="button" size="sm" variant="secondary" @click="showEditModal = false">Cancel</Btn>
                        <Btn type="submit" size="sm" variant="primary" :disabled="form.processing">Save Changes</Btn>
                    </div>
                </form>
            </Modal>
        </div>
    </RouterWorkspace>
</template>
