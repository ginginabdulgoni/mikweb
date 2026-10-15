<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface IpService {
    '.id': string;
    name: string;
    port: string;
    address?: string;
    disabled?: string;
}

const props = defineProps<{
    router: any;
    services: IpService[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

// Selection & Batch
const selectedIds = ref<string[]>([]);
const isBatchOperating = ref(false);

// Filter & Search
const search = ref('');
const statusFilter = ref<'all' | 'enabled' | 'disabled'>('all');

// Sorting & Pagination
const sortColumn = ref<string>('name');
const sortDirection = ref<'asc' | 'desc'>('asc');
const currentPage = ref(1);
const perPage = ref<number | 'all'>(25);

// Modal
const showEditModal = ref(false);
const editingService = ref<IpService | null>(null);

const form = useForm({
    id: '',
    name: '',
    port: 80,
    address: '',
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

// KPI Stats
const stats = computed(() => {
    const list = props.services || [];
    const total = list.length;
    const disabledCount = list.filter((s) => s.disabled === 'true').length;
    const enabledCount = total - disabledCount;
    const restrictedCount = list.filter((s) => Boolean(s.address && s.address.trim())).length;
    const openCount = total - restrictedCount;
    const standardPorts = list.filter((s) => ['80', '443', '8291', '8728', '22', '23', '21'].includes(String(s.port))).length;

    return {
        total,
        enabled: enabledCount,
        disabled: disabledCount,
        restricted: restrictedCount,
        open: openCount,
        standardPorts,
    };
});

// Filtered & Sorted
const filteredServices = computed(() => {
    const q = search.value.trim().toLowerCase();
    return (props.services || []).filter((s) => {
        if (statusFilter.value === 'enabled' && s.disabled === 'true') return false;
        if (statusFilter.value === 'disabled' && s.disabled !== 'true') return false;

        if (!q) return true;
        return (
            s.name.toLowerCase().includes(q) ||
            s.port.includes(q) ||
            (s.address ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedServices = computed(() => {
    const list = [...filteredServices.value];
    const col = sortColumn.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (col === 'name') return a.name.localeCompare(b.name) * dir;
        if (col === 'port') return (parseInt(a.port, 10) - parseInt(b.port, 10)) * dir;
        if (col === 'address') return (a.address ?? '').localeCompare(b.address ?? '') * dir;
        if (col === 'status') {
            const valA = a.disabled === 'true' ? 1 : 0;
            const valB = b.disabled === 'true' ? 1 : 0;
            return (valA - valB) * dir;
        }
        return 0;
    });
});

// Pagination
const totalPages = computed(() => {
    if (perPage.value === 'all') return 1;
    return Math.max(1, Math.ceil(sortedServices.value.length / perPage.value));
});

const paginatedServices = computed(() => {
    if (perPage.value === 'all') return sortedServices.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedServices.value.slice(start, start + perPage.value);
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
    const pageItems = paginatedServices.value;
    if (pageItems.length === 0) return false;
    return pageItems.every((item) => selectedIds.value.includes(item['.id']));
});

function toggleSelectAllPage() {
    const pageIds = paginatedServices.value.map((i) => i['.id']);
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
function runBatch(action: 'enable' | 'disable') {
    if (selectedIds.value.length === 0) return;
    const actionLabel = action === 'enable' ? 'mengaktifkan' : 'menonaktifkan';
    if (!confirm(`Apakah Anda yakin ingin ${actionLabel} ${selectedIds.value.length} service terpilih?`)) {
        return;
    }

    isBatchOperating.value = true;
    inertia.post(
        route('routers.ip.services.batch', props.router.id),
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

function openEdit(item: IpService) {
    editingService.value = item;
    form.id = item['.id'];
    form.name = item.name;
    form.port = Number(item.port);
    form.address = item.address || '';
    showEditModal.value = true;
}

function saveService() {
    form.put(route('routers.ip.services.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
        },
    });
}

function toggleService(item: IpService, disable: boolean) {
    inertia.post(
        route('routers.ip.services.toggle', props.router.id),
        { id: item['.id'], disable },
        { preserveScroll: true },
    );
}

// CSV Export
function exportCsv() {
    const rows = sortedServices.value.map((s, idx) => ({
        '#': idx + 1,
        Name: s.name,
        Port: s.port,
        'Available From': s.address || '0.0.0.0/0 (all)',
        Status: s.disabled === 'true' ? 'Disabled' : 'Enabled',
    }));

    if (rows.length === 0) return;
    const headers = Object.keys(rows[0]!).join(',');
    const csvContent = [headers, ...rows.map((r) => Object.values(r).map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))].join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `ip-services-${props.router.name || 'router'}-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
}
</script>

<template>
    <Head :title="`IP Services — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- 6 KPI Stat Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Services -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total Services</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        {{ stats.total }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Winbox, API, SSH, WWW</span>
                </div>

                <!-- Enabled -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Enabled</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                        {{ stats.enabled }}
                    </p>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Port terbuka</span>
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
                    <span class="text-[11px] text-zinc-500">Port ditutup</span>
                </div>

                <!-- Restricted -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">IP Restricted</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                        {{ stats.restricted }}
                    </p>
                    <span class="text-[11px] text-indigo-600 dark:text-indigo-400">Aman / Whitelisted</span>
                </div>

                <!-- Open -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Open Access</span>
                        <span class="inline-flex h-2 w-2 rounded-full" :class="stats.open > 0 ? 'bg-amber-500' : 'bg-emerald-500'"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
                        {{ stats.open }}
                    </p>
                    <span class="text-[11px] text-zinc-500">0.0.0.0/0 (Global)</span>
                </div>

                <!-- Standard Ports -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Standard Ports</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-cyan-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-cyan-600 dark:text-cyan-400">
                        {{ stats.standardPorts }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Default MikroTik</span>
                </div>
            </div>

            <!-- Main Card -->
            <div class="flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">IP Service List</span>
                        <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            /ip/service
                        </span>
                        <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ sortedServices.length }} of {{ services.length }}
                        </span>
                    </div>

                    <!-- Filter pills -->
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
                            Enabled ({{ stats.enabled }})
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
                            placeholder="Cari service, port, IP..."
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
                            {{ selectedIds.length }} service dipilih
                        </span>
                    </div>

                    <div v-if="can.manage" class="flex flex-wrap items-center gap-1.5">
                        <Btn size="sm" :disabled="isBatchOperating" @click="runBatch('enable')">
                            ✓ Enable
                        </Btn>
                        <Btn size="sm" :disabled="isBatchOperating" @click="runBatch('disable')">
                            ✕ Disable
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
                    <table class="w-full text-left text-xs">
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
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('name')">
                                    <div class="flex items-center gap-1">
                                        <span>Service Name</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'name' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('port')">
                                    <div class="flex items-center gap-1">
                                        <span>Port</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'port' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('address')">
                                    <div class="flex items-center gap-1">
                                        <span>Available From (Allowed Subnet)</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'address' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('status')">
                                    <div class="flex items-center gap-1">
                                        <span>Status</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'status' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="(s, idx) in paginatedServices"
                                :key="s['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedIds.includes(s['.id']) && 'bg-indigo-100/60 dark:bg-indigo-950/40',
                                    s.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="toggleSelectItem(s['.id'])"
                                @dblclick="can.manage && openEdit(s)"
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
                                            title="Copy Name"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(s.name, 'name')"
                                        >
                                            <span v-if="copiedField === `name:${s.name}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-semibold text-indigo-700 dark:text-indigo-400">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ s.port }}</span>
                                        <button
                                            type="button"
                                            title="Copy Port"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(s.port, 'port')"
                                        >
                                            <span v-if="copiedField === `port:${s.port}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">
                                    <span v-if="s.address && s.address.trim()" class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">
                                        {{ s.address }}
                                    </span>
                                    <span v-else class="text-zinc-400 italic font-sans text-[11px]">
                                        0.0.0.0/0 (semua jaringan)
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-sans">
                                    <span v-if="s.disabled === 'true'" class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-500 dark:bg-zinc-800">
                                        disabled
                                    </span>
                                    <span v-else class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400">
                                        enabled
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right font-sans" @click.stop>
                                    <div v-if="can.manage" class="flex items-center justify-end gap-1">
                                        <Btn
                                            size="sm"
                                            :variant="s.disabled === 'true' ? 'primary' : 'ghost'"
                                            @click="toggleService(s, s.disabled !== 'true')"
                                        >
                                            {{ s.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" title="Edit Port" @click="openEdit(s)">
                                            Edit
                                        </Btn>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="paginatedServices.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    Tidak ada IP service yang cocok dengan pencarian.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Pagination & Range Bar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-4">
                        <span>
                            Menampilkan <strong>{{ sortedServices.length === 0 ? 0 : (currentPage - 1) * (perPage === 'all' ? sortedServices.length : perPage) + 1 }}</strong> - <strong>{{ perPage === 'all' ? sortedServices.length : Math.min(currentPage * perPage, sortedServices.length) }}</strong> dari <strong>{{ sortedServices.length }}</strong> service
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

            <!-- Modal: Edit IP Service -->
            <Modal :show="showEditModal" :title="`Edit Service: ${form.name}`" @close="showEditModal = false">
                <form class="space-y-3" @submit.prevent="saveService">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Port</label>
                        <input
                            v-model.number="form.port"
                            type="number"
                            required
                            min="1"
                            max="65535"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Available From (Allowed IP/Subnet)</label>
                        <input
                            v-model="form.address"
                            type="text"
                            placeholder="Leave blank for any, or e.g. 192.168.88.0/24"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Btn type="button" variant="secondary" @click="showEditModal = false">Cancel</Btn>
                        <Btn type="submit" variant="primary" :disabled="form.processing">Save Changes</Btn>
                    </div>
                </form>
            </Modal>
        </div>
    </RouterWorkspace>
</template>
