<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface ArpEntry {
    '.id': string;
    address: string;
    'mac-address'?: string;
    interface: string;
    dynamic?: string;
    complete?: string;
    invalid?: string;
    disabled?: string;
    comment?: string;
}

const props = defineProps<{
    router: any;
    entries: ArpEntry[];
    interfaces?: string[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

const selectedId = ref<string | null>(null);
const search = ref('');
const statusFilter = ref<'all' | 'dynamic' | 'static' | 'disabled'>('all');
const interfaceFilter = ref('all');
const showAddModal = ref(false);

// ── Multi-selection for Batch Operations ─────────────────────────────
const selectedArpIds = ref<string[]>([]);
const isProcessingBatch = ref(false);

const form = useForm({
    address: '',
    mac_address: '',
    interface: props.interfaces?.[0] ?? 'bridge1',
    comment: '',
});

// ── Top KPI Statistics Summary ───────────────────────────────────────
const stats = computed(() => {
    const total = props.entries.length;
    const dynamicCount = props.entries.filter((e) => e.dynamic === 'true').length;
    const staticCount = props.entries.filter((e) => e.dynamic !== 'true').length;
    const completeCount = props.entries.filter((e) => e.complete === 'true' && e.disabled !== 'true').length;
    const disabledCount = props.entries.filter((e) => e.disabled === 'true').length;
    const uniqueInterfaces = new Set(props.entries.map((e) => e.interface)).size;

    return {
        total,
        dynamicCount,
        staticCount,
        completeCount,
        disabledCount,
        uniqueInterfaces,
    };
});

const interfaceList = computed(() => {
    return Array.from(new Set(props.entries.map((e) => e.interface))).sort();
});

// ── Filtered, Sorted, and Paginated Data ─────────────────────────────
type ArpSortField = 'address' | 'mac-address' | 'interface' | 'dynamic' | 'complete';
const sortField = ref<ArpSortField>('address');
const sortAsc = ref(true);
const perPage = ref<number>(25);
const currentPage = ref(1);

function sortBy(field: ArpSortField) {
    if (sortField.value === field) {
        sortAsc.value = !sortAsc.value;
    } else {
        sortField.value = field;
        sortAsc.value = true;
    }
}

const filteredEntries = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.entries.filter((item) => {
        const isDyn = item.dynamic === 'true';
        const isDis = item.disabled === 'true';

        if (statusFilter.value === 'dynamic' && !isDyn) return false;
        if (statusFilter.value === 'static' && isDyn) return false;
        if (statusFilter.value === 'disabled' && !isDis) return false;
        if (interfaceFilter.value !== 'all' && item.interface !== interfaceFilter.value) return false;

        if (!q) return true;
        return (
            item.address.toLowerCase().includes(q) ||
            (item['mac-address'] ?? '').toLowerCase().includes(q) ||
            item.interface.toLowerCase().includes(q) ||
            (item.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedEntries = computed(() => {
    const list = [...filteredEntries.value];
    list.sort((a, b) => {
        if (sortField.value === 'address') {
            const numA = (a.address || '').split('.').reduce((acc, octet) => (acc << 8) + parseInt(octet || '0', 10), 0);
            const numB = (b.address || '').split('.').reduce((acc, octet) => (acc << 8) + parseInt(octet || '0', 10), 0);
            return sortAsc.value ? numA - numB : numB - numA;
        }

        const valA = (a[sortField.value] ?? '').toString().toLowerCase();
        const valB = (b[sortField.value] ?? '').toString().toLowerCase();
        if (valA < valB) return sortAsc.value ? -1 : 1;
        if (valA > valB) return sortAsc.value ? 1 : -1;
        return 0;
    });
    return list;
});

const totalPages = computed(() => {
    if (perPage.value === 0) return 1;
    return Math.ceil(sortedEntries.value.length / perPage.value) || 1;
});

const paginatedEntries = computed(() => {
    if (perPage.value === 0) return sortedEntries.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedEntries.value.slice(start, start + perPage.value);
});

watch([search, statusFilter, interfaceFilter, perPage], () => {
    currentPage.value = 1;
});

// Checkbox selection states
const isAllSelected = computed(() => {
    if (paginatedEntries.value.length === 0) return false;
    return paginatedEntries.value.every((e) => selectedArpIds.value.includes(e['.id']));
});

function toggleSelectAll() {
    if (isAllSelected.value) {
        const currentIds = new Set(paginatedEntries.value.map((e) => e['.id']));
        selectedArpIds.value = selectedArpIds.value.filter((id) => !currentIds.has(id));
    } else {
        const newIds = new Set([...selectedArpIds.value, ...paginatedEntries.value.map((e) => e['.id'])]);
        selectedArpIds.value = Array.from(newIds);
    }
}

function toggleSelect(id: string) {
    const idx = selectedArpIds.value.indexOf(id);
    if (idx >= 0) selectedArpIds.value.splice(idx, 1);
    else selectedArpIds.value.push(id);
}

// ── Batch Operations ─────────────────────────────────────────────────
function batchAction(action: 'make_static' | 'enable' | 'disable' | 'destroy') {
    if (selectedArpIds.value.length === 0) return;
    if (action === 'destroy' && !confirm(`Hapus ${selectedArpIds.value.length} entri ARP terpilih?`)) return;

    isProcessingBatch.value = true;
    inertia.post(
        route('routers.ip.arp.batch', props.router.id),
        { action, ids: selectedArpIds.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isProcessingBatch.value = false;
                selectedArpIds.value = [];
                selectedId.value = null;
            },
        },
    );
}

// ── Single Item Operations ───────────────────────────────────────────
const selectedEntry = computed(() => props.entries.find((e) => e['.id'] === selectedId.value) ?? null);

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

function saveEntry() {
    form.post(route('routers.ip.arp.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddModal.value = false;
            form.reset();
        },
    });
}

function makeStatic(entry: ArpEntry) {
    inertia.post(
        route('routers.ip.arp.make-static', props.router.id),
        { id: entry['.id'] },
        { preserveScroll: true },
    );
}

function toggleEntry(entry: ArpEntry, disable: boolean) {
    inertia.post(
        route('routers.ip.arp.toggle', props.router.id),
        { id: entry['.id'], disable },
        { preserveScroll: true },
    );
}

function removeSelected() {
    if (!selectedEntry.value) return;
    if (!confirm(`Hapus ARP entry ${selectedEntry.value.address}?`)) return;

    inertia.delete(route('routers.ip.arp.destroy', props.router.id), {
        data: { id: selectedEntry.value['.id'] },
        preserveScroll: true,
        onSuccess: () => { selectedId.value = null; },
    });
}

// ── Clipboard Helper ─────────────────────────────────────────────────
const copiedKey = ref<string | null>(null);
function copyText(text: string, key: string) {
    navigator.clipboard.writeText(text);
    copiedKey.value = key;
    setTimeout(() => {
        if (copiedKey.value === key) copiedKey.value = null;
    }, 1500);
}

// ── CSV Export Helper ────────────────────────────────────────────────
function exportCsv() {
    const headers = ['IP Address', 'MAC Address', 'Interface', 'Dynamic', 'Complete', 'Disabled', 'Comment'];
    const rows = sortedEntries.value.map((e) => [
        `"${e.address}"`,
        `"${e['mac-address'] || ''}"`,
        `"${e.interface}"`,
        `"${e.dynamic === 'true' ? 'yes' : 'no'}"`,
        `"${e.complete === 'true' ? 'yes' : 'no'}"`,
        `"${e.disabled === 'true' ? 'yes' : 'no'}"`,
        `"${(e.comment || '').replace(/"/g, '""')}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `arp-table-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<template>
    <Head :title="`ARP List — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- ── Top KPI Statistics Summary Grid ────────────────────────── -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total ARP -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Total ARP</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.total }}</span>
                        <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">Entries</span>
                    </div>
                </div>

                <!-- Complete Active -->
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3.5 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">
                        <StatusDot color="emerald" :pulse="true" />
                        <span>Resolved (Complete)</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-emerald-800 dark:text-emerald-200">{{ stats.completeCount }}</span>
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400">Active</span>
                    </div>
                </div>

                <!-- Static ARP -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-zinc-500">
                        <span class="inline-block h-2 w-2 rounded-full bg-purple-500"></span>
                        <span>Static ARP</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-purple-700 dark:text-purple-400">{{ stats.staticCount }}</span>
                        <span class="rounded bg-purple-50 px-1.5 py-0.5 text-[10px] font-medium text-purple-700 dark:bg-purple-950 dark:text-purple-300">Fixed</span>
                    </div>
                </div>

                <!-- Dynamic ARP -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-zinc-500">
                        <span class="inline-block h-2 w-2 rounded-full bg-sky-500"></span>
                        <span>Dynamic ARP</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-sky-700 dark:text-sky-400">{{ stats.dynamicCount }}</span>
                        <span class="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-950 dark:text-sky-300">Discovered</span>
                    </div>
                </div>

                <!-- Disabled -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-zinc-500">
                        <StatusDot color="zinc" />
                        <span>Disabled</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-500 dark:text-zinc-400">{{ stats.disabledCount }}</span>
                        <span class="text-[10px] text-zinc-400">Nonaktif</span>
                    </div>
                </div>

                <!-- Interfaces -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Bound Interfaces</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.uniqueInterfaces }}</span>
                        <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Ports</span>
                    </div>
                </div>
            </div>

            <!-- ── Main WinBox Window Card ────────────────────────────────── -->
            <div class="flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- WinBox Header -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold text-indigo-600 dark:text-indigo-400">⚡</span>
                            <h2 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">ARP Table & IP-MAC Bindings</h2>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /ip/arp
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                                <StatusDot color="emerald" :pulse="true" />
                                {{ stats.completeCount }} Resolved
                            </span>
                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ entries.length }} Total Entries
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage" size="sm" variant="primary" class="gap-1.5" @click="showAddModal = true">
                            <span>＋</span> Add
                        </Btn>

                        <template v-if="can.manage && selectedEntry">
                            <Btn
                                v-if="selectedEntry.dynamic === 'true'"
                                size="sm"
                                variant="secondary"
                                class="bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/40 dark:text-indigo-300"
                                title="Make permanent static ARP entry"
                                @click="makeStatic(selectedEntry)"
                            >
                                📌 Make Static
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedEntry.disabled === 'false'"
                                @click="toggleEntry(selectedEntry, false)"
                            >
                                ✓ Enable
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedEntry.disabled === 'true'"
                                @click="toggleEntry(selectedEntry, true)"
                            >
                                ✕ Disable
                            </Btn>
                        </template>

                        <Btn
                            v-if="can.manage"
                            size="sm"
                            variant="secondary"
                            class="text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/30"
                            :disabled="!selectedEntry"
                            @click="removeSelected"
                        >
                            🗑 Remove
                        </Btn>

                        <div class="mx-1 h-4 w-px bg-zinc-300 dark:bg-zinc-700"></div>

                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh
                        </Btn>

                        <!-- CSV Export -->
                        <Btn
                            size="sm"
                            variant="ghost"
                            class="gap-1.5 text-zinc-600 dark:text-zinc-300"
                            title="Export ARP table to CSV"
                            @click="exportCsv"
                        >
                            <span>📥</span> Export CSV
                        </Btn>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <select
                            v-model="statusFilter"
                            class="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <option value="all">Semua Status</option>
                            <option value="dynamic">Dynamic Only (D)</option>
                            <option value="static">Static Only (S)</option>
                            <option value="disabled">Disabled (X)</option>
                        </select>

                        <select
                            v-if="interfaceList.length > 0"
                            v-model="interfaceFilter"
                            class="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <option value="all">Semua Interface</option>
                            <option v-for="iface in interfaceList" :key="iface" :value="iface">{{ iface }}</option>
                        </select>

                        <div class="relative">
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Cari IP, MAC, port..."
                                class="w-44 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 text-xs focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                            />
                            <span v-if="search" class="absolute right-2 top-1 cursor-pointer text-xs text-zinc-400 hover:text-zinc-600" @click="search = ''">✕</span>
                        </div>
                    </div>
                </div>

                <!-- ── Batch Actions Sticky Floating Bar ────────────────────── -->
                <transition
                    enter-active-class="transition duration-150 ease-out"
                    enter-from-class="opacity-0 -translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 -translate-y-2"
                >
                    <div
                        v-if="can.manage && selectedArpIds.length > 0"
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/95 px-4 py-2 backdrop-blur-xs dark:border-indigo-900/60 dark:bg-indigo-950/80"
                    >
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white dark:bg-indigo-500">
                                {{ selectedArpIds.length }}
                            </span>
                            <span class="text-xs font-semibold text-indigo-950 dark:text-indigo-100">
                                Entri ARP dipilih
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-indigo-700 shadow-xs hover:bg-indigo-50 dark:bg-zinc-800 dark:text-indigo-300 dark:hover:bg-zinc-700"
                                title="Jadikan static permanent entry"
                                @click="batchAction('make_static')"
                            >
                                📌 Make Static
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-emerald-700 shadow-xs hover:bg-emerald-50 dark:bg-zinc-800 dark:text-emerald-300 dark:hover:bg-zinc-700"
                                @click="batchAction('enable')"
                            >
                                ✓ Enable
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-amber-700 shadow-xs hover:bg-amber-50 dark:bg-zinc-800 dark:text-amber-300 dark:hover:bg-zinc-700"
                                @click="batchAction('disable')"
                            >
                                ✕ Disable
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-rose-700 shadow-xs hover:bg-rose-50 dark:bg-zinc-800 dark:text-rose-300 dark:hover:bg-zinc-700"
                                @click="batchAction('destroy')"
                            >
                                🗑 Hapus
                            </button>
                            <button
                                type="button"
                                class="text-xs text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
                                @click="selectedArpIds = []"
                            >
                                Batal
                            </button>
                        </div>
                    </div>
                </transition>

                <!-- Table View -->
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th v-if="can.manage" class="w-8 px-3 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        :checked="isAllSelected"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelectAll"
                                    />
                                </th>
                                <th class="w-12 px-3 py-2 text-center">Flags</th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortBy('address')">
                                    <div class="flex items-center gap-1">
                                        <span>IP Address</span>
                                        <span v-if="sortField === 'address'">{{ sortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortBy('mac-address')">
                                    <div class="flex items-center gap-1">
                                        <span>MAC Address</span>
                                        <span v-if="sortField === 'mac-address'">{{ sortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="cursor-pointer px-3 py-2 select-none hover:text-zinc-900 dark:hover:text-zinc-200" @click="sortBy('interface')">
                                    <div class="flex items-center gap-1">
                                        <span>Interface</span>
                                        <span v-if="sortField === 'interface'">{{ sortAsc ? '↑' : '↓' }}</span>
                                    </div>
                                </th>
                                <th class="px-3 py-2">Comment</th>
                                <th class="px-3 py-2 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="e in paginatedEntries"
                                :key="e['.id']"
                                class="group cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedId === e['.id'] ? 'bg-indigo-100/60 dark:bg-indigo-950/40' : '',
                                    selectedArpIds.includes(e['.id']) ? 'bg-indigo-50/80 dark:bg-indigo-950/50' : '',
                                    e.disabled === 'true' ? 'opacity-50 line-through' : ''
                                ]"
                                @click="selectedId = e['.id']"
                            >
                                <td v-if="can.manage" class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="selectedArpIds.includes(e['.id'])"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelect(e['.id'])"
                                    />
                                </td>
                                <td class="px-3 py-2 text-center font-sans font-bold">
                                    <span v-if="e.dynamic === 'true'" class="text-indigo-600 dark:text-indigo-400" title="Dynamic (D)">D </span>
                                    <span v-if="e.complete === 'true'" class="text-emerald-500" title="Complete (C)">C </span>
                                    <span v-if="e.disabled === 'true'" class="text-rose-500" title="Disabled (X)">X </span>
                                    <span v-if="e.dynamic !== 'true' && e.disabled !== 'true'" class="text-purple-600 dark:text-purple-400" title="Static (S)">S </span>
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ e.address }}</span>
                                        <button
                                            type="button"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] text-zinc-400 hover:text-indigo-600 transition-opacity"
                                            title="Copy IP"
                                            @click.stop="copyText(e.address, 'ip-' + e['.id'])"
                                        >
                                            {{ copiedKey === 'ip-' + e['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ e['mac-address'] || '(incomplete)' }}</span>
                                        <button
                                            v-if="e['mac-address']"
                                            type="button"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] text-zinc-400 hover:text-indigo-600 transition-opacity"
                                            title="Copy MAC"
                                            @click.stop="copyText(e['mac-address'], 'mac-' + e['.id'])"
                                        >
                                            {{ copiedKey === 'mac-' + e['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-indigo-700 dark:text-indigo-400">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ e.interface }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ e.comment || '-' }}</td>
                                <td class="px-3 py-2 text-right" @click.stop>
                                    <div class="flex items-center justify-end gap-1 font-sans">
                                        <button
                                            v-if="can.manage && e.dynamic === 'true'"
                                            type="button"
                                            class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/60 dark:text-indigo-300"
                                            title="Make static entry"
                                            @click="makeStatic(e)"
                                        >
                                            Make Static
                                        </button>
                                        <button
                                            v-if="can.manage"
                                            type="button"
                                            class="rounded px-1.5 py-0.5 text-[10px] font-medium transition-colors"
                                            :class="e.disabled === 'true'
                                                ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300'
                                                : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300'"
                                            @click="toggleEntry(e, e.disabled !== 'true')"
                                        >
                                            {{ e.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="filteredEntries.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No ARP entries found matching filter criteria.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── Pagination & WinBox Status Footer ──────────────────────── -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50/80 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-4">
                        <span>
                            Total: <strong>{{ filteredEntries.length }}</strong> items
                        </span>
                        <span v-if="selectedEntry" class="font-mono text-indigo-600 dark:text-indigo-400">
                            Selected: {{ selectedEntry.address }} ({{ selectedEntry['.id'] }})
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1.5 text-xs text-zinc-500">
                            <span>Per halaman:</span>
                            <select
                                v-model.number="perPage"
                                class="rounded border border-zinc-300 bg-white px-2 py-0.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option :value="15">15</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="0">Semua</option>
                            </select>
                        </div>

                        <div v-if="perPage > 0" class="flex items-center gap-1">
                            <button
                                type="button"
                                :disabled="currentPage <= 1"
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-zinc-100 disabled:opacity-40 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                @click="currentPage--"
                            >
                                ‹ Prev
                            </button>
                            <span class="px-1 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                {{ currentPage }} / {{ totalPages }}
                            </span>
                            <button
                                type="button"
                                :disabled="currentPage >= totalPages"
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-zinc-100 disabled:opacity-40 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                @click="currentPage++"
                            >
                                Next ›
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: Add Static ARP -->
        <Modal :show="showAddModal" title="New Static ARP Entry" @close="showAddModal = false">
            <form class="space-y-3" @submit.prevent="saveEntry">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">IP Address</label>
                    <input
                        v-model="form.address"
                        type="text"
                        required
                        placeholder="e.g. 192.168.88.50"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">MAC Address</label>
                    <input
                        v-model="form.mac_address"
                        type="text"
                        required
                        placeholder="e.g. AA:BB:CC:DD:EE:FF"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Interface</label>
                    <select
                        v-model="form.interface"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option v-for="iface in interfaces" :key="iface" :value="iface">{{ iface }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="form.comment"
                        type="text"
                        placeholder="e.g. Office Camera"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showAddModal = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="form.processing">Add Entry</Btn>
                </div>
            </form>
        </Modal>
    </RouterWorkspace>
</template>
