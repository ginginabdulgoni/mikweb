<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router as inertia } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';
import type { RouterSummary } from '@/types';

interface Results {
    operation: string;
    items: { router: string; ok: boolean; message: string }[];
}

const props = defineProps<{
    routers: RouterSummary[];
    results: Results | null;
}>();

const selected = ref<number[]>([]);
const searchQuery = ref('');
const showCommandModal = ref(false);
const customCommand = ref('');
const resultFilter = ref<'all' | 'ok' | 'fail'>('all');
const isRunning = ref(false);

const filteredRouters = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.routers;
    return props.routers.filter(
        (r) =>
            r.name.toLowerCase().includes(q) ||
            r.host.toLowerCase().includes(q) ||
            (r.identity ?? '').toLowerCase().includes(q),
    );
});

const isAllSelected = computed(() => {
    if (filteredRouters.value.length === 0) return false;
    return filteredRouters.value.every((r) => selected.value.includes(r.id));
});

function toggleAll() {
    if (isAllSelected.value) {
        const idsToRemove = new Set(filteredRouters.value.map((r) => r.id));
        selected.value = selected.value.filter((id) => !idsToRemove.has(id));
    } else {
        const newIds = new Set([...selected.value, ...filteredRouters.value.map((r) => r.id)]);
        selected.value = Array.from(newIds);
    }
}

function selectOnlineOnly() {
    selected.value = props.routers.filter((r) => r.status === 'online').map((r) => r.id);
}

function selectUnreachableOnly() {
    selected.value = props.routers.filter((r) => r.status === 'unreachable').map((r) => r.id);
}

function clearSelection() {
    selected.value = [];
}

function run(op: 'test' | 'backup' | 'reboot' | 'sync') {
    if (selected.value.length === 0) return;

    if (op === 'reboot') {
        if (!confirm(`Are you sure you want to REBOOT all ${selected.value.length} selected routers? Active sessions will be disconnected.`)) {
            return;
        }
    }

    isRunning.value = true;
    inertia.post(
        route(`bulk.${op}`),
        { routers: selected.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isRunning.value = false;
            },
        },
    );
}

function openCommandModal() {
    if (selected.value.length === 0) return;
    customCommand.value = '';
    showCommandModal.value = true;
}

function runCustomCommand() {
    if (!customCommand.value.trim() || selected.value.length === 0) return;
    isRunning.value = true;
    showCommandModal.value = false;
    inertia.post(
        route('bulk.command'),
        { routers: selected.value, command: customCommand.value.trim() },
        {
            preserveScroll: true,
            onFinish: () => {
                isRunning.value = false;
            },
        },
    );
}

const filteredResults = computed(() => {
    if (!props.results) return [];
    if (resultFilter.value === 'ok') return props.results.items.filter((i) => i.ok);
    if (resultFilter.value === 'fail') return props.results.items.filter((i) => !i.ok);
    return props.results.items;
});

const successCount = computed(() => props.results?.items.filter((i) => i.ok).length ?? 0);
const failureCount = computed(() => props.results?.items.filter((i) => !i.ok).length ?? 0);
</script>

<template>
    <Head title="Bulk Operations" />
    <AppLayout title="Bulk Fleet Operations">
        <div class="w-full space-y-6">
            <!-- Header and Description -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">Bulk Operations &amp; Batch Orchestration</h2>
                    <p class="text-xs text-zinc-500">
                        Select multiple routers and execute batch actions across your network simultaneously.
                    </p>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-wrap items-center gap-2">
                    <Btn
                        variant="secondary"
                        size="sm"
                        :disabled="selected.length === 0 || isRunning"
                        @click="run('test')"
                    >
                        ⚡ Test Connection ({{ selected.length }})
                    </Btn>

                    <Btn
                        variant="secondary"
                        size="sm"
                        :disabled="selected.length === 0 || isRunning"
                        @click="run('sync')"
                    >
                        🔄 Sync Info ({{ selected.length }})
                    </Btn>

                    <Btn
                        variant="secondary"
                        size="sm"
                        :disabled="selected.length === 0 || isRunning"
                        @click="run('backup')"
                    >
                        💾 Backup Export ({{ selected.length }})
                    </Btn>

                    <Btn
                        variant="secondary"
                        size="sm"
                        :disabled="selected.length === 0 || isRunning"
                        @click="openCommandModal"
                    >
                        ⌨ Run Command ({{ selected.length }})
                    </Btn>

                    <Btn
                        variant="danger"
                        size="sm"
                        :disabled="selected.length === 0 || isRunning"
                        @click="run('reboot')"
                    >
                        ⟳ Reboot ({{ selected.length }})
                    </Btn>
                </div>
            </div>

            <!-- Router Selection Table Card -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <!-- Quick selection pills -->
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="text-zinc-500">Pilih Cepat:</span>
                        <button
                            type="button"
                            class="rounded-md border border-zinc-300 bg-white px-2 py-1 font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                            @click="toggleAll"
                        >
                            {{ isAllSelected ? 'Batalkan Semua' : 'Pilih Semua' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-emerald-300 bg-emerald-50/50 px-2 py-1 font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300"
                            @click="selectOnlineOnly"
                        >
                            Hanya Online
                        </button>
                        <button
                            type="button"
                            class="rounded-md border border-rose-300 bg-rose-50/50 px-2 py-1 font-medium text-rose-700 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300"
                            @click="selectUnreachableOnly"
                        >
                            Hanya Unreachable
                        </button>
                        <button
                            v-if="selected.length > 0"
                            type="button"
                            class="rounded-md border border-zinc-300 px-2 py-1 font-medium text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800"
                            @click="clearSelection"
                        >
                            Clear ({{ selected.length }})
                        </button>
                    </div>

                    <!-- Search Filter -->
                    <input
                        v-model="searchQuery"
                        type="search"
                        placeholder="Cari router di daftar..."
                        class="h-7 w-52 rounded-lg border border-zinc-300 bg-white px-2.5 text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                    />
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50/50 text-xs font-medium text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/40 dark:text-zinc-400">
                            <tr>
                                <th class="w-10 px-4 py-2.5">
                                    <input
                                        type="checkbox"
                                        :checked="isAllSelected"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                                        @change="toggleAll"
                                    />
                                </th>
                                <th class="px-4 py-2.5">Router Name</th>
                                <th class="px-4 py-2.5">Identity</th>
                                <th class="px-4 py-2.5">Host / IP</th>
                                <th class="px-4 py-2.5">RouterOS Version</th>
                                <th class="px-4 py-2.5">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredRouters.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center text-xs text-zinc-500">
                                    Tidak ada router yang sesuai dengan pencarian.
                                </td>
                            </tr>
                            <tr
                                v-for="r in filteredRouters"
                                :key="r.id"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40"
                                :class="selected.includes(r.id) ? 'bg-indigo-50/50 dark:bg-indigo-950/30' : ''"
                                @click="selected.includes(r.id) ? selected = selected.filter(id => id !== r.id) : selected.push(r.id)"
                            >
                                <td class="px-4 py-2.5" @click.stop>
                                    <input
                                        v-model="selected"
                                        type="checkbox"
                                        :value="r.id"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                                    />
                                </td>
                                <td class="px-4 py-2.5 font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ r.name }}
                                </td>
                                <td class="px-4 py-2.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ r.identity || '—' }}
                                </td>
                                <td class="px-4 py-2.5 font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ r.host }}
                                </td>
                                <td class="px-4 py-2.5 font-mono text-xs text-zinc-600 dark:text-zinc-300">
                                    {{ r.routeros_version ? `v${r.routeros_version}` : '—' }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <StatusDot :color="r.status_color" :label="r.status_label" :pulse="r.status === 'online'" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Bulk Operation Results Card -->
            <div v-if="results" class="rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50/80 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            Hasil Operasi: {{ results.operation }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                            ✓ {{ successCount }} Sukses
                        </span>
                        <span
                            v-if="failureCount > 0"
                            class="inline-flex items-center gap-1.5 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-800 dark:bg-rose-950/60 dark:text-rose-300"
                        >
                            ✗ {{ failureCount }} Gagal
                        </span>
                    </div>

                    <!-- Filter results -->
                    <div class="flex rounded-lg border border-zinc-200 bg-white p-0.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                        <button
                            type="button"
                            class="rounded px-2.5 py-1 font-medium transition-colors"
                            :class="resultFilter === 'all' ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-white' : 'text-zinc-500'"
                            @click="resultFilter = 'all'"
                        >
                            Semua ({{ results.items.length }})
                        </button>
                        <button
                            type="button"
                            class="rounded px-2.5 py-1 font-medium transition-colors"
                            :class="resultFilter === 'ok' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'text-zinc-500'"
                            @click="resultFilter = 'ok'"
                        >
                            Sukses
                        </button>
                        <button
                            type="button"
                            class="rounded px-2.5 py-1 font-medium transition-colors"
                            :class="resultFilter === 'fail' ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' : 'text-zinc-500'"
                            @click="resultFilter = 'fail'"
                        >
                            Gagal
                        </button>
                    </div>
                </div>

                <ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                    <li v-for="(it, i) in filteredResults" :key="i" class="flex items-center gap-3 px-4 py-2.5">
                        <span :class="it.ok ? 'text-emerald-500 font-bold' : 'text-rose-500 font-bold'">
                            {{ it.ok ? '✓' : '✗' }}
                        </span>
                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ it.router }}</span>
                        <span class="flex-1 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ it.message }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Custom Command Modal -->
        <Modal :open="showCommandModal" title="Execute Batch RouterOS Command" @close="showCommandModal = false">
            <form class="space-y-4" @submit.prevent="runCustomCommand">
                <p class="text-xs text-zinc-500">
                    Perintah RouterOS API akan dieksekusi secara berurutan pada {{ selected.length }} router yang dipilih.
                </p>

                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Command</label>
                    <input
                        v-model="customCommand"
                        type="text"
                        required
                        placeholder="e.g. /system identity print or /ip dns cache flush"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="flex flex-wrap gap-1.5 pt-1 text-[11px] text-zinc-500">
                    <span class="font-medium text-zinc-400">Contoh perintah:</span>
                    <button
                        type="button"
                        class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                        @click="customCommand = '/system identity print'"
                    >
                        /system identity print
                    </button>
                    <button
                        type="button"
                        class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700"
                        @click="customCommand = '/ip dns cache flush'"
                    >
                        /ip dns cache flush
                    </button>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showCommandModal = false">Batal</Btn>
                    <Btn type="submit" variant="primary" :disabled="!customCommand.trim()">Jalankan Command</Btn>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
