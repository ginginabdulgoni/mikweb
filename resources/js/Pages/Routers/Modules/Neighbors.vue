<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';

interface NeighborEntry {
    '.id'?: string;
    interface: string;
    'mac-address': string;
    address?: string;
    identity?: string;
    platform?: string;
    version?: string;
    board?: string;
    uptime?: string;
    age?: string;
}

const props = defineProps<{
    router: any;
    neighbors: NeighborEntry[];
    deviceError: string | null;
}>();

const search = ref('');
const platformFilter = ref<'all' | 'mikrotik' | 'other'>('all');

// Sorting & Pagination
const sortColumn = ref<string>('interface');
const sortDirection = ref<'asc' | 'desc'>('asc');
const currentPage = ref(1);
const perPage = ref<number | 'all'>(25);

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
    const list = props.neighbors || [];
    const total = list.length;
    const mikrotikCount = list.filter((n) => (n.platform ?? '').toLowerCase().includes('mikrotik') || (n.version ?? '').toLowerCase().includes('routeros')).length;
    const otherCount = total - mikrotikCount;
    const uniqueIfaces = new Set(list.map((n) => n.interface).filter(Boolean)).size;
    const withIpCount = list.filter((n) => Boolean(n.address && n.address.trim())).length;
    const uniqueBoards = new Set(list.map((n) => n.board).filter(Boolean)).size;

    return {
        total,
        mikrotik: mikrotikCount,
        other: otherCount,
        interfaces: uniqueIfaces,
        withIp: withIpCount,
        boards: uniqueBoards,
    };
});

// Filtered & Sorted
const filteredNeighbors = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = props.neighbors || [];
    return list.filter((n) => {
        const isMikroTik = (n.platform ?? '').toLowerCase().includes('mikrotik') || (n.version ?? '').toLowerCase().includes('routeros');
        if (platformFilter.value === 'mikrotik' && !isMikroTik) return false;
        if (platformFilter.value === 'other' && isMikroTik) return false;

        if (!q) return true;
        return (
            n.interface.toLowerCase().includes(q) ||
            n['mac-address'].toLowerCase().includes(q) ||
            (n.address ?? '').toLowerCase().includes(q) ||
            (n.identity ?? '').toLowerCase().includes(q) ||
            (n.platform ?? '').toLowerCase().includes(q) ||
            (n.version ?? '').toLowerCase().includes(q) ||
            (n.board ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedNeighbors = computed(() => {
    const list = [...filteredNeighbors.value];
    const col = sortColumn.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (col === 'interface') return a.interface.localeCompare(b.interface) * dir;
        if (col === 'mac') return a['mac-address'].localeCompare(b['mac-address']) * dir;
        if (col === 'address') return (a.address ?? '').localeCompare(b.address ?? '') * dir;
        if (col === 'identity') return (a.identity ?? '').localeCompare(b.identity ?? '') * dir;
        if (col === 'platform') return (a.platform ?? '').localeCompare(b.platform ?? '') * dir;
        if (col === 'version') return (a.version ?? '').localeCompare(b.version ?? '') * dir;
        if (col === 'board') return (a.board ?? '').localeCompare(b.board ?? '') * dir;
        if (col === 'uptime') return (a.uptime ?? '').localeCompare(b.uptime ?? '') * dir;
        return 0;
    });
});

// Pagination
const totalPages = computed(() => {
    if (perPage.value === 'all') return 1;
    return Math.max(1, Math.ceil(sortedNeighbors.value.length / perPage.value));
});

const paginatedNeighbors = computed(() => {
    if (perPage.value === 'all') return sortedNeighbors.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedNeighbors.value.slice(start, start + perPage.value);
});

watch([search, platformFilter, perPage], () => {
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

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// CSV Export
function exportCsv() {
    const rows = sortedNeighbors.value.map((n, idx) => ({
        '#': idx + 1,
        Interface: n.interface,
        'MAC Address': n['mac-address'],
        'IP Address': n.address ?? '',
        Identity: n.identity ?? '',
        Platform: n.platform ?? '',
        Version: n.version ?? '',
        Board: n.board ?? '',
        Uptime: n.uptime ?? '',
    }));

    if (rows.length === 0) return;
    const headers = Object.keys(rows[0]!).join(',');
    const csvContent = [headers, ...rows.map((r) => Object.values(r).map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))].join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `neighbors-${props.router.name || 'router'}-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
}
</script>

<template>
    <Head :title="`Neighbors — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- 6 KPI Stat Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Neighbors -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total Devices</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        {{ stats.total }}
                    </p>
                    <span class="text-[11px] text-zinc-500">MNDP / CDP / LLDP</span>
                </div>

                <!-- MikroTik Devices -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">MikroTik</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                        {{ stats.mikrotik }}
                    </p>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400">RouterOS peers</span>
                </div>

                <!-- Other Vendors -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Other Vendors</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-amber-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
                        {{ stats.other }}
                    </p>
                    <span class="text-[11px] text-amber-600 dark:text-amber-400">Switches / APs</span>
                </div>

                <!-- Active Interfaces -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Interfaces</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                        {{ stats.interfaces }}
                    </p>
                    <span class="text-[11px] text-indigo-600 dark:text-indigo-400">Port pendeteksi</span>
                </div>

                <!-- Devices with IP -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">With IP</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-cyan-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-cyan-600 dark:text-cyan-400">
                        {{ stats.withIp }}
                    </p>
                    <span class="text-[11px] text-zinc-500">IP Layer 3</span>
                </div>

                <!-- Board Types -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Hardware Models</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-violet-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-violet-600 dark:text-violet-400">
                        {{ stats.boards }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Tipe perangkat</span>
                </div>
            </div>

            <!-- Main Card -->
            <div class="flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Discovered Neighbors</span>
                        <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            /ip/neighbor
                        </span>
                        <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ sortedNeighbors.length }} of {{ neighbors.length }}
                        </span>
                    </div>

                    <!-- Filter pills -->
                    <div class="flex items-center rounded-lg border border-zinc-200 bg-white p-0.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                        <button
                            type="button"
                            class="rounded px-2.5 py-1 transition-colors"
                            :class="platformFilter === 'all' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="platformFilter = 'all'"
                        >
                            All
                        </button>
                        <button
                            type="button"
                            class="rounded px-2.5 py-1 transition-colors"
                            :class="platformFilter === 'mikrotik' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="platformFilter = 'mikrotik'"
                        >
                            MikroTik ({{ stats.mikrotik }})
                        </button>
                        <button
                            type="button"
                            class="rounded px-2.5 py-1 transition-colors"
                            :class="platformFilter === 'other' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="platformFilter = 'other'"
                        >
                            Other Vendors ({{ stats.other }})
                        </button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh Discovery
                        </Btn>
                        <Btn size="sm" variant="ghost" title="Export CSV" @click="exportCsv">
                            ⤓ Export CSV
                        </Btn>
                    </div>

                    <div class="relative">
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Cari interface, MAC, IP, identity..."
                            class="w-48 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 pl-8 text-xs placeholder-zinc-400 transition-all focus:w-64 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                        <span class="pointer-events-none absolute left-2.5 top-1.5 text-xs text-zinc-400">⌕</span>
                    </div>
                </div>

                <!-- Table -->
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left font-mono text-xs">
                        <thead class="sticky top-0 bg-zinc-100 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-2 py-2 text-center font-sans">#</th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('interface')">
                                    <div class="flex items-center gap-1">
                                        <span>Interface</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'interface' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('mac')">
                                    <div class="flex items-center gap-1">
                                        <span>MAC Address</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'mac' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('address')">
                                    <div class="flex items-center gap-1">
                                        <span>IP Address</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'address' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('identity')">
                                    <div class="flex items-center gap-1">
                                        <span>Identity</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'identity' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('platform')">
                                    <div class="flex items-center gap-1">
                                        <span>Platform</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'platform' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('version')">
                                    <div class="flex items-center gap-1">
                                        <span>Version</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'version' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('board')">
                                    <div class="flex items-center gap-1">
                                        <span>Board</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'board' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('uptime')">
                                    <div class="flex items-center gap-1">
                                        <span>Uptime</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'uptime' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-900">
                            <tr
                                v-for="(n, idx) in paginatedNeighbors"
                                :key="n['.id'] || n['mac-address'] || idx"
                                class="hover:bg-indigo-50/50 dark:hover:bg-indigo-950/30"
                            >
                                <td class="px-2 py-2 text-center font-sans text-zinc-400">
                                    {{ (currentPage - 1) * (perPage === 'all' ? 0 : perPage) + idx + 1 }}
                                </td>
                                <td class="px-3 py-2 font-bold text-zinc-800 dark:text-zinc-200">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] font-mono dark:bg-zinc-800">
                                        {{ n.interface }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-700 dark:text-zinc-300">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ n['mac-address'] }}</span>
                                        <button
                                            type="button"
                                            title="Copy MAC"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(n['mac-address'], 'mac')"
                                        >
                                            <span v-if="copiedField === `mac:${n['mac-address']}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-indigo-600 dark:text-indigo-400">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ n.address || '-' }}</span>
                                        <button
                                            v-if="n.address"
                                            type="button"
                                            title="Copy IP"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(n.address, 'ip')"
                                        >
                                            <span v-if="copiedField === `ip:${n.address}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-sans font-bold text-emerald-700 dark:text-emerald-400">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ n.identity || '-' }}</span>
                                        <button
                                            v-if="n.identity"
                                            type="button"
                                            title="Copy Identity"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(n.identity, 'id')"
                                        >
                                            <span v-if="copiedField === `id:${n.identity}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ n.platform || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ n.version || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ n.board || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ n.uptime || '-' }}</td>
                            </tr>
                            <tr v-if="paginatedNeighbors.length === 0">
                                <td colspan="9" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No neighbor devices discovered on local network segments.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Pagination & Range Bar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-4">
                        <span>
                            Menampilkan <strong>{{ sortedNeighbors.length === 0 ? 0 : (currentPage - 1) * (perPage === 'all' ? sortedNeighbors.length : perPage) + 1 }}</strong> - <strong>{{ perPage === 'all' ? sortedNeighbors.length : Math.min(currentPage * perPage, sortedNeighbors.length) }}</strong> dari <strong>{{ sortedNeighbors.length }}</strong> tetangga jaringan
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
        </div>
    </RouterWorkspace>
</template>
