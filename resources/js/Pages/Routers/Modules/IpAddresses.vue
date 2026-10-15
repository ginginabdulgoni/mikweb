<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';

interface Addr {
    '.id': string;
    address: string;
    network?: string;
    interface: string;
    disabled?: string;
    dynamic?: string;
    comment?: string;
}

const props = defineProps<{
    router: any;
    addresses: Addr[];
    interfaces: string[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

// Selection & Batch
const selectedIds = ref<string[]>([]);
const isBatchOperating = ref(false);

// Filter & Search
const searchQuery = ref('');
const interfaceFilter = ref('all');
const typeFilter = ref<'all' | 'static' | 'dynamic' | 'disabled'>('all');

// Sort & Pagination
const sortColumn = ref<string>('address');
const sortDirection = ref<'asc' | 'desc'>('asc');
const currentPage = ref(1);
const perPage = ref<number | 'all'>(25);

// Modal state
const showModal = ref(false);
const editing = ref<Addr | null>(null);
const form = useForm({ id: '', address: '', interface: '', comment: '' });

// Copied tooltip feedback state
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
    const list = props.addresses || [];
    const total = list.length;
    const disabledCount = list.filter((a) => a.disabled === 'true').length;
    const activeCount = total - disabledCount;
    const dynamicCount = list.filter((a) => a.dynamic === 'true').length;
    const staticCount = list.filter((a) => a.dynamic !== 'true').length;
    const uniqueNetworks = new Set(list.map((a) => a.network).filter(Boolean)).size;

    return {
        total,
        active: activeCount,
        disabled: disabledCount,
        dynamic: dynamicCount,
        static: staticCount,
        networks: uniqueNetworks,
    };
});

// Helper for numeric IP octet sorting
function ipToNumber(ip: string): number {
    const cleanIp = ip.split('/')[0] || '';
    const parts = cleanIp.split('.').map((p) => parseInt(p, 10));
    if (parts.length === 4 && parts.every((p) => !isNaN(p))) {
        return (parts[0]! << 24) + (parts[1]! << 16) + (parts[2]! << 8) + parts[3]!;
    }
    return 0;
}

// Filtered & Sorted
const filteredAddresses = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return (props.addresses || []).filter((a) => {
        if (interfaceFilter.value !== 'all' && a.interface !== interfaceFilter.value) return false;
        if (typeFilter.value === 'static' && a.dynamic === 'true') return false;
        if (typeFilter.value === 'dynamic' && a.dynamic !== 'true') return false;
        if (typeFilter.value === 'disabled' && a.disabled !== 'true') return false;

        if (!q) return true;
        return (
            a.address.toLowerCase().includes(q) ||
            (a.network ?? '').toLowerCase().includes(q) ||
            a.interface.toLowerCase().includes(q) ||
            (a.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedAddresses = computed(() => {
    const list = [...filteredAddresses.value];
    const col = sortColumn.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (col === 'address') {
            const numA = ipToNumber(a.address);
            const numB = ipToNumber(b.address);
            if (numA !== 0 && numB !== 0) return (numA - numB) * dir;
            return a.address.localeCompare(b.address) * dir;
        }
        if (col === 'network') {
            return (a.network ?? '').localeCompare(b.network ?? '') * dir;
        }
        if (col === 'interface') {
            return a.interface.localeCompare(b.interface) * dir;
        }
        if (col === 'disabled') {
            const valA = a.disabled === 'true' ? 1 : 0;
            const valB = b.disabled === 'true' ? 1 : 0;
            return (valA - valB) * dir;
        }
        if (col === 'comment') {
            return (a.comment ?? '').localeCompare(b.comment ?? '') * dir;
        }
        return 0;
    });
});

// Pagination
const totalPages = computed(() => {
    if (perPage.value === 'all') return 1;
    return Math.max(1, Math.ceil(sortedAddresses.value.length / perPage.value));
});

const paginatedAddresses = computed(() => {
    if (perPage.value === 'all') return sortedAddresses.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedAddresses.value.slice(start, start + perPage.value);
});

// Reset page on search/filter changes
watch([searchQuery, interfaceFilter, typeFilter, perPage], () => {
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
    const pageItems = paginatedAddresses.value;
    if (pageItems.length === 0) return false;
    return pageItems.every((item) => selectedIds.value.includes(item['.id']));
});

function toggleSelectAllPage() {
    const pageIds = paginatedAddresses.value.map((i) => i['.id']);
    if (isAllPageSelected.value) {
        selectedIds.value = selectedIds.value.filter((id) => !pageIds.includes(id));
    } else {
        const set = new Set([...selectedIds.value, ...pageIds]);
        selectedIds.value = Array.from(set);
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
function runBatch(action: 'enable' | 'disable' | 'destroy') {
    if (selectedIds.value.length === 0) return;
    const actionLabel = action === 'destroy' ? 'menghapus' : action === 'enable' ? 'mengaktifkan' : 'menonaktifkan';
    if (!confirm(`Apakah Anda yakin ingin ${actionLabel} ${selectedIds.value.length} IP address terpilih?`)) {
        return;
    }

    isBatchOperating.value = true;
    inertia.post(
        route('routers.ip.addresses.batch', props.router.id),
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
function openCreate() {
    editing.value = null;
    form.reset();
    form.interface = props.interfaces[0] ?? '';
    showModal.value = true;
}

function openEdit(a: Addr) {
    editing.value = a;
    form.id = a['.id'];
    form.address = a.address;
    form.interface = a.interface;
    form.comment = a.comment ?? '';
    showModal.value = true;
}

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    if (editing.value) form.put(route('routers.ip.addresses.update', props.router.id), opts);
    else form.post(route('routers.ip.addresses.store', props.router.id), opts);
}

function toggle(a: Addr) {
    inertia.post(
        route('routers.ip.addresses.toggle', props.router.id),
        { id: a['.id'], disable: a.disabled !== 'true' },
        { preserveScroll: true },
    );
}

function remove(a: Addr) {
    if (confirm(`Remove IP address ${a.address}?`)) {
        inertia.delete(route('routers.ip.addresses.destroy', props.router.id), {
            data: { id: a['.id'] },
            preserveScroll: true,
            onSuccess: () => {
                selectedIds.value = selectedIds.value.filter((id) => id !== a['.id']);
            },
        });
    }
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// CSV Export
function exportCsv() {
    const rows = sortedAddresses.value.map((a, idx) => ({
        '#': idx + 1,
        Address: a.address,
        Network: a.network ?? '',
        Interface: a.interface,
        Type: a.dynamic === 'true' ? 'Dynamic' : 'Static',
        Status: a.disabled === 'true' ? 'Disabled' : 'Enabled',
        Comment: a.comment ?? '',
    }));

    if (rows.length === 0) return;
    const headers = Object.keys(rows[0]!).join(',');
    const csvContent = [
        headers,
        ...rows.map((r) =>
            Object.values(r)
                .map((v) => `"${String(v).replace(/"/g, '""')}"`)
                .join(','),
        ),
    ].join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `ip-addresses-${props.router.name || 'router'}-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
}
</script>

<template>
    <Head title="IP Addresses" />
    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- 6 KPI Stat Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Addresses -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total IP</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        {{ stats.total }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Semua interface</span>
                </div>

                <!-- Active / Enabled -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Active</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                        {{ stats.active }}
                    </p>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Siap routing</span>
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

                <!-- Dynamic / DHCP -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Dynamic</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                        {{ stats.dynamic }}
                    </p>
                    <span class="text-[11px] text-indigo-600 dark:text-indigo-400">DHCP / Auto</span>
                </div>

                <!-- Static -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Static</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-amber-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
                        {{ stats.static }}
                    </p>
                    <span class="text-[11px] text-amber-600 dark:text-amber-400">Manual Config</span>
                </div>

                <!-- Subnets -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Networks</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-violet-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-violet-600 dark:text-violet-400">
                        {{ stats.networks }}
                    </p>
                    <span class="text-[11px] text-violet-600 dark:text-violet-400">Subnet terdaftar</span>
                </div>
            </div>

            <!-- Main Card -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Window Header -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-bold text-indigo-600 dark:text-indigo-400">◎</span>
                        <h2 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">IP Address List</h2>
                        <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ sortedAddresses.length }} of {{ addresses.length }}
                        </span>
                    </div>

                    <!-- Quick Type Filter Pills -->
                    <div class="flex items-center gap-1.5 text-xs">
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="typeFilter === 'all' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700'"
                            @click="typeFilter = 'all'"
                        >
                            Semua
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="typeFilter === 'static' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700'"
                            @click="typeFilter = 'static'"
                        >
                            Static
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="typeFilter === 'dynamic' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700'"
                            @click="typeFilter = 'dynamic'"
                        >
                            Dynamic
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="typeFilter === 'disabled' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700'"
                            @click="typeFilter = 'disabled'"
                        >
                            Disabled
                        </button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage" size="sm" variant="primary" @click="openCreate">+ Add IP Address</Btn>
                        <Btn size="sm" variant="ghost" title="Refresh data" @click="refreshData">↻ Refresh</Btn>
                        <Btn size="sm" variant="ghost" title="Export CSV" @click="exportCsv">⤓ Export CSV</Btn>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <!-- Interface filter -->
                        <select
                            v-model="interfaceFilter"
                            class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        >
                            <option value="all">Semua Interface</option>
                            <option v-for="i in interfaces" :key="i" :value="i">{{ i }}</option>
                        </select>

                        <!-- Search Input -->
                        <div class="relative">
                            <input
                                v-model="searchQuery"
                                type="search"
                                placeholder="Cari IP, subnet, comment..."
                                class="w-48 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 pl-8 text-xs placeholder-zinc-400 transition-all focus:w-64 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                            <span class="pointer-events-none absolute left-2.5 top-2 text-xs text-zinc-400">⌕</span>
                        </div>
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
                            {{ selectedIds.length }} IP address dipilih
                        </span>
                    </div>

                    <div v-if="can.manage" class="flex flex-wrap items-center gap-1.5">
                        <Btn size="sm" :disabled="isBatchOperating" @click="runBatch('enable')">
                            ✓ Enable
                        </Btn>
                        <Btn size="sm" :disabled="isBatchOperating" @click="runBatch('disable')">
                            ✕ Disable
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
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">
                                    <input
                                        type="checkbox"
                                        class="rounded-sm border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        :checked="isAllPageSelected"
                                        @change="toggleSelectAllPage"
                                    />
                                </th>
                                <th class="w-10 px-2 py-2.5 text-center">#</th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('address')">
                                    <div class="flex items-center gap-1">
                                        <span>Address</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'address' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('network')">
                                    <div class="flex items-center gap-1">
                                        <span>Network</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'network' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('interface')">
                                    <div class="flex items-center gap-1">
                                        <span>Interface</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'interface' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('disabled')">
                                    <div class="flex items-center gap-1">
                                        <span>Flags</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'disabled' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('comment')">
                                    <div class="flex items-center gap-1">
                                        <span>Comment</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'comment' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="paginatedAddresses.length === 0">
                                <td colspan="8" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada IP address yang cocok dengan kriteria pencarian.
                                </td>
                            </tr>
                            <tr
                                v-for="(a, idx) in paginatedAddresses"
                                :key="a['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="[
                                    selectedIds.includes(a['.id']) && 'bg-indigo-50/70 dark:bg-indigo-950/40',
                                    a.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="toggleSelectItem(a['.id'])"
                                @dblclick="openEdit(a)"
                            >
                                <td class="px-3 py-2.5 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded-sm border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        :checked="selectedIds.includes(a['.id'])"
                                        @change="toggleSelectItem(a['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2.5 text-center font-mono text-[11px] text-zinc-400">
                                    {{ (currentPage - 1) * (perPage === 'all' ? 0 : perPage) + idx + 1 }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-1.5 font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                                        <span>{{ a.address }}</span>
                                        <button
                                            type="button"
                                            title="Copy IP Address"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(a.address, 'ip')"
                                        >
                                            <span v-if="copiedField === `ip:${a.address}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ a.network ?? '—' }}</span>
                                        <button
                                            v-if="a.network"
                                            type="button"
                                            title="Copy Network Subnet"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(a.network, 'net')"
                                        >
                                            <span v-if="copiedField === `net:${a.network}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 font-medium text-zinc-700 dark:text-zinc-300">
                                    <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs font-mono dark:bg-zinc-800">
                                        {{ a.interface }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-1">
                                        <span v-if="a.disabled === 'true'" class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                            disabled
                                        </span>
                                        <span v-else-if="a.dynamic === 'true'" class="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-600 dark:bg-blue-950/50 dark:text-blue-300">
                                            dynamic
                                        </span>
                                        <span v-else class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300">
                                            static
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500 italic">
                                    {{ a.comment || '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end items-center gap-1">
                                        <Btn
                                            size="sm"
                                            :variant="a.disabled === 'true' ? 'primary' : 'ghost'"
                                            :title="a.disabled === 'true' ? 'Aktifkan IP' : 'Non-aktifkan IP'"
                                            @click="toggle(a)"
                                        >
                                            {{ a.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" title="Edit IP" @click="openEdit(a)">
                                            Edit
                                        </Btn>
                                        <Btn
                                            v-if="a.dynamic !== 'true'"
                                            size="sm"
                                            variant="danger"
                                            title="Hapus IP Address"
                                            @click="remove(a)"
                                        >
                                            Del
                                        </Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Pagination & Range Bar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-4 py-2.5 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="flex items-center gap-2">
                        <span>Menampilkan <strong>{{ sortedAddresses.length === 0 ? 0 : (currentPage - 1) * (perPage === 'all' ? sortedAddresses.length : perPage) + 1 }}</strong> - <strong>{{ perPage === 'all' ? sortedAddresses.length : Math.min(currentPage * perPage, sortedAddresses.length) }}</strong> dari <strong>{{ sortedAddresses.length }}</strong> IP address</span>
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

            <!-- Modal Add / Edit -->
            <Modal :open="showModal" :title="editing ? `Edit IP Address: ${editing.address}` : 'Add IP Address'" @close="showModal = false">
                <form class="space-y-4 text-xs" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Address (CIDR format)</label>
                        <input
                            v-model="form.address"
                            placeholder="192.168.88.1/24"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                            required
                        />
                        <p v-if="form.errors.address" class="mt-1 text-red-500">{{ form.errors.address }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Interface</label>
                        <select
                            v-model="form.interface"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                            required
                        >
                            <option v-for="i in interfaces" :key="i" :value="i">{{ i }}</option>
                        </select>
                        <p v-if="form.errors.interface" class="mt-1 text-red-500">{{ form.errors.interface }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="form.comment"
                            placeholder="e.g. LAN Gateway"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        <Btn variant="ghost" @click="showModal = false">Cancel</Btn>
                        <Btn type="submit" variant="primary" :disabled="form.processing">
                            {{ editing ? 'Save Changes' : 'Add Address' }}
                        </Btn>
                    </div>
                </form>
            </Modal>
        </div>
    </RouterWorkspace>
</template>
