<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';

interface RouterFile {
    '.id': string;
    name: string;
    type?: string;
    size?: string;
    'creation-time'?: string;
}

const props = defineProps<{
    router: any;
    files: RouterFile[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

const selectedId = ref<string | null>(null);
const search = ref('');
const typeFilter = ref('all');

// ── Multi Selection & Batch Bar ──────────────────────────────────────────
const selectedFileIds = ref<string[]>([]);
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

// ── Formatting ───────────────────────────────────────────────────────────
function formatSize(bytes?: string): string {
    if (!bytes) return '0 B';
    const num = parseInt(bytes, 10);
    if (isNaN(num) || num <= 0) return '0 B';
    if (num < 1024) return `${num} B`;
    if (num < 1024 * 1024) return `${(num / 1024).toFixed(1)} KB`;
    return `${(num / (1024 * 1024)).toFixed(2)} MB`;
}

// ── Sorting ─────────────────────────────────────────────────────────────
type SortField = 'name' | 'type' | 'size' | 'creation-time';
const sortField = ref<SortField>('name');
const sortDirection = ref<'asc' | 'desc'>('asc');

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

// ── Backup Modal & Form ──────────────────────────────────────────────────
const showBackupModal = ref(false);
const backupForm = useForm({
    name: '',
    password: '',
});

function openBackup() {
    backupForm.reset();
    showBackupModal.value = true;
}

function createBackup() {
    backupForm.post(route('routers.files.backup', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showBackupModal.value = false;
            backupForm.reset();
        },
    });
}

const fileList = computed(() => props.files ?? []);

// ── KPI Statistics ───────────────────────────────────────────────────────
const kpiStats = computed(() => {
    const list = fileList.value;
    const totalBytes = list.reduce((acc, f) => {
        const sz = parseInt(f.size ?? '0', 10);
        return acc + (isNaN(sz) ? 0 : sz);
    }, 0);

    return {
        total: list.length,
        totalSize: formatSize(totalBytes.toString()),
        backups: list.filter((f) => f.name.endsWith('.backup') || f.type === 'backup').length,
        scripts: list.filter((f) => f.name.endsWith('.rsc') || f.type === 'script').length,
        dirs: list.filter((f) => f.type === 'directory').length,
        other: list.filter((f) => !f.name.endsWith('.backup') && !f.name.endsWith('.rsc') && f.type !== 'directory').length,
    };
});

// ── Filtered & Sorted Files ──────────────────────────────────────────────
const filteredFiles = computed(() => {
    const q = search.value.trim().toLowerCase();
    const t = typeFilter.value;

    return fileList.value.filter((f) => {
        if (t === 'backup' && !f.name.endsWith('.backup') && f.type !== 'backup') return false;
        if (t === 'script' && !f.name.endsWith('.rsc') && f.type !== 'script') return false;
        if (t === 'directory' && f.type !== 'directory') return false;
        if (t === 'file' && f.type === 'directory') return false;
        if (!q) return true;
        return (
            f.name.toLowerCase().includes(q) ||
            (f.type ?? '').toLowerCase().includes(q) ||
            (f['creation-time'] ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedFiles = computed(() => {
    const list = [...filteredFiles.value];
    const f = sortField.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        if (f === 'size') {
            const szA = parseInt(a.size ?? '0', 10) || 0;
            const szB = parseInt(b.size ?? '0', 10) || 0;
            return (szA - szB) * dir;
        }
        const valA = (a[f] ?? '').toLowerCase();
        const valB = (b[f] ?? '').toLowerCase();
        return valA.localeCompare(valB) * dir;
    });
});

const totalPages = computed(() => {
    if (perPage.value <= 0) return 1;
    return Math.max(1, Math.ceil(sortedFiles.value.length / perPage.value));
});

const paginatedFiles = computed(() => {
    if (perPage.value <= 0) return sortedFiles.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedFiles.value.slice(start, start + perPage.value);
});

// ── Multi Selection Controls ─────────────────────────────────────────────
const isAllSelected = computed(() => {
    if (sortedFiles.value.length === 0) return false;
    return sortedFiles.value.every((f) => selectedFileIds.value.includes(f['.id']));
});

function toggleSelectAll() {
    if (isAllSelected.value) {
        selectedFileIds.value = [];
    } else {
        selectedFileIds.value = sortedFiles.value.map((f) => f['.id']);
    }
}

function toggleSelect(id: string) {
    const idx = selectedFileIds.value.indexOf(id);
    if (idx >= 0) {
        selectedFileIds.value.splice(idx, 1);
    } else {
        selectedFileIds.value.push(id);
    }
}

// ── Batch Operations ─────────────────────────────────────────────────────
function executeBatch(action: 'destroy') {
    if (selectedFileIds.value.length === 0) return;
    if (!confirm(`Hapus permanen ${selectedFileIds.value.length} file dari storage router?`)) {
        return;
    }

    batchLoading.value = true;
    inertia.post(
        route('routers.files.batch', props.router.id),
        {
            action,
            ids: selectedFileIds.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                batchLoading.value = false;
                selectedFileIds.value = [];
            },
        },
    );
}

// ── Single Operations ────────────────────────────────────────────────────
function removeSingle(file: RouterFile) {
    if (!confirm(`Hapus permanen file "${file.name}" dari storage router?`)) return;

    inertia.delete(route('routers.files.destroy', props.router.id), {
        data: { id: file['.id'] },
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
    const rows = sortedFiles.value.map((f) => [
        f.name,
        f.type || 'file',
        f.size || '0',
        formatSize(f.size),
        f['creation-time'] || '',
    ]);
    const headers = ['File Name', 'Type', 'Size (Bytes)', 'Formatted Size', 'Creation Time'];
    const csvContent =
        'data:text/csv;charset=utf-8,' +
        [headers.join(','), ...rows.map((e) => e.map((val) => `"${(val || '').replace(/"/g, '""')}"`).join(','))].join(
            '\n',
        );

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `files_${props.router.name}_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<template>
    <Head :title="`File Manager — ${router.name}`" />

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
                    <div class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">Total Files</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ kpiStats.total }}</div>
                    <div class="mt-1 text-[10px] text-zinc-400">Objek tersimpan</div>
                </div>

                <div class="rounded-xl border border-indigo-200 bg-indigo-50/40 p-3 shadow-xs dark:border-indigo-900/40 dark:bg-indigo-950/20">
                    <div class="text-[11px] font-medium text-indigo-700 dark:text-indigo-400">Total Storage</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-indigo-700 dark:text-indigo-300 truncate">{{ kpiStats.totalSize }}</div>
                    <div class="mt-1 text-[10px] text-indigo-600/70 dark:text-indigo-400/60">Penggunaan disk</div>
                </div>

                <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <div class="text-[11px] font-medium text-emerald-700 dark:text-emerald-400">Backup Files</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-emerald-700 dark:text-emerald-300">{{ kpiStats.backups }}</div>
                    <div class="mt-1 text-[10px] text-emerald-600/70 dark:text-emerald-400/60">File *.backup</div>
                </div>

                <div class="rounded-xl border border-amber-200 bg-amber-50/40 p-3 shadow-xs dark:border-amber-900/40 dark:bg-amber-950/20">
                    <div class="text-[11px] font-medium text-amber-700 dark:text-amber-400">Scripts & Configs</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-amber-700 dark:text-amber-300">{{ kpiStats.scripts }}</div>
                    <div class="mt-1 text-[10px] text-amber-600/70 dark:text-amber-400/60">File *.rsc</div>
                </div>

                <div class="rounded-xl border border-blue-200 bg-blue-50/40 p-3 shadow-xs dark:border-blue-900/40 dark:bg-blue-950/20">
                    <div class="text-[11px] font-medium text-blue-700 dark:text-blue-400">Directories</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-blue-700 dark:text-blue-300">{{ kpiStats.dirs }}</div>
                    <div class="mt-1 text-[10px] text-blue-600/70 dark:text-blue-400/60">Folder sistem</div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-zinc-50/60 p-3 shadow-xs dark:border-zinc-800 dark:bg-zinc-900/50">
                    <div class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">Other Files</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-zinc-600 dark:text-zinc-400">{{ kpiStats.other }}</div>
                    <div class="mt-1 text-[10px] text-zinc-400">Sertifikat, paket, log</div>
                </div>
            </div>

            <!-- Main Panel -->
            <div class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Files Explorer</span>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /file
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage" size="sm" variant="primary" class="gap-1.5" @click="openBackup">
                            <span>＋</span> Buat Backup Router
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
                                <option value="all">Semua File</option>
                                <option value="backup">Backup (.backup)</option>
                                <option value="script">Script (.rsc)</option>
                                <option value="directory">Folder</option>
                                <option value="file">File Biasa</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari nama file, tipe..."
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
                        v-if="selectedFileIds.length > 0"
                        class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-200 bg-indigo-50 px-4 py-2 dark:border-indigo-900/50 dark:bg-indigo-950/40"
                    >
                        <div class="flex items-center gap-2 text-xs font-medium text-indigo-900 dark:text-indigo-200">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">
                                {{ selectedFileIds.length }}
                            </span>
                            <span>file dipilih</span>
                        </div>

                        <div class="flex items-center gap-1.5">
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
                                @click="selectedFileIds = []"
                            >
                                Batal
                            </button>
                        </div>
                    </div>
                </transition>

                <!-- Table -->
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
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('name')">
                                    Name
                                    <span v-if="sortField === 'name'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('type')">
                                    Type
                                    <span v-if="sortField === 'type'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('size')">
                                    Size
                                    <span v-if="sortField === 'size'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('creation-time')">
                                    Creation Time
                                    <span v-if="sortField === 'creation-time'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th v-if="can.manage" class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr v-if="paginatedFiles.length === 0">
                                <td :colspan="can.manage ? 6 : 5" class="px-4 py-8 text-center font-sans text-xs text-zinc-500">
                                    Tidak ada file yang cocok dengan kriteria pencarian.
                                </td>
                            </tr>
                            <tr
                                v-for="f in paginatedFiles"
                                :key="f['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="selectedFileIds.includes(f['.id']) ? 'bg-indigo-100/50 dark:bg-indigo-950/40' : ''"
                                @click="selectedId = f['.id']"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="selectedFileIds.includes(f['.id'])"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelect(f['.id'])"
                                    />
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm">
                                            {{ f.type === 'directory' ? '📁' : f.name.endsWith('.backup') ? '💾' : f.name.endsWith('.rsc') ? '📜' : '📄' }}
                                        </span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ f.name }}</span>
                                        <button
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin nama file"
                                            @click.stop="copyValue(f.name, 'Filename')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <span
                                        class="inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold"
                                        :class="
                                            f.type === 'directory'
                                                ? 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
                                                : f.name.endsWith('.backup')
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                                                : f.name.endsWith('.rsc')
                                                ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                                                : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                                        "
                                    >
                                        {{ f.type || 'file' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 tabular-nums text-zinc-700 dark:text-zinc-300">
                                    {{ formatSize(f.size) }}
                                </td>
                                <td class="px-3 py-2 text-zinc-500">{{ f['creation-time'] || '-' }}</td>
                                <td v-if="can.manage" class="px-3 py-2 text-right" @click.stop>
                                    <button
                                        class="rounded px-2 py-0.5 text-xs text-rose-600 hover:bg-rose-100 dark:text-rose-400 dark:hover:bg-rose-950/50 font-sans"
                                        title="Hapus file"
                                        @click="removeSingle(f)"
                                    >
                                        🗑 Hapus
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer with Pagination -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 bg-zinc-50/80 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-3">
                        <span>
                            Total: <strong>{{ sortedFiles.length }}</strong> file
                        </span>
                        <span v-if="selectedFileIds.length > 0" class="font-medium text-indigo-600 dark:text-indigo-400">
                            ({{ selectedFileIds.length }} dipilih)
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

            <!-- Modal: Create Backup -->
            <Modal :show="showBackupModal" title="Buat Backup Router (.backup)" @close="showBackupModal = false">
                <form class="space-y-3" @submit.prevent="createBackup">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Nama File Backup (Opsional)</label>
                        <input
                            v-model="backupForm.name"
                            type="text"
                            placeholder="e.g. backup-sebelum-update"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                        <p class="mt-1 text-[11px] text-zinc-500">Jika dikosongkan, nama otomatis dibuat berdasarkan tanggal & waktu.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Enkripsi Password (Opsional)</label>
                        <input
                            v-model="backupForm.password"
                            type="password"
                            placeholder="Password untuk membuka file backup"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Btn type="button" variant="secondary" @click="showBackupModal = false">Batal</Btn>
                        <Btn type="submit" variant="primary" :disabled="backupForm.processing">Simpan Backup ke Storage</Btn>
                    </div>
                </form>
            </Modal>
        </div>
    </RouterWorkspace>
</template>
