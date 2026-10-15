<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface StaticDns {
    '.id': string;
    name: string;
    address: string;
    ttl?: string;
    disabled?: string;
    comment?: string;
}

const props = defineProps<{
    router: any;
    dnsSettings: Record<string, string>;
    staticEntries: StaticDns[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

type DnsTab = 'static' | 'settings';
const activeTab = ref<DnsTab>('static');

// Selection & Batch
const selectedIds = ref<string[]>([]);
const isBatchOperating = ref(false);

// Filter & Search
const search = ref('');
const statusFilter = ref<'all' | 'enabled' | 'disabled'>('all');

// Sort & Pagination
const sortColumn = ref<string>('name');
const sortDirection = ref<'asc' | 'desc'>('asc');
const currentPage = ref(1);
const perPage = ref<number | 'all'>(25);

// Modals
const showAddModal = ref(false);
const showEditModal = ref(false);

const settingsForm = useForm({
    servers: props.dnsSettings.servers ?? '8.8.8.8,1.1.1.1',
    allow_remote_requests: props.dnsSettings['allow-remote-requests'] === 'true' || props.dnsSettings['allow-remote-requests'] === 'yes',
    cache_size: props.dnsSettings['cache-size'] ?? '2048KiB',
});

const staticForm = useForm({
    id: '',
    name: '',
    address: '',
    ttl: '1d',
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
    const list = props.staticEntries || [];
    const total = list.length;
    const disabledCount = list.filter((e) => e.disabled === 'true').length;
    const activeCount = total - disabledCount;
    const isAllowRemote = props.dnsSettings['allow-remote-requests'] === 'true' || props.dnsSettings['allow-remote-requests'] === 'yes';
    const upstreamServers = (props.dnsSettings.servers ?? '').split(',').map((s) => s.trim()).filter(Boolean).length;
    const cacheSize = props.dnsSettings['cache-size'] ?? '2048KiB';

    return {
        total,
        active: activeCount,
        disabled: disabledCount,
        allowRemote: isAllowRemote,
        upstreamCount: upstreamServers,
        cacheSize,
    };
});

// Filtered & Sorted
const filteredStatic = computed(() => {
    const q = search.value.trim().toLowerCase();
    return (props.staticEntries || []).filter((item) => {
        if (statusFilter.value === 'enabled' && item.disabled === 'true') return false;
        if (statusFilter.value === 'disabled' && item.disabled !== 'true') return false;

        if (!q) return true;
        return (
            item.name.toLowerCase().includes(q) ||
            item.address.toLowerCase().includes(q) ||
            (item.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedStatic = computed(() => {
    const list = [...filteredStatic.value];
    const col = sortColumn.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (col === 'name') return a.name.localeCompare(b.name) * dir;
        if (col === 'address') return a.address.localeCompare(b.address) * dir;
        if (col === 'ttl') return (a.ttl ?? '').localeCompare(b.ttl ?? '') * dir;
        if (col === 'status') {
            const valA = a.disabled === 'true' ? 1 : 0;
            const valB = b.disabled === 'true' ? 1 : 0;
            return (valA - valB) * dir;
        }
        if (col === 'comment') return (a.comment ?? '').localeCompare(b.comment ?? '') * dir;
        return 0;
    });
});

// Pagination
const totalPages = computed(() => {
    if (perPage.value === 'all') return 1;
    return Math.max(1, Math.ceil(sortedStatic.value.length / perPage.value));
});

const paginatedStatic = computed(() => {
    if (perPage.value === 'all') return sortedStatic.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedStatic.value.slice(start, start + perPage.value);
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
    const pageItems = paginatedStatic.value;
    if (pageItems.length === 0) return false;
    return pageItems.every((item) => selectedIds.value.includes(item['.id']));
});

function toggleSelectAllPage() {
    const pageIds = paginatedStatic.value.map((i) => i['.id']);
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
function runBatch(action: 'enable' | 'disable' | 'destroy') {
    if (selectedIds.value.length === 0) return;
    const actionLabel = action === 'destroy' ? 'menghapus' : action === 'enable' ? 'mengaktifkan' : 'menonaktifkan';
    if (!confirm(`Apakah Anda yakin ingin ${actionLabel} ${selectedIds.value.length} entri DNS static terpilih?`)) {
        return;
    }

    isBatchOperating.value = true;
    inertia.post(
        route('routers.ip.dns.static.batch', props.router.id),
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

// Single actions
function refreshData() {
    inertia.reload({ preserveScroll: true });
}

function saveSettings() {
    settingsForm.post(route('routers.ip.dns.settings.update', props.router.id), {
        preserveScroll: true,
    });
}

function openAddStatic() {
    staticForm.reset();
    staticForm.ttl = '1d';
    showAddModal.value = true;
}

function openEditStatic(item: StaticDns) {
    staticForm.id = item['.id'];
    staticForm.name = item.name;
    staticForm.address = item.address;
    staticForm.ttl = item.ttl || '1d';
    staticForm.comment = item.comment || '';
    showEditModal.value = true;
}

function saveStatic() {
    staticForm.post(route('routers.ip.dns.static.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddModal.value = false;
            staticForm.reset();
        },
    });
}

function updateStatic() {
    staticForm.put(route('routers.ip.dns.static.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
        },
    });
}

function toggleStatic(item: StaticDns, disable: boolean) {
    inertia.post(
        route('routers.ip.dns.static.toggle', props.router.id),
        { id: item['.id'], disable },
        { preserveScroll: true },
    );
}

function removeStatic(item: StaticDns) {
    if (!confirm(`Remove static DNS "${item.name}"?`)) return;

    inertia.delete(route('routers.ip.dns.static.destroy', props.router.id), {
        data: { id: item['.id'] },
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = selectedIds.value.filter((id) => id !== item['.id']);
        },
    });
}

// CSV Export
function exportCsv() {
    const rows = sortedStatic.value.map((s, idx) => ({
        '#': idx + 1,
        'Domain Name': s.name,
        'IP Address': s.address,
        TTL: s.ttl ?? '1d',
        Status: s.disabled === 'true' ? 'Disabled' : 'Enabled',
        Comment: s.comment ?? '',
    }));

    if (rows.length === 0) return;
    const headers = Object.keys(rows[0]!).join(',');
    const csvContent = [headers, ...rows.map((r) => Object.values(r).map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))].join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `static-dns-${props.router.name || 'router'}-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
}
</script>

<template>
    <Head :title="`DNS — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- 6 KPI Stat Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Static DNS -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Static DNS</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        {{ stats.total }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Entri domain lokal</span>
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
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Resolving aktif</span>
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

                <!-- Remote Requests -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Remote DNS</span>
                        <span class="inline-flex h-2 w-2 rounded-full" :class="stats.allowRemote ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight" :class="stats.allowRemote ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">
                        {{ stats.allowRemote ? 'Allowed' : 'Disabled' }}
                    </p>
                    <span class="text-[11px] text-zinc-500">DNS Server LAN</span>
                </div>

                <!-- Upstream Servers -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Upstream DNS</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                        {{ stats.upstreamCount }}
                    </p>
                    <span class="text-[11px] text-indigo-600 dark:text-indigo-400">Server terdaftar</span>
                </div>

                <!-- Cache Size -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Cache Size</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-cyan-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-cyan-600 dark:text-cyan-400">
                        {{ stats.cacheSize }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Used: {{ dnsSettings['cache-used'] || '0KiB' }}</span>
                </div>
            </div>

            <!-- Main Card -->
            <div class="flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header Tabs -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">DNS Management</span>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /ip/dns
                            </span>
                        </div>
                    </div>

                    <div class="flex gap-1 overflow-x-auto text-xs">
                        <button
                            type="button"
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'static'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'static'"
                        >
                            Static DNS ({{ staticEntries.length }})
                        </button>
                        <button
                            type="button"
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'settings'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'settings'"
                        >
                            DNS Settings
                        </button>
                    </div>
                </div>

                <!-- Toolbar (Static DNS) -->
                <div v-if="activeTab === 'static'" class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage" size="sm" variant="primary" class="gap-1.5" @click="openAddStatic">
                            <span>＋</span> Add DNS Entry
                        </Btn>
                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh
                        </Btn>
                        <Btn size="sm" variant="ghost" title="Export CSV" @click="exportCsv">
                            ⤓ Export CSV
                        </Btn>
                    </div>

                    <div class="flex items-center gap-2">
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
                                :class="statusFilter === 'enabled' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                                @click="statusFilter = 'enabled'"
                            >
                                Active ({{ stats.active }})
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

                        <div class="relative">
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Cari domain, IP, comment..."
                                class="w-48 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 pl-8 text-xs placeholder-zinc-400 transition-all focus:w-64 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                            <span class="pointer-events-none absolute left-2.5 top-1.5 text-xs text-zinc-400">⌕</span>
                        </div>
                    </div>
                </div>

                <!-- Floating Sticky Batch Actions Bar for Static DNS -->
                <div
                    v-if="activeTab === 'static' && selectedIds.length > 0"
                    class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/95 px-4 py-2.5 backdrop-blur-xs dark:border-indigo-900/60 dark:bg-indigo-950/90"
                >
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[10px] font-bold text-white">
                            {{ selectedIds.length }}
                        </span>
                        <span class="text-xs font-semibold text-indigo-950 dark:text-indigo-200">
                            {{ selectedIds.length }} entri DNS static dipilih
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

                <!-- Main Content Area -->
                <div class="flex-1 overflow-auto">
                    <!-- 1. Static DNS Table -->
                    <table v-if="activeTab === 'static'" class="w-full text-left text-xs">
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
                                <th class="w-8 px-2 py-2 text-center font-sans">#</th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('name')">
                                    <div class="flex items-center gap-1">
                                        <span>Domain Name (FQDN)</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'name' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('address')">
                                    <div class="flex items-center gap-1">
                                        <span>IP Address</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'address' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('ttl')">
                                    <div class="flex items-center gap-1">
                                        <span>TTL</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'ttl' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('status')">
                                    <div class="flex items-center gap-1">
                                        <span>Status</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'status' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('comment')">
                                    <div class="flex items-center gap-1">
                                        <span>Comment</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'comment' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="px-3 py-2 text-right font-sans">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="(s, idx) in paginatedStatic"
                                :key="s['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedIds.includes(s['.id']) && 'bg-indigo-100/60 dark:bg-indigo-950/40',
                                    s.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="toggleSelectItem(s['.id'])"
                                @dblclick="can.manage && openEditStatic(s)"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded-sm border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        :checked="selectedIds.includes(s['.id'])"
                                        @change="toggleSelectItem(s['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2 text-center font-sans text-zinc-400">
                                    {{ (currentPage - 1) * (perPage === 'all' ? 0 : perPage) + idx + 1 }}
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ s.name }}</span>
                                        <button
                                            type="button"
                                            title="Copy Domain"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(s.name, 'dom')"
                                        >
                                            <span v-if="copiedField === `dom:${s.name}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-indigo-700 dark:text-indigo-400">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ s.address }}</span>
                                        <button
                                            type="button"
                                            title="Copy IP"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(s.address, 'ip')"
                                        >
                                            <span v-if="copiedField === `ip:${s.address}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ s.ttl || '1d' }}</td>
                                <td class="px-3 py-2 font-sans">
                                    <span v-if="s.disabled === 'true'" class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-500 dark:bg-zinc-800">
                                        disabled
                                    </span>
                                    <span v-else class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400">
                                        active
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ s.comment || '-' }}</td>
                                <td class="px-3 py-2 text-right font-sans" @click.stop>
                                    <div v-if="can.manage" class="flex items-center justify-end gap-1">
                                        <Btn
                                            size="sm"
                                            :variant="s.disabled === 'true' ? 'primary' : 'ghost'"
                                            @click="toggleStatic(s, s.disabled !== 'true')"
                                        >
                                            {{ s.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" title="Edit" @click="openEditStatic(s)">
                                            Edit
                                        </Btn>
                                        <Btn size="sm" variant="danger" title="Remove" @click="removeStatic(s)">
                                            Del
                                        </Btn>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="paginatedStatic.length === 0">
                                <td colspan="8" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No static DNS entries found.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 2. DNS Settings Tab -->
                    <div v-else class="max-w-xl p-6">
                        <form class="space-y-4" @submit.prevent="saveSettings">
                            <div>
                                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                    Upstream DNS Servers (comma separated)
                                </label>
                                <input
                                    v-model="settingsForm.servers"
                                    type="text"
                                    required
                                    placeholder="8.8.8.8, 1.1.1.1"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                                />
                                <p class="mt-1 text-[11px] text-zinc-500">
                                    Dynamic Servers from ISP: {{ dnsSettings['dynamic-servers'] || 'none' }}
                                </p>
                            </div>

                            <div>
                                <label class="flex items-center gap-2 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                    <input
                                        v-model="settingsForm.allow_remote_requests"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                                    />
                                    Allow Remote Requests (Router acts as DNS Server for LAN)
                                </label>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                    Cache Size
                                </label>
                                <input
                                    v-model="settingsForm.cache_size"
                                    type="text"
                                    placeholder="2048KiB"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                                />
                                <p class="mt-1 text-[11px] text-zinc-500">
                                    Cache currently used: {{ dnsSettings['cache-used'] || '0KiB' }}
                                </p>
                            </div>

                            <div v-if="can.manage" class="pt-2">
                                <Btn type="submit" variant="primary" :disabled="settingsForm.processing">
                                    Save DNS Settings
                                </Btn>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Footer Pagination & Range Bar (for Static DNS) -->
                <div v-if="activeTab === 'static'" class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-4">
                        <span>
                            Menampilkan <strong>{{ sortedStatic.length === 0 ? 0 : (currentPage - 1) * (perPage === 'all' ? sortedStatic.length : perPage) + 1 }}</strong> - <strong>{{ perPage === 'all' ? sortedStatic.length : Math.min(currentPage * perPage, sortedStatic.length) }}</strong> dari <strong>{{ sortedStatic.length }}</strong> entri static
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

            <!-- Modal: Add Static DNS -->
            <Modal :show="showAddModal" title="New Static DNS Entry" @close="showAddModal = false">
                <form class="space-y-3" @submit.prevent="saveStatic">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Domain Name (FQDN)</label>
                        <input
                            v-model="staticForm.name"
                            type="text"
                            required
                            placeholder="e.g. router.lan or portal.wifi"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">IP Address</label>
                        <input
                            v-model="staticForm.address"
                            type="text"
                            required
                            placeholder="e.g. 192.168.88.1"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">TTL</label>
                        <input
                            v-model="staticForm.ttl"
                            type="text"
                            placeholder="1d"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="staticForm.comment"
                            type="text"
                            placeholder="Optional note"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Btn type="button" variant="secondary" @click="showAddModal = false">Cancel</Btn>
                        <Btn type="submit" variant="primary" :disabled="staticForm.processing">Add DNS Entry</Btn>
                    </div>
                </form>
            </Modal>

            <!-- Modal: Edit Static DNS -->
            <Modal :show="showEditModal" title="Edit Static DNS Entry" @close="showEditModal = false">
                <form class="space-y-3" @submit.prevent="updateStatic">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Domain Name (FQDN)</label>
                        <input
                            v-model="staticForm.name"
                            type="text"
                            required
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">IP Address</label>
                        <input
                            v-model="staticForm.address"
                            type="text"
                            required
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">TTL</label>
                        <input
                            v-model="staticForm.ttl"
                            type="text"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="staticForm.comment"
                            type="text"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Btn type="button" variant="secondary" @click="showEditModal = false">Cancel</Btn>
                        <Btn type="submit" variant="primary" :disabled="staticForm.processing">Save Changes</Btn>
                    </div>
                </form>
            </Modal>
        </div>
    </RouterWorkspace>
</template>
