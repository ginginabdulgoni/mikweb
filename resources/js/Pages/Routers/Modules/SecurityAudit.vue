<script setup lang="ts">
import { ref } from 'vue';
import { Head, router as inertia } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';

interface Finding {
    id: string;
    category: string;
    title: string;
    severity: 'critical' | 'warning' | 'info';
    detail: string;
    recommendation: string;
    fixable: boolean;
    fix_action?: string;
}

const props = defineProps<{
    router: {
        id: number;
        name: string;
        host: string;
        identity?: string;
    };
    score: number;
    grade: string;
    findings: Finding[];
}>();

const isFixing = ref<string | null>(null);

function applyFix(f: Finding) {
    if (!f.fix_action) return;
    if (confirm(`Terapkan perbaikan otomatis untuk "${f.title}"?`)) {
        isFixing.value = f.id;
        inertia.post(
            route('routers.security.audit.fix', props.router.id),
            { action: f.fix_action },
            {
                preserveScroll: true,
                onFinish: () => {
                    isFixing.value = null;
                },
            }
        );
    }
}

function rescan() {
    inertia.reload({ preserveScroll: true });
}
</script>

<template>
    <Head :title="`Security Auditor - ${router.name}`" />
    <AppLayout :title="`Security Auditor: ${router.name}`">
        <div class="mx-auto max-w-5xl space-y-6">
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-200 pb-4 dark:border-zinc-800">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <span>🛡️ MikroTik Security Auditor</span>
                    </h2>
                    <p class="text-xs text-zinc-500">
                        Audit otomatis celah keamanan umum pada router <strong>{{ router.name }}</strong> ({{ router.host }}).
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Btn variant="secondary" size="sm" @click="rescan">
                        ↻ Pindai Ulang
                    </Btn>
                </div>
            </div>

            <!-- Score KPI Card -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <!-- Big Score Card -->
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-medium text-zinc-500">Skor Keamanan Router</span>
                        <div class="mt-1 flex items-baseline gap-2">
                            <span
                                class="text-4xl font-extrabold tracking-tight"
                                :class="{
                                    'text-emerald-600 dark:text-emerald-400': score >= 90,
                                    'text-sky-600 dark:text-sky-400': score >= 75 && score < 90,
                                    'text-amber-600 dark:text-amber-400': score >= 60 && score < 75,
                                    'text-rose-600 dark:text-rose-400': score < 60,
                                }"
                            >
                                {{ score }}
                            </span>
                            <span class="text-sm font-semibold text-zinc-400">/ 100</span>
                        </div>
                        <span class="text-[11px] text-zinc-400">Audit kepatuhan konfigurasi</span>
                    </div>

                    <!-- Grade Circle Badge -->
                    <div
                        class="flex h-16 w-16 items-center justify-center rounded-2xl text-2xl font-black shadow-inner"
                        :class="{
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300': grade === 'A',
                            'bg-sky-50 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300': grade === 'B',
                            'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300': grade === 'C',
                            'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300': grade === 'D',
                        }"
                    >
                        Grade {{ grade }}
                    </div>
                </div>

                <!-- Critical Issues -->
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500">Isu Kritis (High Risk)</span>
                    <div class="mt-1 text-3xl font-bold text-rose-600 dark:text-rose-400">
                        {{ findings.filter((f) => f.severity === 'critical').length }}
                    </div>
                    <span class="text-[11px] text-rose-500">Harus segera diperbaiki</span>
                </div>

                <!-- Warning Issues -->
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500">Peringatan (Medium Risk)</span>
                    <div class="mt-1 text-3xl font-bold text-amber-600 dark:text-amber-400">
                        {{ findings.filter((f) => f.severity === 'warning').length }}
                    </div>
                    <span class="text-[11px] text-amber-500">Rekomendasi penguatan</span>
                </div>
            </div>

            <!-- Findings List -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        Temuan Audit Keamanan ({{ findings.length }})
                    </h3>
                </div>

                <!-- Empty state if score 100 -->
                <div
                    v-if="findings.length === 0"
                    class="rounded-xl border border-dashed border-emerald-300 bg-emerald-50/50 p-12 text-center dark:border-emerald-900 dark:bg-emerald-950/20"
                >
                    <div class="text-3xl">🛡️✨</div>
                    <h4 class="mt-2 text-sm font-bold text-emerald-800 dark:text-emerald-300">Konfigurasi Router Sangat Baik!</h4>
                    <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-400">
                        Tidak ditemukan celah keamanan kritis maupun peringatan pada router ini.
                    </p>
                </div>

                <!-- Cards of findings -->
                <div
                    v-for="f in findings"
                    :key="f.id"
                    class="rounded-xl border bg-white p-5 shadow-xs transition dark:bg-zinc-900"
                    :class="{
                        'border-rose-200 dark:border-rose-900/60': f.severity === 'critical',
                        'border-amber-200 dark:border-amber-900/60': f.severity === 'warning',
                        'border-zinc-200 dark:border-zinc-800': f.severity === 'info',
                    }"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="rounded px-2 py-0.5 text-[10px] font-bold uppercase"
                                    :class="{
                                        'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300': f.severity === 'critical',
                                        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300': f.severity === 'warning',
                                        'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300': f.severity === 'info',
                                    }"
                                >
                                    {{ f.severity === 'critical' ? 'KRITIS' : 'PERINGATAN' }}
                                </span>
                                <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-mono text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                    {{ f.category }}
                                </span>
                            </div>
                            <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ f.title }}</h4>
                        </div>

                        <!-- 1-Click Fix Button -->
                        <div v-if="f.fixable">
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white shadow-xs transition hover:bg-emerald-700 disabled:opacity-50"
                                :disabled="isFixing === f.id"
                                @click="applyFix(f)"
                            >
                                <span>{{ isFixing === f.id ? 'Menerapkan...' : '⚡ 1-Click Perbaiki' }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Details & Recommendation -->
                    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                        <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/40">
                            <span class="font-semibold text-zinc-700 dark:text-zinc-300">Deskripsi &amp; Risiko:</span>
                            <p class="mt-1 text-zinc-600 dark:text-zinc-400 leading-relaxed">{{ f.detail }}</p>
                        </div>

                        <div class="rounded-lg bg-indigo-50/60 p-3 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40">
                            <span class="font-semibold text-indigo-900 dark:text-indigo-300">Langkah Solusi / Rekomendasi:</span>
                            <p class="mt-1 text-indigo-800 dark:text-indigo-400 leading-relaxed">{{ f.recommendation }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
