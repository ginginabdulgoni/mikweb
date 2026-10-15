<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';

interface IpPool {
    '.id': string;
    name: string;
    ranges: string;
    'next-pool'?: string;
    comment?: string;
}

interface UsedAddress {
    '.id'?: string;
    pool?: string;
    address?: string;
    owner?: string;
    info?: string;
}

const props = defineProps<{
    router: any;
    pools: IpPool[];
    used: UsedAddress[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

const activeTab = ref<'pools' | 'used'>('pools');
const search = ref('');

// Selection & Batch
const selectedPoolIds = ref<string[]>([]);
const isBatchOperating = ref(false);

// Sorting & Pagination
const poolSortColumn = ref<string>('name');
const poolSortDir = ref<'asc' | 'desc'>('asc');
const usedSortColumn = ref<string>('pool');
const usedSortDir = ref<'asc' | 'desc'>('asc');

const currentPage = ref(1);
const perPage = ref<number | 'all'>(25);

// Modal states
const showAddModal = ref(false);
const showEditModal = ref(false);

const form = useForm({
    id: '',
    name: '',
    ranges: '',
    next_pool: 'none',
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
    const pList = props.pools || [];
    const uList = props.used || [];

    const totalPools = pList.length;
    const totalAllocated = uList.length;
    const chainedPools = pList.filter((p) => p['next-pool'] && p['next-pool'] !== 'none').length;
    const dhcpAllocated = uList.filter((u) => (u.owner ?? '').toLowerCase().includes('dhcp')).length;
    const pppAllocated = uList.filter((u) => (u.owner ?? '').toLowerCase().includes('ppp')).length;
    const activeAllocated = totalAllocated - dhcpAllocated - pppAllocated;

    return {
        totalPools,
        totalAllocated,
        chainedPools,
        dhcpAllocated,
        pppAllocated,
        otherAllocated: Math.max(0, activeAllocated),
    };
});

// Filtered & Sorted Pools
const filteredPools = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = props.pools || [];
    if (!q) return list;
    return list.filter(
        (p) =>
            p.name.toLowerCase().includes(q) ||
            p.ranges.toLowerCase().includes(q) ||
            (p['next-pool'] ?? '').toLowerCase().includes(q) ||
            (p.comment ?? '').toLowerCase().includes(q),
    );
});

const sortedPools = computed(() => {
    const list = [...filteredPools.value];
    const col = poolSortColumn.value;
    const dir = poolSortDir.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (col === 'name') return a.name.localeCompare(b.name) * dir;
        if (col === 'ranges') return a.ranges.localeCompare(b.ranges) * dir;
        if (col === 'next-pool') return (a['next-pool'] ?? '').localeCompare(b['next-pool'] ?? '') * dir;
        if (col === 'comment') return (a.comment ?? '').localeCompare(b.comment ?? '') * dir;
        return 0;
    });
});

// Filtered & Sorted Used
const filteredUsed = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = props.used || [];
    if (!q) return list;
    return list.filter(
        (u) =>
            (u.pool ?? '').toLowerCase().includes(q) ||
            (u.address ?? '').toLowerCase().includes(q) ||
            (u.owner ?? '').toLowerCase().includes(q) ||
            (u.info ?? '').toLowerCase().includes(q),
    );
});

const sortedUsed = computed(() => {
    const list = [...filteredUsed.value];
    const col = usedSortColumn.value;
    const dir = usedSortDir.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (col === 'pool') return (a.pool ?? '').localeCompare(b.pool ?? '') * dir;
        if (col === 'address') return (a.address ?? '').localeCompare(b.address ?? '') * dir;
        if (col === 'owner') return (a.owner ?? '').localeCompare(b.owner ?? '') * dir;
        if (col === 'info') return (a.info ?? '').localeCompare(b.info ?? '') * dir;
        return 0;
    });
});

// Pagination
const activeList = computed(() => (activeTab.value === 'pools' ? sortedPools.value : sortedUsed.value));

const totalPages = computed(() => {
    if (perPage.value === 'all') return 1;
    return Math.max(1, Math.ceil(activeList.value.length / perPage.value));
});

const paginatedPools = computed(() => {
    if (perPage.value === 'all') return sortedPools.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedPools.value.slice(start, start + perPage.value);
});

const paginatedUsed = computed(() => {
    if (perPage.value === 'all') return sortedUsed.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedUsed.value.slice(start, start + perPage.value);
});

watch([search, activeTab, perPage], () => {
    currentPage.value = 1;
});

// Sort Toggles
function togglePoolSort(col: string) {
    if (poolSortColumn.value === col) {
        poolSortDir.value = poolSortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        poolSortColumn.value = col;
        poolSortDir.value = 'asc';
    }
}

function toggleUsedSort(col: string) {
    if (usedSortColumn.value === col) {
        usedSortDir.value = usedSortDir.value === 'asc' ? 'desc' : 'asc';
    } else {
        usedSortColumn.value = col;
        usedSortDir.value = 'asc';
    }
}

// Selection helpers for Pools
const isAllPageSelected = computed(() => {
    const pageItems = paginatedPools.value;
    if (pageItems.length === 0) return false;
    return pageItems.every((item) => selectedPoolIds.value.includes(item['.id']));
});

function toggleSelectAllPage() {
    const pageIds = paginatedPools.value.map((i) => i['.id']);
    if (isAllPageSelected.value) {
        selectedPoolIds.value = selectedPoolIds.value.filter((id) => !pageIds.includes(id));
    } else {
        selectedPoolIds.value = Array.from(new Set([...selectedPoolIds.value, ...pageIds]));
    }
}

function toggleSelectPool(id: string) {
    const idx = selectedPoolIds.value.indexOf(id);
    if (idx >= 0) selectedPoolIds.value.splice(idx, 1);
    else selectedPoolIds.value.push(id);
}

function clearSelection() {
    selectedPoolIds.value = [];
}

// Batch Actions
function runBatchPools(action: 'destroy') {
    if (selectedPoolIds.value.length === 0) return;
    if (!confirm(`Apakah Anda yakin ingin menghapus ${selectedPoolIds.value.length} IP pool terpilih?`)) {
        return;
    }

    isBatchOperating.value = true;
    inertia.post(
        route('routers.ip.pool.batch', props.router.id),
        { action, ids: selectedPoolIds.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isBatchOperating.value = false;
                selectedPoolIds.value = [];
            },
        },
    );
}

// Modals & Single Actions
function openAdd() {
    form.reset();
    form.id = '';
    form.next_pool = 'none';
    showAddModal.value = true;
}

function openEdit(pool: IpPool) {
    form.id = pool['.id'];
    form.name = pool.name;
    form.ranges = pool.ranges;
    form.next_pool = pool['next-pool'] || 'none';
    form.comment = pool.comment || '';
    showEditModal.value = true;
}

function savePool() {
    form.post(route('routers.ip.pool.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddModal.value = false;
            form.reset();
        },
    });
}

function updatePool() {
    form.put(route('routers.ip.pool.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            form.reset();
        },
    });
}

function removePool(pool: IpPool) {
    if (!confirm(`Remove IP pool "${pool.name}"?`)) return;

    inertia.delete(route('routers.ip.pool.destroy', props.router.id), {
        data: { id: pool['.id'] },
        preserveScroll: true,
        onSuccess: () => {
            selectedPoolIds.value = selectedPoolIds.value.filter((id) => id !== pool['.id']);
        },
    });
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// CSV Export
function exportCsv() {
    if (activeTab.value === 'pools') {
        const rows = sortedPools.value.map((p, idx) => ({
            '#': idx + 1,
            Name: p.name,
            Ranges: p.ranges,
            'Next Pool': p['next-pool'] ?? '',
            Comment: p.comment ?? '',
        }));

        if (rows.length === 0) return;
        const headers = Object.keys(rows[0]!).join(',');
        const csvContent = [headers, ...rows.map((r) => Object.values(r).map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))].join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `ip-pools-${props.router.name || 'router'}-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
    } else {
        const rows = sortedUsed.value.map((u, idx) => ({
            '#': idx + 1,
            Pool: u.pool ?? '',
            Address: u.address ?? '',
            Owner: u.owner ?? '',
            Info: u.info ?? '',
        }));

        if (rows.length === 0) return;
        const headers = Object.keys(rows[0]!).join(',');
        const csvContent = [headers, ...rows.map((r) => Object.values(r).map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))].join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `ip-pool-used-${props.router.name || 'router'}-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
    }
}
</script>

<template>
    <Head :title="`IP Pool — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- 6 KPI Stat Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Pools -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total Pools</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        {{ stats.totalPools }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Blok IP address</span>
                </div>

                <!-- Total Allocated -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Used IPs</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                        {{ stats.totalAllocated }}
                    </p>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Sedang terpakai</span>
                </div>

                <!-- Chained Next Pools -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Next Pool</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                        {{ stats.chainedPools }}
                    </p>
                    <span class="text-[11px] text-indigo-600 dark:text-indigo-400">Chained pools</span>
                </div>

                <!-- DHCP Leased -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">DHCP Used</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-amber-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
                        {{ stats.dhcpAllocated }}
                    </p>
                    <span class="text-[11px] text-amber-600 dark:text-amber-400">DHCP clients</span>
                </div>

                <!-- PPP Leased -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">PPP Used</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-violet-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-violet-600 dark:text-violet-400">
                        {{ stats.pppAllocated }}
                    </p>
                    <span class="text-[11px] text-violet-600 dark:text-violet-400">PPPoE / VPN</span>
                </div>

                <!-- Other / Static -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Other Services</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-cyan-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-cyan-600 dark:text-cyan-400">
                        {{ stats.otherAllocated }}
                    </p>
                    <span class="text-[11px] text-cyan-600 dark:text-cyan-400">Hotspot / System</span>
                </div>
            </div>

            <!-- Main Card -->
            <div class="flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header Tabs -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">IP Pool Overview</span>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /ip/pool
                            </span>
                        </div>
                    </div>

                    <div class="flex gap-1 overflow-x-auto text-xs">
                        <button
                            type="button"
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'pools'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'pools'"
                        >
                            Pools ({{ pools.length }})
                        </button>
                        <button
                            type="button"
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'used'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'used'"
                        >
                            Used Addresses ({{ used.length }})
                        </button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="activeTab === 'pools' && can.manage" size="sm" variant="primary" @click="openAdd">
                            <span>＋</span> Add Pool
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
                            :placeholder="activeTab === 'pools' ? 'Cari nama pool, ranges...' : 'Cari pool, address, owner...'"
                            class="w-48 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 pl-8 text-xs placeholder-zinc-400 transition-all focus:w-64 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                        <span class="pointer-events-none absolute left-2.5 top-1.5 text-xs text-zinc-400">⌕</span>
                    </div>
                </div>

                <!-- Floating Sticky Batch Actions Bar for Pools -->
                <div
                    v-if="activeTab === 'pools' && selectedPoolIds.length > 0"
                    class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/95 px-4 py-2.5 backdrop-blur-xs dark:border-indigo-900/60 dark:bg-indigo-950/90"
                >
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-bold text-white">
                            {{ selectedPoolIds.length }}
                        </span>
                        <span class="text-xs font-semibold text-indigo-950 dark:text-indigo-200">
                            {{ selectedPoolIds.length }} pool dipilih
                        </span>
                    </div>

                    <div v-if="can.manage" class="flex flex-wrap items-center gap-1.5">
                        <Btn size="sm" variant="danger" :disabled="isBatchOperating" @click="runBatchPools('destroy')">
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

                <!-- Tables -->
                <div class="flex-1 overflow-auto">
                    <!-- 1. Pools Table -->
                    <table v-if="activeTab === 'pools'" class="w-full text-left font-mono text-xs">
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
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="togglePoolSort('name')">
                                    <div class="flex items-center gap-1">
                                        <span>Name</span>
                                        <span class="text-[10px] text-zinc-400">{{ poolSortColumn === 'name' ? (poolSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="togglePoolSort('ranges')">
                                    <div class="flex items-center gap-1">
                                        <span>Address Ranges</span>
                                        <span class="text-[10px] text-zinc-400">{{ poolSortColumn === 'ranges' ? (poolSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="togglePoolSort('next-pool')">
                                    <div class="flex items-center gap-1">
                                        <span>Next Pool</span>
                                        <span class="text-[10px] text-zinc-400">{{ poolSortColumn === 'next-pool' ? (poolSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="togglePoolSort('comment')">
                                    <div class="flex items-center gap-1">
                                        <span>Comment</span>
                                        <span class="text-[10px] text-zinc-400">{{ poolSortColumn === 'comment' ? (poolSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="px-3 py-2 text-right font-sans">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-900">
                            <tr
                                v-for="(pool, idx) in paginatedPools"
                                :key="pool['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/30"
                                :class="selectedPoolIds.includes(pool['.id']) && 'bg-indigo-100/70 font-semibold text-indigo-900 dark:bg-indigo-950/70 dark:text-indigo-200'"
                                @click="toggleSelectPool(pool['.id'])"
                                @dblclick="can.manage && openEdit(pool)"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded-sm border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        :checked="selectedPoolIds.includes(pool['.id'])"
                                        @change="toggleSelectPool(pool['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2 text-center font-sans text-zinc-400">
                                    {{ (currentPage - 1) * (perPage === 'all' ? 0 : perPage) + idx + 1 }}
                                </td>
                                <td class="px-3 py-2 font-bold text-zinc-800 dark:text-zinc-200">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ pool.name }}</span>
                                        <button
                                            type="button"
                                            title="Copy Name"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(pool.name, 'name')"
                                        >
                                            <span v-if="copiedField === `name:${pool.name}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ pool.ranges }}</span>
                                        <button
                                            type="button"
                                            title="Copy Ranges"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(pool.ranges, 'range')"
                                        >
                                            <span v-if="copiedField === `range:${pool.ranges}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-500">
                                    <span v-if="pool['next-pool'] && pool['next-pool'] !== 'none'" class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ pool['next-pool'] }}
                                    </span>
                                    <span v-else class="text-zinc-400">-</span>
                                </td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ pool.comment || '-' }}</td>
                                <td class="px-3 py-2 text-right font-sans" @click.stop>
                                    <div class="flex items-center justify-end gap-1">
                                        <Btn
                                            v-if="can.manage"
                                            size="sm"
                                            variant="ghost"
                                            title="Edit"
                                            @click="openEdit(pool)"
                                        >
                                            Edit
                                        </Btn>
                                        <Btn
                                            v-if="can.manage"
                                            size="sm"
                                            variant="danger"
                                            title="Remove"
                                            @click="removePool(pool)"
                                        >
                                            Del
                                        </Btn>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="paginatedPools.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No IP pools configured. Click "+ Add Pool" to create one.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 2. Used Addresses Table -->
                    <table v-else class="w-full text-left font-mono text-xs">
                        <thead class="sticky top-0 bg-zinc-100 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-2 py-2 text-center font-sans">#</th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleUsedSort('pool')">
                                    <div class="flex items-center gap-1">
                                        <span>Pool</span>
                                        <span class="text-[10px] text-zinc-400">{{ usedSortColumn === 'pool' ? (usedSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleUsedSort('address')">
                                    <div class="flex items-center gap-1">
                                        <span>Allocated Address</span>
                                        <span class="text-[10px] text-zinc-400">{{ usedSortColumn === 'address' ? (usedSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleUsedSort('owner')">
                                    <div class="flex items-center gap-1">
                                        <span>Owner / Service</span>
                                        <span class="text-[10px] text-zinc-400">{{ usedSortColumn === 'owner' ? (usedSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleUsedSort('info')">
                                    <div class="flex items-center gap-1">
                                        <span>Info</span>
                                        <span class="text-[10px] text-zinc-400">{{ usedSortColumn === 'info' ? (usedSortDir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-900">
                            <tr
                                v-for="(item, idx) in paginatedUsed"
                                :key="item['.id'] || idx"
                                class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50"
                            >
                                <td class="px-2 py-2 text-center font-sans text-zinc-400">
                                    {{ (currentPage - 1) * (perPage === 'all' ? 0 : perPage) + idx + 1 }}
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-800 dark:text-zinc-200">{{ item.pool || '-' }}</td>
                                <td class="px-3 py-2 text-indigo-600 dark:text-indigo-400">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ item.address || '-' }}</span>
                                        <button
                                            v-if="item.address"
                                            type="button"
                                            title="Copy IP"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(item.address, 'used')"
                                        >
                                            <span v-if="copiedField === `used:${item.address}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-sans">
                                    <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                        {{ item.owner || 'active' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-zinc-500">{{ item.info || '-' }}</td>
                            </tr>
                            <tr v-if="paginatedUsed.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No active address allocations found in IP pools.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Pagination & Range Bar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-4">
                        <span>
                            Menampilkan <strong>{{ activeList.length === 0 ? 0 : (currentPage - 1) * (perPage === 'all' ? activeList.length : perPage) + 1 }}</strong> - <strong>{{ perPage === 'all' ? activeList.length : Math.min(currentPage * perPage, activeList.length) }}</strong> dari <strong>{{ activeList.length }}</strong> {{ activeTab === 'pools' ? 'pool' : 'alokasi' }}
                        </span>
                        <span v-if="activeTab === 'pools' && selectedPoolIds.length > 0" class="text-indigo-600 dark:text-indigo-400 font-semibold">
                            ({{ selectedPoolIds.length }} terpilih)
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

            <!-- Add Pool Modal -->
            <Modal :show="showAddModal" title="New IP Pool" @close="showAddModal = false">
                <form class="space-y-3" @submit.prevent="savePool">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Pool Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            placeholder="e.g. dhcp-pool-1"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Address Ranges</label>
                        <input
                            v-model="form.ranges"
                            type="text"
                            required
                            placeholder="e.g. 192.168.88.10-192.168.88.254"
                            class="mt-1 block w-full font-mono text-xs rounded-lg border border-zinc-300 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                        <p class="mt-0.5 text-[10px] text-zinc-500">Comma-separated ranges or single range (e.g. 10.0.0.2-10.0.0.100,10.0.0.150-10.0.0.200)</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Next Pool (Optional)</label>
                        <select
                            v-model="form.next_pool"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        >
                            <option value="none">none</option>
                            <option v-for="p in pools" :key="p['.id']" :value="p.name">{{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="form.comment"
                            type="text"
                            placeholder="Optional comment"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                        <Btn type="button" size="sm" variant="secondary" @click="showAddModal = false">Cancel</Btn>
                        <Btn type="submit" size="sm" variant="primary" :disabled="form.processing">Create Pool</Btn>
                    </div>
                </form>
            </Modal>

            <!-- Edit Pool Modal -->
            <Modal :show="showEditModal" title="Edit IP Pool" @close="showEditModal = false">
                <form class="space-y-3" @submit.prevent="updatePool">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Pool Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Address Ranges</label>
                        <input
                            v-model="form.ranges"
                            type="text"
                            required
                            class="mt-1 block w-full font-mono text-xs rounded-lg border border-zinc-300 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Next Pool</label>
                        <select
                            v-model="form.next_pool"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        >
                            <option value="none">none</option>
                            <option v-for="p in pools.filter(p => p['.id'] !== form.id)" :key="p['.id']" :value="p.name">{{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="form.comment"
                            type="text"
                            placeholder="Optional comment"
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
