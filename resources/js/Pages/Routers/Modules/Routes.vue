<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';

interface Rt {
    '.id': string;
    'dst-address': string;
    gateway?: string;
    distance?: string;
    active?: string;
    static?: string;
    disabled?: string;
    comment?: string;
}

const props = defineProps<{
    router: any;
    routes: Rt[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

// Selection & Batch
const selectedIds = ref<string[]>([]);
const isBatchOperating = ref(false);

// Filter & Search
const searchQuery = ref('');
const stateFilter = ref<'all' | 'active' | 'inactive' | 'static' | 'dynamic' | 'default'>('all');

// Sort & Pagination
const sortColumn = ref<string>('dst-address');
const sortDirection = ref<'asc' | 'desc'>('asc');
const currentPage = ref(1);
const perPage = ref<number | 'all'>(25);

// Modal state
const showModal = ref(false);
const form = useForm({ dst_address: '0.0.0.0/0', gateway: '', distance: 1, comment: '' });

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

// Helper for numeric IP octet sorting
function ipToNumber(ip: string): number {
    const cleanIp = ip.split('/')[0] || '';
    const parts = cleanIp.split('.').map((p) => parseInt(p, 10));
    if (parts.length === 4 && parts.every((p) => !isNaN(p))) {
        return (parts[0]! << 24) + (parts[1]! << 16) + (parts[2]! << 8) + parts[3]!;
    }
    return 0;
}

// KPI Statistics
const stats = computed(() => {
    const list = props.routes || [];
    const total = list.length;
    const activeCount = list.filter((r) => r.active === 'true').length;
    const inactiveCount = total - activeCount;
    const staticCount = list.filter((r) => r.static === 'true').length;
    const dynamicCount = total - staticCount;
    const defaultCount = list.filter((r) => r['dst-address'] === '0.0.0.0/0').length;

    return {
        total,
        active: activeCount,
        inactive: inactiveCount,
        static: staticCount,
        dynamic: dynamicCount,
        defaultRoutes: defaultCount,
    };
});

// Filtered & Sorted
const filteredRoutes = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return (props.routes || []).filter((r) => {
        if (stateFilter.value === 'active' && r.active !== 'true') return false;
        if (stateFilter.value === 'inactive' && r.active === 'true') return false;
        if (stateFilter.value === 'static' && r.static !== 'true') return false;
        if (stateFilter.value === 'dynamic' && r.static === 'true') return false;
        if (stateFilter.value === 'default' && r['dst-address'] !== '0.0.0.0/0') return false;

        if (!q) return true;
        return (
            r['dst-address'].toLowerCase().includes(q) ||
            (r.gateway ?? '').toLowerCase().includes(q) ||
            (r.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedRoutes = computed(() => {
    const list = [...filteredRoutes.value];
    const col = sortColumn.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (col === 'dst-address') {
            const numA = ipToNumber(a['dst-address']);
            const numB = ipToNumber(b['dst-address']);
            if (numA !== 0 && numB !== 0) return (numA - numB) * dir;
            return a['dst-address'].localeCompare(b['dst-address']) * dir;
        }
        if (col === 'gateway') {
            return (a.gateway ?? '').localeCompare(b.gateway ?? '') * dir;
        }
        if (col === 'distance') {
            const distA = parseInt(a.distance ?? '1', 10);
            const distB = parseInt(b.distance ?? '1', 10);
            return (distA - distB) * dir;
        }
        if (col === 'active') {
            const actA = a.active === 'true' ? 1 : 0;
            const actB = b.active === 'true' ? 1 : 0;
            return (actA - actB) * dir;
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
    return Math.max(1, Math.ceil(sortedRoutes.value.length / perPage.value));
});

const paginatedRoutes = computed(() => {
    if (perPage.value === 'all') return sortedRoutes.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedRoutes.value.slice(start, start + perPage.value);
});

// Reset page on search/filter changes
watch([searchQuery, stateFilter, perPage], () => {
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
    const pageItems = paginatedRoutes.value;
    if (pageItems.length === 0) return false;
    return pageItems.every((item) => selectedIds.value.includes(item['.id']));
});

function toggleSelectAllPage() {
    const pageIds = paginatedRoutes.value.map((i) => i['.id']);
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
    if (!confirm(`Apakah Anda yakin ingin ${actionLabel} ${selectedIds.value.length} route terpilih?`)) {
        return;
    }

    isBatchOperating.value = true;
    inertia.post(
        route('routers.ip.routes.batch', props.router.id),
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
function submit() {
    form.post(route('routers.ip.routes.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => (showModal.value = false),
    });
}

function toggle(r: Rt) {
    inertia.post(
        route('routers.ip.routes.toggle', props.router.id),
        { id: r['.id'], disable: r.disabled !== 'true' },
        { preserveScroll: true },
    );
}

function remove(r: Rt) {
    if (confirm(`Remove route ${r['dst-address']} -> ${r.gateway ?? 'none'}?`)) {
        inertia.delete(route('routers.ip.routes.destroy', props.router.id), {
            data: { id: r['.id'] },
            preserveScroll: true,
            onSuccess: () => {
                selectedIds.value = selectedIds.value.filter((id) => id !== r['.id']);
            },
        });
    }
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// CSV Export
function exportCsv() {
    const rows = sortedRoutes.value.map((r, idx) => ({
        '#': idx + 1,
        'Dst Address': r['dst-address'],
        Gateway: r.gateway ?? '',
        Distance: r.distance ?? '1',
        State: r.active === 'true' ? 'Active' : 'Inactive',
        Type: r.static === 'true' ? 'Static' : 'Dynamic',
        Status: r.disabled === 'true' ? 'Disabled' : 'Enabled',
        Comment: r.comment ?? '',
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
    link.download = `ip-routes-${props.router.name || 'router'}-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
}
</script>

<template>
    <Head title="Routes" />
    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- 6 KPI Stat Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Routes -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total Routes</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        {{ stats.total }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Tabel routing</span>
                </div>

                <!-- Active -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Active</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                        {{ stats.active }}
                    </p>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Reachable & Live</span>
                </div>

                <!-- Inactive -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Inactive</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-zinc-400"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-600 dark:text-zinc-300">
                        {{ stats.inactive }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Unreachable / Off</span>
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
                    <span class="text-[11px] text-amber-600 dark:text-amber-400">Manual Entry</span>
                </div>

                <!-- Dynamic -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Dynamic</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                        {{ stats.dynamic }}
                    </p>
                    <span class="text-[11px] text-indigo-600 dark:text-indigo-400">Connected / OSPF</span>
                </div>

                <!-- Default Gateway -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Default Gateway</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-violet-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-violet-600 dark:text-violet-400">
                        {{ stats.defaultRoutes }}
                    </p>
                    <span class="text-[11px] text-violet-600 dark:text-violet-400">0.0.0.0/0 WAN</span>
                </div>
            </div>

            <!-- Main Card -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-bold text-indigo-600 dark:text-indigo-400">↝</span>
                        <h2 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">IP Route List</h2>
                        <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ sortedRoutes.length }} of {{ routes.length }}
                        </span>
                    </div>

                    <!-- Filter pills -->
                    <div class="flex flex-wrap items-center gap-1.5 text-xs">
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="stateFilter === 'all' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700'"
                            @click="stateFilter = 'all'"
                        >
                            Semua ({{ routes.length }})
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="stateFilter === 'active' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700'"
                            @click="stateFilter = 'active'"
                        >
                            Active ({{ stats.active }})
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="stateFilter === 'inactive' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700'"
                            @click="stateFilter = 'inactive'"
                        >
                            Inactive ({{ stats.inactive }})
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="stateFilter === 'static' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700'"
                            @click="stateFilter = 'static'"
                        >
                            Static
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                            :class="stateFilter === 'default' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700'"
                            @click="stateFilter = 'default'"
                        >
                            Default (0.0.0.0/0)
                        </button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage" size="sm" variant="primary" @click="showModal = true">+ Add Static Route</Btn>
                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">↻ Refresh</Btn>
                        <Btn size="sm" variant="ghost" title="Export CSV" @click="exportCsv">⤓ Export CSV</Btn>
                    </div>

                    <!-- Search Input -->
                    <div class="relative">
                        <input
                            v-model="searchQuery"
                            type="search"
                            placeholder="Cari Dst / Gateway / Comment..."
                            class="w-48 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 pl-8 text-xs placeholder-zinc-400 transition-all focus:w-64 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                        <span class="pointer-events-none absolute left-2.5 top-2 text-xs text-zinc-400">⌕</span>
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
                            {{ selectedIds.length }} route dipilih
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
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('dst-address')">
                                    <div class="flex items-center gap-1">
                                        <span>Dst Address</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'dst-address' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('gateway')">
                                    <div class="flex items-center gap-1">
                                        <span>Gateway</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'gateway' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('distance')">
                                    <div class="flex items-center gap-1">
                                        <span>Distance</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'distance' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('active')">
                                    <div class="flex items-center gap-1">
                                        <span>State & Flags</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'active' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
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
                            <tr v-if="paginatedRoutes.length === 0">
                                <td colspan="8" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada route yang cocok dengan filter.
                                </td>
                            </tr>
                            <tr
                                v-for="(r, idx) in paginatedRoutes"
                                :key="r['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="[
                                    selectedIds.includes(r['.id']) && 'bg-indigo-50/70 dark:bg-indigo-950/40',
                                    r.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="toggleSelectItem(r['.id'])"
                            >
                                <td class="px-3 py-2.5 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded-sm border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        :checked="selectedIds.includes(r['.id'])"
                                        @change="toggleSelectItem(r['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2.5 text-center font-mono text-[11px] text-zinc-400">
                                    {{ (currentPage - 1) * (perPage === 'all' ? 0 : perPage) + idx + 1 }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-1.5 font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                                        <span>{{ r['dst-address'] }}</span>
                                        <button
                                            type="button"
                                            title="Copy Dst Address"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(r['dst-address'], 'dst')"
                                        >
                                            <span v-if="copiedField === `dst:${r['dst-address']}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ r.gateway ?? '—' }}</span>
                                        <button
                                            v-if="r.gateway"
                                            type="button"
                                            title="Copy Gateway"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(r.gateway, 'gw')"
                                        >
                                            <span v-if="copiedField === `gw:${r.gateway}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-700 dark:text-zinc-300">
                                    <span class="rounded bg-zinc-100 px-2 py-0.5 text-xs font-mono dark:bg-zinc-800">
                                        {{ r.distance ?? '1' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-1">
                                        <span v-if="r.active === 'true'" class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">
                                            active
                                        </span>
                                        <span v-else class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-500 dark:bg-zinc-800">
                                            inactive
                                        </span>
                                        <span v-if="r.static === 'true'" class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">
                                            static
                                        </span>
                                        <span v-else class="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700 dark:bg-blue-950/40 dark:text-blue-400">
                                            dynamic
                                        </span>
                                        <span v-if="r.disabled === 'true'" class="rounded bg-red-50 px-1.5 py-0.5 text-[10px] text-red-600 dark:bg-red-950/40">
                                            disabled
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500 italic">
                                    {{ r.comment || '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage && r.static === 'true'" class="flex justify-end items-center gap-1">
                                        <Btn
                                            size="sm"
                                            :variant="r.disabled === 'true' ? 'primary' : 'ghost'"
                                            @click="toggle(r)"
                                        >
                                            {{ r.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="danger" @click="remove(r)">
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
                        <span>Menampilkan <strong>{{ sortedRoutes.length === 0 ? 0 : (currentPage - 1) * (perPage === 'all' ? sortedRoutes.length : perPage) + 1 }}</strong> - <strong>{{ perPage === 'all' ? sortedRoutes.length : Math.min(currentPage * perPage, sortedRoutes.length) }}</strong> dari <strong>{{ sortedRoutes.length }}</strong> route</span>
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

            <!-- Modal Add Static Route -->
            <Modal :open="showModal" title="Add Static Route" @close="showModal = false">
                <form class="space-y-4 text-xs" @submit.prevent="submit">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Dst Address</label>
                        <input
                            v-model="form.dst_address"
                            placeholder="0.0.0.0/0"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                            required
                        />
                        <p v-if="form.errors.dst_address" class="mt-1 text-red-500">{{ form.errors.dst_address }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Gateway</label>
                        <input
                            v-model="form.gateway"
                            placeholder="192.168.88.254"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                            required
                        />
                        <p v-if="form.errors.gateway" class="mt-1 text-red-500">{{ form.errors.gateway }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Distance</label>
                        <input
                            v-model.number="form.distance"
                            type="number"
                            min="1"
                            max="255"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="form.comment"
                            placeholder="e.g. Default Gateway ISP 1"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                        <Btn variant="ghost" @click="showModal = false">Cancel</Btn>
                        <Btn type="submit" variant="primary" :disabled="form.processing">Add Route</Btn>
                    </div>
                </form>
            </Modal>
        </div>
    </RouterWorkspace>
</template>
