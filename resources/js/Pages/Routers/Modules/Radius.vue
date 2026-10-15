<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface RadiusServer {
    '.id': string;
    service: string;
    address: string;
    secret?: string;
    'authentication-port'?: string;
    'accounting-port'?: string;
    timeout?: string;
    disabled?: string;
    comment?: string;
}

const props = defineProps<{
    router: any;
    servers: RadiusServer[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

const selectedId = ref<string | null>(null);
const search = ref('');
const serviceFilter = ref('all');
const statusFilter = ref<'all' | 'enabled' | 'disabled'>('all');

// ── Multi Selection & Batch Bar ──────────────────────────────────────────
const selectedServerIds = ref<string[]>([]);
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

// ── Sorting ─────────────────────────────────────────────────────────────
type SortField = 'address' | 'service' | 'authentication-port' | 'accounting-port' | 'timeout' | 'disabled';
const sortField = ref<SortField>('address');
const sortDirection = ref<'asc' | 'desc'>('asc');

function toggleSort(field: SortField) {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDirection.value = 'asc';
    }
}

function ipToNum(ip: string): number {
    const clean = ip.split('/')[0].trim();
    const parts = clean.split('.').map((p) => parseInt(p, 10));
    if (parts.length !== 4 || parts.some(isNaN)) return 0;
    return ((parts[0] << 24) >>> 0) + ((parts[1] << 16) >>> 0) + ((parts[2] << 8) >>> 0) + (parts[3] >>> 0);
}

// ── Pagination ───────────────────────────────────────────────────────────
const perPage = ref<number>(25);
const currentPage = ref<number>(1);

// ── Modals & Forms ───────────────────────────────────────────────────────
const showAddModal = ref(false);
const showEditModal = ref(false);

const form = useForm({
    id: '',
    service: 'ppp,hotspot',
    address: '',
    secret: '',
    authentication_port: 1812,
    accounting_port: 1813,
    timeout: '300ms',
    comment: '',
});

const serverList = computed(() => props.servers ?? []);

// ── KPI Statistics ───────────────────────────────────────────────────────
const kpiStats = computed(() => {
    const list = serverList.value;
    return {
        total: list.length,
        active: list.filter((s) => s.disabled !== 'true').length,
        ppp: list.filter((s) => (s.service || '').toLowerCase().includes('ppp')).length,
        hotspot: list.filter((s) => (s.service || '').toLowerCase().includes('hotspot')).length,
        login: list.filter((s) => (s.service || '').toLowerCase().includes('login')).length,
        disabled: list.filter((s) => s.disabled === 'true').length,
    };
});

// ── Filtered & Sorted Servers ────────────────────────────────────────────
const filteredServers = computed(() => {
    const q = search.value.trim().toLowerCase();
    const svc = serviceFilter.value;
    const st = statusFilter.value;

    return serverList.value.filter((s) => {
        if (svc !== 'all' && !(s.service || '').toLowerCase().includes(svc)) return false;
        if (st === 'enabled' && s.disabled === 'true') return false;
        if (st === 'disabled' && s.disabled !== 'true') return false;
        if (!q) return true;
        return (
            s.service.toLowerCase().includes(q) ||
            s.address.toLowerCase().includes(q) ||
            (s['authentication-port'] ?? '').toLowerCase().includes(q) ||
            (s['accounting-port'] ?? '').toLowerCase().includes(q) ||
            (s.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedServers = computed(() => {
    const list = [...filteredServers.value];
    const f = sortField.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (f === 'address') {
            const numA = ipToNum(a.address);
            const numB = ipToNum(b.address);
            if (numA !== 0 && numB !== 0) {
                return (numA - numB) * dir;
            }
        }
        const valA = (a[f] ?? '').toLowerCase();
        const valB = (b[f] ?? '').toLowerCase();
        return valA.localeCompare(valB) * dir;
    });
});

const totalPages = computed(() => {
    if (perPage.value <= 0) return 1;
    return Math.max(1, Math.ceil(sortedServers.value.length / perPage.value));
});

const paginatedServers = computed(() => {
    if (perPage.value <= 0) return sortedServers.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedServers.value.slice(start, start + perPage.value);
});

// ── Multi Selection Controls ─────────────────────────────────────────────
const isAllSelected = computed(() => {
    if (sortedServers.value.length === 0) return false;
    return sortedServers.value.every((s) => selectedServerIds.value.includes(s['.id']));
});

function toggleSelectAll() {
    if (isAllSelected.value) {
        selectedServerIds.value = [];
    } else {
        selectedServerIds.value = sortedServers.value.map((s) => s['.id']);
    }
}

function toggleSelect(id: string) {
    const idx = selectedServerIds.value.indexOf(id);
    if (idx >= 0) {
        selectedServerIds.value.splice(idx, 1);
    } else {
        selectedServerIds.value.push(id);
    }
}

// ── Batch Operations ─────────────────────────────────────────────────────
function executeBatch(action: 'enable' | 'disable' | 'destroy') {
    if (selectedServerIds.value.length === 0) return;
    if (action === 'destroy' && !confirm(`Hapus permanen ${selectedServerIds.value.length} RADIUS server yang dipilih?`)) {
        return;
    }

    batchLoading.value = true;
    inertia.post(
        route('routers.radius.batch', props.router.id),
        {
            action,
            ids: selectedServerIds.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                batchLoading.value = false;
                selectedServerIds.value = [];
            },
        },
    );
}

// ── Single Operations ────────────────────────────────────────────────────
function openAdd() {
    form.reset();
    form.id = '';
    form.service = 'ppp,hotspot';
    form.authentication_port = 1812;
    form.accounting_port = 1813;
    form.timeout = '300ms';
    showAddModal.value = true;
}

function openEdit(server: RadiusServer) {
    form.id = server['.id'];
    form.service = server.service;
    form.address = server.address;
    form.secret = '';
    form.authentication_port = server['authentication-port'] ? parseInt(server['authentication-port'], 10) : 1812;
    form.accounting_port = server['accounting-port'] ? parseInt(server['accounting-port'], 10) : 1813;
    form.timeout = server.timeout || '300ms';
    form.comment = server.comment || '';
    showEditModal.value = true;
}

function saveServer() {
    form.post(route('routers.radius.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddModal.value = false;
            form.reset();
        },
    });
}

function updateServer() {
    form.put(route('routers.radius.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            form.reset();
        },
    });
}

function toggleServer(server: RadiusServer, disable: boolean) {
    inertia.post(
        route('routers.radius.toggle', props.router.id),
        { id: server['.id'], disable },
        { preserveScroll: true },
    );
}

function removeSingle(server: RadiusServer) {
    if (!confirm(`Hapus RADIUS server "${server.address}"?`)) return;

    inertia.delete(route('routers.radius.destroy', props.router.id), {
        data: { id: server['.id'] },
        preserveScroll: true,
        onSuccess: () => {
            selectedId.value = null;
        },
    });
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// ── CSV Export ───────────────────────────────────────────────────────────
function exportCsv() {
    const rows = sortedServers.value.map((s) => [
        s.address,
        s.service,
        s['authentication-port'] || '1812',
        s['accounting-port'] || '1813',
        s.timeout || '300ms',
        s.disabled === 'true' ? 'disabled' : 'enabled',
        s.comment || '',
    ]);
    const headers = ['Address', 'Services', 'Auth Port', 'Acct Port', 'Timeout', 'Status', 'Comment'];
    const csvContent =
        'data:text/csv;charset=utf-8,' +
        [headers.join(','), ...rows.map((e) => e.map((val) => `"${(val || '').replace(/"/g, '""')}"`).join(','))].join(
            '\n',
        );

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `radius_servers_${props.router.name}_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<template>
    <Head :title="`RADIUS AAA — ${router.name}`" />

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
                    <div class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">Total Servers</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ kpiStats.total }}</div>
                    <div class="mt-1 text-[10px] text-zinc-400">AAA endpoints</div>
                </div>

                <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <div class="text-[11px] font-medium text-emerald-700 dark:text-emerald-400">Active Servers</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-emerald-700 dark:text-emerald-300">{{ kpiStats.active }}</div>
                    <div class="mt-1 text-[10px] text-emerald-600/70 dark:text-emerald-400/60">Aktif melayani auth</div>
                </div>

                <div class="rounded-xl border border-indigo-200 bg-indigo-50/40 p-3 shadow-xs dark:border-indigo-900/40 dark:bg-indigo-950/20">
                    <div class="text-[11px] font-medium text-indigo-700 dark:text-indigo-400">PPP AAA</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-indigo-700 dark:text-indigo-300">{{ kpiStats.ppp }}</div>
                    <div class="mt-1 text-[10px] text-indigo-600/70 dark:text-indigo-400/60">PPPoE / VPN AAA</div>
                </div>

                <div class="rounded-xl border border-amber-200 bg-amber-50/40 p-3 shadow-xs dark:border-amber-900/40 dark:bg-amber-950/20">
                    <div class="text-[11px] font-medium text-amber-700 dark:text-amber-400">Hotspot AAA</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-amber-700 dark:text-amber-300">{{ kpiStats.hotspot }}</div>
                    <div class="mt-1 text-[10px] text-amber-600/70 dark:text-amber-400/60">Voucher / captive portal</div>
                </div>

                <div class="rounded-xl border border-cyan-200 bg-cyan-50/40 p-3 shadow-xs dark:border-cyan-900/40 dark:bg-cyan-950/20">
                    <div class="text-[11px] font-medium text-cyan-700 dark:text-cyan-400">Admin Login AAA</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-cyan-700 dark:text-cyan-300">{{ kpiStats.login }}</div>
                    <div class="mt-1 text-[10px] text-cyan-600/70 dark:text-cyan-400/60">Console / Winbox auth</div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-zinc-50/60 p-3 shadow-xs dark:border-zinc-800 dark:bg-zinc-900/50">
                    <div class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">Disabled</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-zinc-600 dark:text-zinc-400">{{ kpiStats.disabled }}</div>
                    <div class="mt-1 text-[10px] text-zinc-400">Server dinonaktifkan</div>
                </div>
            </div>

            <!-- Main Panel -->
            <div class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">RADIUS AAA Servers</span>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /radius
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage" size="sm" variant="primary" class="gap-1.5" @click="openAdd">
                            <span>＋</span> Tambah Server
                        </Btn>

                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh
                        </Btn>

                        <Btn size="sm" variant="secondary" title="Export CSV" @click="exportCsv">
                            ⬇ Export CSV
                        </Btn>

                        <div class="mx-1 h-4 w-px bg-zinc-300 dark:bg-zinc-700"></div>

                        <!-- Service Filter -->
                        <div class="flex items-center gap-1 text-xs text-zinc-500">
                            <span>Service:</span>
                            <select
                                v-model="serviceFilter"
                                class="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <option value="all">Semua Service</option>
                                <option value="ppp">PPP</option>
                                <option value="hotspot">Hotspot</option>
                                <option value="login">Login</option>
                                <option value="wireless">Wireless</option>
                            </select>
                        </div>

                        <!-- Status Filter -->
                        <div class="flex items-center gap-1 text-xs text-zinc-500">
                            <span>Status:</span>
                            <select
                                v-model="statusFilter"
                                class="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <option value="all">Semua Status</option>
                                <option value="enabled">Aktif</option>
                                <option value="disabled">Nonaktif</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari server, ip, service..."
                            class="w-52 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>

                <!-- Floating Sticky Batch Action Bar -->
                <transition
                    enter-active-class="transition duration-150 ease-out"
                    enter-from-class="-translate-y-2 opacity-0"
                    enter-to-class="translate-y-0 opacity-100"
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="translate-y-0 opacity-100"
                    leave-to-class="-translate-y-2 opacity-0"
                >
                    <div
                        v-if="selectedServerIds.length > 0"
                        class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-200 bg-indigo-50 px-4 py-2 dark:border-indigo-900/50 dark:bg-indigo-950/40"
                    >
                        <div class="flex items-center gap-2 text-xs font-medium text-indigo-900 dark:text-indigo-200">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">
                                {{ selectedServerIds.length }}
                            </span>
                            <span>server dipilih</span>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="batchLoading"
                                @click="executeBatch('enable')"
                            >
                                ✓ Aktifkan
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="batchLoading"
                                @click="executeBatch('disable')"
                            >
                                ✕ Nonaktifkan
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                class="text-rose-600 hover:bg-rose-100 dark:text-rose-400 dark:hover:bg-rose-950/40"
                                :disabled="batchLoading"
                                @click="executeBatch('destroy')"
                            >
                                🗑 Hapus Terpilih
                            </Btn>
                            <button
                                class="ml-2 text-xs text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200"
                                @click="selectedServerIds = []"
                            >
                                Batal
                            </button>
                        </div>
                    </div>
                </transition>

                <!-- Tables -->
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        :checked="isAllSelected"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelectAll"
                                    />
                                </th>
                                <th class="w-8 px-2 py-2 text-center">St</th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('address')">
                                    Server Address
                                    <span v-if="sortField === 'address'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('service')">
                                    Services
                                    <span v-if="sortField === 'service'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('authentication-port')">
                                    Auth Port
                                    <span v-if="sortField === 'authentication-port'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('accounting-port')">
                                    Acct Port
                                    <span v-if="sortField === 'accounting-port'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('timeout')">
                                    Timeout
                                    <span v-if="sortField === 'timeout'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="px-3 py-2">Comment</th>
                                <th v-if="can.manage" class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr v-if="paginatedServers.length === 0">
                                <td :colspan="can.manage ? 9 : 8" class="px-4 py-8 text-center font-sans text-xs text-zinc-500">
                                    Tidak ada RADIUS server yang cocok dengan filter.
                                </td>
                            </tr>
                            <tr
                                v-for="s in paginatedServers"
                                :key="s['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedServerIds.includes(s['.id']) ? 'bg-indigo-100/50 dark:bg-indigo-950/40' : '',
                                    s.disabled === 'true' ? 'opacity-60' : ''
                                ]"
                                @click="selectedId = s['.id']"
                                @dblclick="can.manage && openEdit(s)"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="selectedServerIds.includes(s['.id'])"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelect(s['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2 text-center font-sans">
                                    <StatusDot :color="s.disabled === 'true' ? 'gray' : 'emerald'" />
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-bold text-zinc-900 dark:text-zinc-100" :class="{ 'line-through': s.disabled === 'true' }">
                                            {{ s.address }}
                                        </span>
                                        <button
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin server address"
                                            @click.stop="copyValue(s.address, 'Server Address')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="svc in (s.service || '').split(',')"
                                            :key="svc"
                                            class="rounded px-1.5 py-0.5 text-[10px] font-bold"
                                            :class="
                                                svc.trim() === 'ppp'
                                                    ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300'
                                                    : svc.trim() === 'hotspot'
                                                    ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                                                    : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                                            "
                                        >
                                            {{ svc.trim() }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-700 dark:text-zinc-300">
                                    {{ s['authentication-port'] || '1812' }}
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-700 dark:text-zinc-300">
                                    {{ s['accounting-port'] || '1813' }}
                                </td>
                                <td class="px-3 py-2 text-zinc-500">{{ s.timeout || '300ms' }}</td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ s.comment || '-' }}</td>
                                <td v-if="can.manage" class="px-3 py-2 text-right" @click.stop>
                                    <div class="flex items-center justify-end gap-1 font-sans">
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700"
                                            :title="s.disabled === 'true' ? 'Aktifkan' : 'Nonaktifkan'"
                                            @click="toggleServer(s, s.disabled !== 'true')"
                                        >
                                            {{ s.disabled === 'true' ? '✓' : '✕' }}
                                        </button>
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700"
                                            title="Edit server"
                                            @click="openEdit(s)"
                                        >
                                            ✏
                                        </button>
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-rose-600 hover:bg-rose-100 dark:text-rose-400 dark:hover:bg-rose-950/50"
                                            title="Hapus server"
                                            @click="removeSingle(s)"
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
                            Total: <strong>{{ sortedServers.length }}</strong> server
                        </span>
                        <span v-if="selectedServerIds.length > 0" class="font-medium text-indigo-600 dark:text-indigo-400">
                            ({{ selectedServerIds.length }} dipilih)
                        </span>
                    </div>

                    <!-- Pagination Controls -->
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1 text-[11px]">
                            <span>Baris:</span>
                            <select
                                v-model="perPage"
                                class="rounded border border-zinc-300 bg-white px-1.5 py-0.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                                @change="currentPage = 1"
                            >
                                <option :value="15">15</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="-1">Semua</option>
                            </select>
                        </div>

                        <div v-if="perPage > 0 && totalPages > 1" class="flex items-center gap-1">
                            <button
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs disabled:opacity-40 dark:border-zinc-700"
                                :disabled="currentPage <= 1"
                                @click="currentPage--"
                            >
                                ‹
                            </button>
                            <span class="text-[11px] font-medium text-zinc-700 dark:text-zinc-300">
                                {{ currentPage }} / {{ totalPages }}
                            </span>
                            <button
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs disabled:opacity-40 dark:border-zinc-700"
                                :disabled="currentPage >= totalPages"
                                @click="currentPage++"
                            >
                                ›
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: Add Server -->
            <Modal :show="showAddModal" title="Tambah RADIUS Server" @close="showAddModal = false">
                <form class="space-y-3" @submit.prevent="saveServer">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Server IP Address</label>
                        <input
                            v-model="form.address"
                            type="text"
                            required
                            placeholder="e.g. 192.168.88.250"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Services (pisahkan dengan koma)</label>
                        <input
                            v-model="form.service"
                            type="text"
                            required
                            placeholder="ppp,hotspot,login"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Shared Secret</label>
                        <input
                            v-model="form.secret"
                            type="password"
                            required
                            placeholder="RADIUS Secret"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Authentication Port</label>
                            <input
                                v-model.number="form.authentication_port"
                                type="number"
                                min="1"
                                max="65535"
                                class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Accounting Port</label>
                            <input
                                v-model.number="form.accounting_port"
                                type="number"
                                min="1"
                                max="65535"
                                class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Timeout</label>
                        <input
                            v-model="form.timeout"
                            type="text"
                            placeholder="300ms"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="form.comment"
                            type="text"
                            placeholder="Catatan opsional"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Btn type="button" variant="secondary" @click="showAddModal = false">Batal</Btn>
                        <Btn type="submit" variant="primary" :disabled="form.processing">Simpan Server</Btn>
                    </div>
                </form>
            </Modal>

            <!-- Modal: Edit Server -->
            <Modal :show="showEditModal" :title="`Edit RADIUS: ${form.address}`" @close="showEditModal = false">
                <form class="space-y-3" @submit.prevent="updateServer">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Server IP Address</label>
                        <input
                            v-model="form.address"
                            type="text"
                            required
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Services</label>
                        <input
                            v-model="form.service"
                            type="text"
                            required
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Ganti Secret (kosongkan jika tidak diubah)</label>
                        <input
                            v-model="form.secret"
                            type="password"
                            placeholder="Secret baru"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Authentication Port</label>
                            <input
                                v-model.number="form.authentication_port"
                                type="number"
                                min="1"
                                max="65535"
                                class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Accounting Port</label>
                            <input
                                v-model.number="form.accounting_port"
                                type="number"
                                min="1"
                                max="65535"
                                class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Timeout</label>
                        <input
                            v-model="form.timeout"
                            type="text"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="form.comment"
                            type="text"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Btn type="button" variant="secondary" @click="showEditModal = false">Batal</Btn>
                        <Btn type="submit" variant="primary" :disabled="form.processing">Simpan Perubahan</Btn>
                    </div>
                </form>
            </Modal>
        </div>
    </RouterWorkspace>
</template>
