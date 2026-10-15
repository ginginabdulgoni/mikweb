<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';

interface LogEntry {
    '.id'?: string;
    time: string;
    topics: string;
    message: string;
}

const props = defineProps<{
    router: any;
    logs: LogEntry[];
    deviceError: string | null;
}>();

const search = ref('');
const topicFilter = ref<'all' | 'system' | 'firewall' | 'ppp' | 'hotspot' | 'dhcp' | 'warning_error'>('all');

// Sorting & Pagination
const sortColumn = ref<string>('time');
const sortDirection = ref<'asc' | 'desc'>('desc');
const currentPage = ref(1);
const perPage = ref<number | 'all'>(50);

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
    const list = props.logs || [];
    const total = list.length;
    const errorsCount = list.filter((l) => {
        const t = (l.topics ?? '').toLowerCase();
        return t.includes('error') || t.includes('critical');
    }).length;
    const warningsCount = list.filter((l) => (l.topics ?? '').toLowerCase().includes('warning')).length;
    const systemCount = list.filter((l) => (l.topics ?? '').toLowerCase().includes('system') || (l.topics ?? '').toLowerCase().includes('account')).length;
    const networkCount = list.filter((l) => {
        const t = (l.topics ?? '').toLowerCase();
        return t.includes('dhcp') || t.includes('ppp') || t.includes('hotspot');
    }).length;
    const firewallCount = list.filter((l) => (l.topics ?? '').toLowerCase().includes('firewall')).length;

    return {
        total,
        errors: errorsCount,
        warnings: warningsCount,
        system: systemCount,
        network: networkCount,
        firewall: firewallCount,
    };
});

// Filtered & Sorted
const filteredLogs = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = props.logs || [];
    return list.filter((item) => {
        const top = (item.topics ?? '').toLowerCase();

        if (topicFilter.value === 'system' && !top.includes('system') && !top.includes('account')) return false;
        if (topicFilter.value === 'firewall' && !top.includes('firewall')) return false;
        if (topicFilter.value === 'ppp' && !top.includes('ppp')) return false;
        if (topicFilter.value === 'hotspot' && !top.includes('hotspot')) return false;
        if (topicFilter.value === 'dhcp' && !top.includes('dhcp')) return false;
        if (topicFilter.value === 'warning_error' && !top.includes('error') && !top.includes('warning') && !top.includes('critical')) return false;

        if (!q) return true;
        return (
            item.time.toLowerCase().includes(q) ||
            top.includes(q) ||
            item.message.toLowerCase().includes(q)
        );
    });
});

const sortedLogs = computed(() => {
    const list = [...filteredLogs.value];
    const col = sortColumn.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (col === 'time') return a.time.localeCompare(b.time) * dir;
        if (col === 'topics') return a.topics.localeCompare(b.topics) * dir;
        if (col === 'message') return a.message.localeCompare(b.message) * dir;
        return 0;
    });
});

// Pagination
const totalPages = computed(() => {
    if (perPage.value === 'all') return 1;
    return Math.max(1, Math.ceil(sortedLogs.value.length / perPage.value));
});

const paginatedLogs = computed(() => {
    if (perPage.value === 'all') return sortedLogs.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedLogs.value.slice(start, start + perPage.value);
});

watch([search, topicFilter, perPage], () => {
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

function getTopicBadgeClass(topics: string): string {
    const t = topics.toLowerCase();
    if (t.includes('error') || t.includes('critical')) {
        return 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300 border-red-200 dark:border-red-900';
    }
    if (t.includes('warning')) {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border-amber-200 dark:border-amber-900';
    }
    if (t.includes('firewall')) {
        return 'bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300 border-purple-200 dark:border-purple-900';
    }
    if (t.includes('pppoe') || t.includes('ppp') || t.includes('hotspot')) {
        return 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border-blue-200 dark:border-blue-900';
    }
    if (t.includes('dhcp')) {
        return 'bg-cyan-100 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-300 border-cyan-200 dark:border-cyan-900';
    }
    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 border-zinc-200 dark:border-zinc-700';
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// CSV Export
function exportCsv() {
    const rows = sortedLogs.value.map((l, idx) => ({
        '#': idx + 1,
        Time: l.time,
        Topics: l.topics,
        Message: l.message,
    }));

    if (rows.length === 0) return;
    const headers = Object.keys(rows[0]!).join(',');
    const csvContent = [headers, ...rows.map((r) => Object.values(r).map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))].join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `router-logs-${props.router.name || 'router'}-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
}
</script>

<template>
    <Head :title="`Log — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- 6 KPI Stat Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Logs -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total Buffer</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-blue-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        {{ stats.total }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Buffer memori</span>
                </div>

                <!-- Errors & Critical -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Errors</span>
                        <span class="inline-flex h-2 w-2 rounded-full" :class="stats.errors > 0 ? 'bg-red-500' : 'bg-zinc-300'"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-red-600 dark:text-red-400">
                        {{ stats.errors }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Critical / Failure</span>
                </div>

                <!-- Warnings -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Warnings</span>
                        <span class="inline-flex h-2 w-2 rounded-full" :class="stats.warnings > 0 ? 'bg-amber-500' : 'bg-zinc-300'"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
                        {{ stats.warnings }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Perlu perhatian</span>
                </div>

                <!-- System & Auth -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">System</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-emerald-600 dark:text-emerald-400">
                        {{ stats.system }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Boot & Login</span>
                </div>

                <!-- Network Services -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Clients</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-indigo-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-indigo-600 dark:text-indigo-400">
                        {{ stats.network }}
                    </p>
                    <span class="text-[11px] text-zinc-500">DHCP, PPP, Hotspot</span>
                </div>

                <!-- Firewall Logs -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Firewall</span>
                        <span class="inline-flex h-2 w-2 rounded-full bg-purple-500"></span>
                    </div>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-purple-600 dark:text-purple-400">
                        {{ stats.firewall }}
                    </p>
                    <span class="text-[11px] text-zinc-500">Rule filter log</span>
                </div>
            </div>

            <!-- Main Card -->
            <div class="flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">System Logs</span>
                        <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            /log
                        </span>
                        <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ sortedLogs.length }} of {{ logs.length }}
                        </span>
                    </div>

                    <!-- Filter pills -->
                    <div class="flex flex-wrap items-center rounded-lg border border-zinc-200 bg-white p-0.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                        <button
                            type="button"
                            class="rounded px-2 py-0.5 transition-colors"
                            :class="topicFilter === 'all' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="topicFilter = 'all'"
                        >
                            All
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-0.5 transition-colors"
                            :class="topicFilter === 'system' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="topicFilter = 'system'"
                        >
                            System ({{ stats.system }})
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-0.5 transition-colors"
                            :class="topicFilter === 'firewall' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="topicFilter = 'firewall'"
                        >
                            Firewall ({{ stats.firewall }})
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-0.5 transition-colors"
                            :class="topicFilter === 'ppp' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="topicFilter = 'ppp'"
                        >
                            PPP
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-0.5 transition-colors"
                            :class="topicFilter === 'hotspot' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="topicFilter = 'hotspot'"
                        >
                            Hotspot
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-0.5 transition-colors"
                            :class="topicFilter === 'dhcp' ? 'bg-indigo-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="topicFilter = 'dhcp'"
                        >
                            DHCP
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-0.5 transition-colors"
                            :class="topicFilter === 'warning_error' ? 'bg-red-600 text-white font-medium shadow-xs' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="topicFilter = 'warning_error'"
                        >
                            Errors / Warnings
                        </button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh Logs
                        </Btn>
                        <Btn size="sm" variant="ghost" title="Export CSV" @click="exportCsv">
                            ⤓ Export CSV
                        </Btn>
                    </div>

                    <div class="relative">
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Cari pesan log, topic, waktu..."
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
                                <th class="w-36 cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('time')">
                                    <div class="flex items-center gap-1">
                                        <span>Time</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'time' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="w-44 cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('topics')">
                                    <div class="flex items-center gap-1">
                                        <span>Topics</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'topics' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-100" @click="toggleSort('message')">
                                    <div class="flex items-center gap-1">
                                        <span>Message</span>
                                        <span class="text-[10px] text-zinc-400">{{ sortColumn === 'message' ? (sortDirection === 'asc' ? '▲' : '▼') : '↕' }}</span>
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-900">
                            <tr
                                v-for="(log, idx) in paginatedLogs"
                                :key="log['.id'] || idx"
                                class="hover:bg-indigo-50/50 dark:hover:bg-indigo-950/30"
                            >
                                <td class="px-2 py-2 text-center font-sans text-zinc-400">
                                    {{ (currentPage - 1) * (perPage === 'all' ? 0 : perPage) + idx + 1 }}
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400 whitespace-nowrap">
                                    <div class="flex items-center gap-1">
                                        <span>{{ log.time }}</span>
                                        <button
                                            type="button"
                                            title="Copy Time"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                            @click.stop="copyToClipboard(log.time, 'time')"
                                        >
                                            <span v-if="copiedField === `time:${log.time}`" class="text-[10px] font-sans font-medium text-emerald-500">✓</span>
                                            <span v-else class="text-[10px]">📋</span>
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <span
                                        class="inline-block rounded border px-1.5 py-0.5 text-[10px] font-medium"
                                        :class="getTopicBadgeClass(log.topics)"
                                    >
                                        {{ log.topics }}
                                    </span>
                                </td>
                                <td
                                    class="px-3 py-2 text-zinc-800 dark:text-zinc-200"
                                    :class="(log.topics.includes('error') || log.topics.includes('critical')) ? 'text-red-700 font-semibold dark:text-red-400' : ''"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <span>{{ log.message }}</span>
                                        <button
                                            type="button"
                                            title="Copy Log Message"
                                            class="text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400 shrink-0"
                                            @click.stop="copyToClipboard(log.message, 'msg')"
                                        >
                                            <span v-if="copiedField === `msg:${log.message}`" class="text-[10px] font-sans font-medium text-emerald-500">Copied!</span>
                                            <span v-else class="text-xs">📋</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="paginatedLogs.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No log entries match the current filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Pagination & Range Bar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-4">
                        <span>
                            Menampilkan <strong>{{ sortedLogs.length === 0 ? 0 : (currentPage - 1) * (perPage === 'all' ? sortedLogs.length : perPage) + 1 }}</strong> - <strong>{{ perPage === 'all' ? sortedLogs.length : Math.min(currentPage * perPage, sortedLogs.length) }}</strong> dari <strong>{{ sortedLogs.length }}</strong> log
                        </span>
                    </div>

                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs">Baris:</span>
                            <select
                                v-model="perPage"
                                class="rounded-lg border border-zinc-200 bg-white px-2 py-1 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="200">200</option>
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
