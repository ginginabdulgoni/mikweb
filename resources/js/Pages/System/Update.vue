<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';

interface CommitItem {
    hash: string;
    short_hash: string;
    author_name: string;
    author_email: string;
    date: string;
    relative_date: string;
    message: string;
}

interface GitStatus {
    branch: string;
    remote_url: string;
    current: {
        hash: string;
        short_hash: string;
        message: string;
        author: string;
        date: string;
    };
    has_modifications: boolean;
    behind: number;
    ahead: number;
    is_up_to_date: boolean;
    remote_latest: {
        hash: string;
        short_hash: string;
        author: string;
        date: string;
        message: string;
    } | null;
}

interface SystemInfo {
    php_version: string;
    laravel_version: string;
    os: string;
    environment: string;
    app_url: string;
}

const props = defineProps<{
    git: GitStatus;
    commits: CommitItem[];
    system: SystemInfo;
    updateOutput?: string | null;
}>();

const checking = ref(false);
const showConfirmModal = ref(false);
const showLogModal = ref(!!props.updateOutput);

const updateForm = useForm({
    run_composer: true,
    run_migrate: true,
    run_optimize: true,
});

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

function checkUpdates() {
    checking.value = true;
    inertia.post(
        route('system.update.check'),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                checking.value = false;
            },
        },
    );
}

function runUpdate() {
    showConfirmModal.value = false;
    updateForm.post(route('system.update.apply'), {
        preserveScroll: true,
        onSuccess: () => {
            showLogModal.value = true;
        },
    });
}
</script>

<template>
    <Head title="System Update" />

    <AppLayout title="System Update">
        <div class="space-y-6">
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

            <!-- Page Header -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        System Update & Git Sync
                    </h1>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Kelola pembaruan otomatis platform MikWeb langsung dari repositori Git resmi.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Btn
                        variant="secondary"
                        size="md"
                        class="gap-1.5"
                        :disabled="checking || updateForm.processing"
                        @click="checkUpdates"
                    >
                        <span>↻</span> {{ checking ? 'Memeriksa...' : 'Cek Update dari Git' }}
                    </Btn>

                    <Btn
                        variant="primary"
                        size="md"
                        class="gap-1.5"
                        :disabled="updateForm.processing"
                        @click="showConfirmModal = true"
                    >
                        <span>🚀</span> {{ updateForm.processing ? 'Memproses Update...' : 'Perbarui Sistem Sekarang' }}
                    </Btn>

                    <Btn
                        v-if="updateOutput"
                        variant="ghost"
                        size="md"
                        @click="showLogModal = true"
                    >
                        📋 Lihat Log Terakhir
                    </Btn>
                </div>
            </div>

            <!-- Top KPI Cards -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <!-- Status Card -->
                <div
                    class="rounded-xl border p-4 shadow-xs"
                    :class="
                        git.behind > 0
                            ? 'border-amber-300 bg-amber-50/60 dark:border-amber-900/60 dark:bg-amber-950/20'
                            : 'border-emerald-300 bg-emerald-50/60 dark:border-emerald-900/60 dark:bg-emerald-950/20'
                    "
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="text-xs font-semibold"
                            :class="git.behind > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400'"
                        >
                            Status Sistem
                        </span>
                        <span class="text-lg">{{ git.behind > 0 ? '⚠️' : '✅' }}</span>
                    </div>
                    <div
                        class="mt-2 text-lg font-bold"
                        :class="git.behind > 0 ? 'text-amber-800 dark:text-amber-300' : 'text-emerald-800 dark:text-emerald-300'"
                    >
                        {{ git.behind > 0 ? `${git.behind} Commit Tertinggal` : 'Sistem Terbaru (Up to Date)' }}
                    </div>
                    <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ git.behind > 0 ? 'Tersedia pembaruan baru di repositori.' : 'Versi lokal sinkron dengan GitHub.' }}
                    </div>
                </div>

                <!-- Current Version Card -->
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Current Commit</span>
                        <span class="rounded bg-indigo-100 px-1.5 py-0.5 font-mono text-[11px] font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                            {{ git.current.short_hash }}
                        </span>
                    </div>
                    <div class="mt-2 truncate font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100" :title="git.current.message">
                        {{ git.current.message }}
                    </div>
                    <div class="mt-1 flex items-center justify-between text-xs text-zinc-400">
                        <span>{{ git.current.author }}</span>
                        <span>{{ git.current.date }}</span>
                    </div>
                </div>

                <!-- Git Branch & Remote Card -->
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Git Branch & Remote</span>
                        <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">branch: {{ git.branch }}</span>
                    </div>
                    <div class="mt-2 flex items-center gap-1.5 font-mono text-xs text-indigo-600 dark:text-indigo-400">
                        <span class="truncate" :title="git.remote_url">{{ git.remote_url || 'https://github.com/ginginabdulgoni/mikweb.git' }}</span>
                        <button
                            class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
                            title="Salin URL remote"
                            @click="copyValue(git.remote_url, 'Remote URL')"
                        >
                            📋
                        </button>
                    </div>
                    <div class="mt-1 text-xs text-zinc-400">
                        {{ git.has_modifications ? '⚠️ Ada modifikasi lokal yang belum di-commit' : '✓ Direktori lokal bersih' }}
                    </div>
                </div>

                <!-- Environment Card -->
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Server Environment</span>
                        <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ system.environment }}
                        </span>
                    </div>
                    <div class="mt-2 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        PHP {{ system.php_version }} / Laravel {{ system.laravel_version }}
                    </div>
                    <div class="mt-1 truncate text-xs text-zinc-400" :title="system.os">
                        OS: {{ system.os }}
                    </div>
                </div>
            </div>

            <!-- Update Execution Log (Terminal View) -->
            <div v-if="updateOutput" class="overflow-hidden rounded-xl border border-zinc-800 bg-zinc-950 shadow-md">
                <div class="flex items-center justify-between border-b border-zinc-800 px-4 py-2.5 bg-zinc-900/80">
                    <div class="flex items-center gap-2">
                        <span class="h-3 w-3 rounded-full bg-emerald-500"></span>
                        <span class="text-xs font-mono font-medium text-zinc-300">Update Execution Output</span>
                    </div>
                    <button
                        class="text-xs text-zinc-400 hover:text-white"
                        @click="copyValue(updateOutput, 'Log')"
                    >
                        📋 Salin Log
                    </button>
                </div>
                <pre class="max-h-80 overflow-y-auto p-4 font-mono text-xs text-emerald-400 whitespace-pre-wrap">{{ updateOutput }}</pre>
            </div>

            <!-- Recent Commits List Card -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Riwayat Commit Terakhir (Git Log)</h2>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Daftar pembaruan dan perubahan kode terbaru dari repositori.</p>
                        </div>
                        <span class="text-xs font-mono text-zinc-400">{{ commits.length }} commit ditampilkan</span>
                    </div>
                </div>

                <div class="divide-y divide-zinc-200/70 dark:divide-zinc-800/70">
                    <div v-if="commits.length === 0" class="p-6 text-center text-xs text-zinc-400">
                        Tidak ada riwayat commit yang ditemukan.
                    </div>
                    <div
                        v-for="(c, idx) in commits"
                        :key="c.hash"
                        class="flex flex-col gap-2 p-4 transition-colors hover:bg-zinc-50/80 sm:flex-row sm:items-center sm:justify-between dark:hover:bg-zinc-800/40"
                    >
                        <div class="flex items-start gap-3">
                            <!-- Indicator badge -->
                            <div class="mt-0.5">
                                <span
                                    v-if="c.hash === git.current.hash"
                                    class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                                >
                                    ● Current Version
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
                                >
                                    #{{ idx + 1 }}
                                </span>
                            </div>

                            <div>
                                <div class="font-medium text-sm text-zinc-900 dark:text-zinc-100">
                                    {{ c.message }}
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                                    <span>👤 {{ c.author_name }}</span>
                                    <span>📅 {{ c.relative_date }} ({{ c.date }})</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 self-start sm:self-center font-mono">
                            <span class="rounded bg-zinc-100 px-2 py-1 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ c.short_hash }}
                            </span>
                            <button
                                class="rounded p-1 text-zinc-400 hover:text-indigo-600 dark:hover:text-indigo-400"
                                title="Salin Full Hash"
                                @click="copyValue(c.hash, 'Commit Hash')"
                            >
                                📋
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: Konfirmasi Update -->
            <Modal :show="showConfirmModal" title="Konfirmasi Pembaruan Sistem" @close="showConfirmModal = false">
                <div class="space-y-4">
                    <p class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        Proses ini akan mengunduh versi terbaru dari repositori <strong>https://github.com/ginginabdulgoni/mikweb.git</strong> dan memperbarui komponen backend & database aplikasi MikWeb.
                    </p>

                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 space-y-2 dark:border-zinc-800 dark:bg-zinc-900/60">
                        <label class="flex items-center gap-2 text-xs text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            <input
                                v-model="updateForm.run_composer"
                                type="checkbox"
                                class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                            />
                            <span>Jalankan Composer Install (perbarui dependensi PHP jika ada)</span>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            <input
                                v-model="updateForm.run_migrate"
                                type="checkbox"
                                class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                            />
                            <span>Jalankan Database Migration (`php artisan migrate --force`)</span>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            <input
                                v-model="updateForm.run_optimize"
                                type="checkbox"
                                class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                            />
                            <span>Bersihkan Cache & Optimalkan (`php artisan optimize:clear`)</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <Btn type="button" variant="secondary" @click="showConfirmModal = false">Batal</Btn>
                        <Btn
                            type="button"
                            variant="primary"
                            :disabled="updateForm.processing"
                            @click="runUpdate"
                        >
                            {{ updateForm.processing ? 'Sedang Memperbarui...' : 'Mulai Update Sekarang' }}
                        </Btn>
                    </div>
                </div>
            </Modal>
        </div>
    </AppLayout>
</template>
