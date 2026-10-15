<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router as inertia } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';

interface Backup {
    id: number;
    type: string;
    filename: string;
    size: number;
    routeros_version: string | null;
    created_at: string | null;
}

const props = defineProps<{
    router: any;
    backups: Backup[];
    can: { manage: boolean };
}>();

const search = ref('');
const typeFilter = ref('all');

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

// ── Formatting ───────────────────────────────────────────────────────────
function fmt(n: number): string {
    if (isNaN(n) || n <= 0) return '0 B';
    if (n < 1024) return `${n} B`;
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${(n / (1024 * 1024)).toFixed(2)} MB`;
}

// ── Sorting ─────────────────────────────────────────────────────────────
type SortField = 'filename' | 'type' | 'routeros_version' | 'size' | 'created_at';
const sortField = ref<SortField>('created_at');
const sortDirection = ref<'asc' | 'desc'>('desc');

function toggleSort(field: SortField) {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = field;
        sortDirection.value = 'asc';
    }
}

// ── Pagination ───────────────────────────────────────────────────────────
const perPage = ref<number>(25);
const currentPage = ref<number>(1);

const backupList = computed(() => props.backups ?? []);

// ── KPI Statistics ───────────────────────────────────────────────────────
const kpiStats = computed(() => {
    const list = backupList.value;
    const totalBytes = list.reduce((acc, b) => acc + (b.size || 0), 0);
    const latest = list.length > 0 ? list[0].created_at : '-';
    const versions = new Set(list.map((b) => b.routeros_version).filter(Boolean)).size;

    return {
        total: list.length,
        totalSize: fmt(totalBytes),
        rscCount: list.filter((b) => (b.type || '').toLowerCase().includes('rsc') || (b.filename || '').endsWith('.rsc')).length,
        latestDate: latest || '-',
        distinctVersions: versions || 1,
    };
});

// ── Filtered & Sorted Backups ────────────────────────────────────────────
const filteredBackups = computed(() => {
    const q = search.value.trim().toLowerCase();
    const t = typeFilter.value;

    return backupList.value.filter((b) => {
        if (t !== 'all' && (b.type || '').toLowerCase() !== t) return false;
        if (!q) return true;
        return (
            b.filename.toLowerCase().includes(q) ||
            b.type.toLowerCase().includes(q) ||
            (b.routeros_version ?? '').toLowerCase().includes(q) ||
            (b.created_at ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedBackups = computed(() => {
    const list = [...filteredBackups.value];
    const f = sortField.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (f === 'size') {
            return (a.size - b.size) * dir;
        }
        const valA = (a[f] ?? '').toLowerCase();
        const valB = (b[f] ?? '').toLowerCase();
        return valA.localeCompare(valB) * dir;
    });
});

const totalPages = computed(() => {
    if (perPage.value <= 0) return 1;
    return Math.max(1, Math.ceil(sortedBackups.value.length / perPage.value));
});

const paginatedBackups = computed(() => {
    if (perPage.value <= 0) return sortedBackups.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedBackups.value.slice(start, start + perPage.value);
});

// ── Actions ──────────────────────────────────────────────────────────────
const exporting = ref(false);

function exportNow() {
    exporting.value = true;
    inertia.post(
        route('routers.backups.export', props.router.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                exporting.value = false;
            },
        },
    );
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// ── CSV Export ───────────────────────────────────────────────────────────
function exportCsv() {
    const rows = sortedBackups.value.map((b) => [
        b.filename,
        b.type,
        b.routeros_version || '-',
        b.size.toString(),
        fmt(b.size),
        b.created_at || '',
    ]);
    const headers = ['Filename', 'Type', 'RouterOS Version', 'Size (Bytes)', 'Formatted Size', 'Created At'];
    const csvContent =
        'data:text/csv;charset=utf-8,' +
        [headers.join(','), ...rows.map((e) => e.map((val) => `"${(val || '').replace(/"/g, '""')}"`).join(','))].join(
            '\n',
        );

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `backups_${props.router.name}_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<template>
    <Head :title="`Backups — ${router.name}`" />

    <RouterWorkspace :router="router">
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
                    <div class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">Total Backups</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ kpiStats.total }}</div>
                    <div class="mt-1 text-[10px] text-zinc-400">Snapshot tersimpan</div>
                </div>

                <div class="rounded-xl border border-indigo-200 bg-indigo-50/40 p-3 shadow-xs dark:border-indigo-900/40 dark:bg-indigo-950/20">
                    <div class="text-[11px] font-medium text-indigo-700 dark:text-indigo-400">Total Size</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-indigo-700 dark:text-indigo-300 truncate">{{ kpiStats.totalSize }}</div>
                    <div class="mt-1 text-[10px] text-indigo-600/70 dark:text-indigo-400/60">Server storage</div>
                </div>

                <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <div class="text-[11px] font-medium text-emerald-700 dark:text-emerald-400">RSC Exports</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-emerald-700 dark:text-emerald-300">{{ kpiStats.rscCount }}</div>
                    <div class="mt-1 text-[10px] text-emerald-600/70 dark:text-emerald-400/60">Clean text export</div>
                </div>

                <div class="rounded-xl border border-amber-200 bg-amber-50/40 p-3 shadow-xs dark:border-amber-900/40 dark:bg-amber-950/20">
                    <div class="text-[11px] font-medium text-amber-700 dark:text-amber-400">RouterOS Versions</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-amber-700 dark:text-amber-300">{{ kpiStats.distinctVersions }}</div>
                    <div class="mt-1 text-[10px] text-amber-600/70 dark:text-amber-400/60">Versi tercatat</div>
                </div>

                <div class="rounded-xl border border-blue-200 bg-blue-50/40 p-3 shadow-xs dark:border-blue-900/40 dark:bg-blue-950/20">
                    <div class="text-[11px] font-medium text-blue-700 dark:text-blue-400">Latest Backup</div>
                    <div class="mt-1 text-xs font-bold tracking-tight text-blue-700 dark:text-blue-300 truncate">{{ kpiStats.latestDate }}</div>
                    <div class="mt-1 text-[10px] text-blue-600/70 dark:text-blue-400/60">Terakhir dibuat</div>
                </div>

                <div class="rounded-xl border border-cyan-200 bg-cyan-50/40 p-3 shadow-xs dark:border-cyan-900/40 dark:bg-cyan-950/20">
                    <div class="text-[11px] font-medium text-cyan-700 dark:text-cyan-400">Security</div>
                    <div class="mt-1 text-sm font-bold tracking-tight text-cyan-700 dark:text-cyan-300">AES-256</div>
                    <div class="mt-1 text-[10px] text-cyan-600/70 dark:text-cyan-400/60">Encrypted at rest</div>
                </div>
            </div>

            <!-- Main Panel -->
            <div class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Configuration Backups</span>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /export
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage" size="sm" variant="primary" class="gap-1.5" :disabled="exporting" @click="exportNow">
                            <span>＋</span> {{ exporting ? 'Sedang Export...' : 'Export Sekarang (/export)' }}
                        </Btn>

                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh
                        </Btn>

                        <Btn size="sm" variant="secondary" title="Export CSV" @click="exportCsv">
                            ⬇ Export CSV
                        </Btn>

                        <div class="mx-1 h-4 w-px bg-zinc-300 dark:bg-zinc-700"></div>

                        <!-- Type Filter -->
                        <div class="flex items-center gap-1 text-xs text-zinc-500">
                            <span>Tipe:</span>
                            <select
                                v-model="typeFilter"
                                class="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <option value="all">Semua Tipe</option>
                                <option value="export">Export Text</option>
                                <option value="binary">Binary</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari file, versi, tanggal..."
                            class="w-52 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>

                <!-- Table -->
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">#</th>
                                <th class="cursor-pointer px-4 py-2.5" @click="toggleSort('filename')">
                                    File Name
                                    <span v-if="sortField === 'filename'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5" @click="toggleSort('type')">
                                    Type
                                    <span v-if="sortField === 'type'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5" @click="toggleSort('routeros_version')">
                                    RouterOS Version
                                    <span v-if="sortField === 'routeros_version'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5" @click="toggleSort('size')">
                                    Size
                                    <span v-if="sortField === 'size'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5" @click="toggleSort('created_at')">
                                    Created At
                                    <span v-if="sortField === 'created_at'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="px-4 py-2.5 text-right font-sans">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr v-if="paginatedBackups.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center font-sans text-xs text-zinc-500">
                                    Belum ada backup konfigurasi yang tersimpan. Klik "Export Sekarang" untuk membuat snapshot.
                                </td>
                            </tr>
                            <tr
                                v-for="(b, idx) in paginatedBackups"
                                :key="b.id"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                            >
                                <td class="px-3 py-2.5 text-center text-zinc-400 font-sans">{{ (currentPage - 1) * perPage + idx + 1 }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm">💾</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ b.filename }}</span>
                                        <button
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin nama file"
                                            @click.stop="copyValue(b.filename, 'Filename')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 uppercase">
                                        {{ b.type }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400 font-sans">
                                    {{ b.routeros_version ?? '—' }}
                                </td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-700 dark:text-zinc-300">
                                    {{ fmt(b.size) }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500 font-sans">
                                    {{ b.created_at || '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right font-sans" @click.stop>
                                    <a
                                        :href="route('backups.download', b.id)"
                                        class="inline-flex items-center gap-1 rounded bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/40 dark:text-indigo-300 dark:hover:bg-indigo-950/70"
                                    >
                                        ⬇ Download
                                    </a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer with Pagination -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 bg-zinc-50/80 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-3">
                        <span>
                            Total: <strong>{{ sortedBackups.length }}</strong> backup snapshot
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
        </div>
    </RouterWorkspace>
</template>
