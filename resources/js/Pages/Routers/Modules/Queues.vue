<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface SimpleQueue {
    '.id': string;
    name: string;
    target?: string;
    'max-limit'?: string;
    'limit-at'?: string;
    'burst-limit'?: string;
    priority?: string;
    parent?: string;
    queue?: string;
    bytes?: string;
    packets?: string;
    'total-bytes'?: string;
    disabled?: string;
    dynamic?: string;
    comment?: string;
}

interface QueueTreeNode {
    '.id': string;
    name: string;
    parent?: string;
    'packet-mark'?: string;
    'max-limit'?: string;
    'limit-at'?: string;
    priority?: string;
    bytes?: string;
    packets?: string;
    disabled?: string;
}

interface QueueType {
    '.id': string;
    name: string;
    kind?: string;
    'pcq-rate'?: string;
    'pcq-limit'?: string;
    'pcq-classifier'?: string;
}

const props = defineProps<{
    router: any;
    simpleQueues: SimpleQueue[];
    queueTree: QueueTreeNode[];
    queueTypes: QueueType[];
    interfaces?: string[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

type QueueTab = 'simple' | 'tree' | 'types';
const activeTab = ref<QueueTab>('simple');

// ── Search & Filter ──────────────────────────────────────────────────
const search = ref('');
const statusFilter = ref<'all' | 'enabled' | 'disabled'>('all');
const selectedId = ref<string | null>(null);

// Multi-select for Simple Queues
const selectedQueueIds = ref<string[]>([]);

// ── Byte & Unit Formatting Helpers ───────────────────────────────────
function fmtBytes(bytes: number | string): string {
    const n = Number(bytes ?? 0);
    if (!n || isNaN(n)) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(n) / Math.log(1024));
    return `${(n / 1024 ** i).toFixed(1)} ${u[i]}`;
}

function parseBytes(bytesStr?: string): { up: number; down: number; total: number; formatted: string } {
    if (!bytesStr) return { up: 0, down: 0, total: 0, formatted: '0 B / 0 B' };
    const [upStr, downStr] = bytesStr.split('/');
    const up = Number(upStr ?? 0) || 0;
    const down = Number(downStr ?? 0) || 0;
    return {
        up,
        down,
        total: up + down,
        formatted: `${fmtBytes(up)} / ${fmtBytes(down)}`,
    };
}

// ── Top KPI Statistics Summary ───────────────────────────────────────
const stats = computed(() => {
    const totalQueues = props.simpleQueues.length;
    const enabledQueues = props.simpleQueues.filter((q) => q.disabled !== 'true').length;
    const disabledQueues = props.simpleQueues.filter((q) => q.disabled === 'true').length;

    let totalUpBytes = 0;
    let totalDownBytes = 0;
    for (const q of props.simpleQueues) {
        const p = parseBytes(q.bytes);
        totalUpBytes += p.up;
        totalDownBytes += p.down;
    }

    const treeActive = props.queueTree.filter((t) => t.disabled !== 'true').length;
    const typesCount = props.queueTypes.length;

    return {
        totalQueues,
        enabledQueues,
        disabledQueues,
        totalUpBytes,
        totalDownBytes,
        totalTrafficFormatted: fmtBytes(totalUpBytes + totalDownBytes),
        totalTrafficSplit: `↑ ${fmtBytes(totalUpBytes)} / ↓ ${fmtBytes(totalDownBytes)}`,
        treeActive,
        typesCount,
    };
});

// ── Filtered Datasets ────────────────────────────────────────────────
const filteredSimple = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.simpleQueues.filter((item) => {
        const isDis = item.disabled === 'true';
        if (statusFilter.value === 'enabled' && isDis) return false;
        if (statusFilter.value === 'disabled' && !isDis) return false;
        if (!q) return true;
        return (
            item.name.toLowerCase().includes(q) ||
            (item.target ?? '').toLowerCase().includes(q) ||
            (item['max-limit'] ?? '').toLowerCase().includes(q) ||
            (item.parent ?? '').toLowerCase().includes(q) ||
            (item.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredTree = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.queueTree.filter((item) => {
        if (!q) return true;
        return (
            item.name.toLowerCase().includes(q) ||
            (item.parent ?? '').toLowerCase().includes(q) ||
            (item['packet-mark'] ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredTypes = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.queueTypes.filter((item) => {
        if (!q) return true;
        return item.name.toLowerCase().includes(q) || (item.kind ?? '').toLowerCase().includes(q);
    });
});

const selectedItem = computed(() => {
    if (!selectedId.value) return null;
    if (activeTab.value === 'simple') return props.simpleQueues.find((q) => q['.id'] === selectedId.value) ?? null;
    if (activeTab.value === 'tree') return props.queueTree.find((t) => t['.id'] === selectedId.value) ?? null;
    if (activeTab.value === 'types') return props.queueTypes.find((y) => y['.id'] === selectedId.value) ?? null;
    return null;
});

// ── Sorting & Pagination for Simple Queues ───────────────────────────
const sortKey = ref<string>('name');
const sortOrder = ref<'asc' | 'desc'>('asc');
const perPage = ref<number>(25);
const currentPage = ref<number>(1);

function setSort(key: string) {
    if (sortKey.value === key) {
        sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = key;
        sortOrder.value = 'asc';
    }
}

const sortedSimple = computed(() => {
    const list = [...filteredSimple.value];
    list.sort((a, b) => {
        let valA = '';
        let valB = '';
        if (sortKey.value === 'name') {
            valA = a.name;
            valB = b.name;
        } else if (sortKey.value === 'target') {
            valA = a.target ?? '';
            valB = b.target ?? '';
        } else if (sortKey.value === 'max-limit') {
            valA = a['max-limit'] ?? '';
            valB = b['max-limit'] ?? '';
        } else if (sortKey.value === 'limit-at') {
            valA = a['limit-at'] ?? '';
            valB = b['limit-at'] ?? '';
        } else if (sortKey.value === 'priority') {
            valA = a.priority ?? '8/8';
            valB = b.priority ?? '8/8';
        } else if (sortKey.value === 'bytes') {
            const bytesA = parseBytes(a.bytes).total;
            const bytesB = parseBytes(b.bytes).total;
            return sortOrder.value === 'asc' ? bytesA - bytesB : bytesB - bytesA;
        } else if (sortKey.value === 'status') {
            valA = a.disabled === 'true' ? '2' : '1';
            valB = b.disabled === 'true' ? '2' : '1';
        } else if (sortKey.value === 'comment') {
            valA = a.comment ?? '';
            valB = b.comment ?? '';
        }
        const cmp = valA.localeCompare(valB, undefined, { numeric: true });
        return sortOrder.value === 'asc' ? cmp : -cmp;
    });
    return list;
});

const paginatedSimple = computed(() => {
    if (perPage.value >= 9999) return sortedSimple.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedSimple.value.slice(start, start + perPage.value);
});

const totalPages = computed(() => {
    if (perPage.value >= 9999) return 1;
    return Math.max(1, Math.ceil(sortedSimple.value.length / perPage.value));
});

watch([search, statusFilter, perPage], () => {
    currentPage.value = 1;
});

// Multi-select helpers
function toggleSelectAllVisible(items: SimpleQueue[]) {
    const visibleIds = items.map((i) => i['.id']);
    const allSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedQueueIds.value.includes(id));
    if (allSelected) {
        selectedQueueIds.value = selectedQueueIds.value.filter((id) => !visibleIds.includes(id));
    } else {
        const set = new Set([...selectedQueueIds.value, ...visibleIds]);
        selectedQueueIds.value = Array.from(set);
    }
}

function toggleQueueSelection(id: string) {
    const idx = selectedQueueIds.value.indexOf(id);
    if (idx >= 0) {
        selectedQueueIds.value.splice(idx, 1);
    } else {
        selectedQueueIds.value.push(id);
    }
}

function clearQueueSelection() {
    selectedQueueIds.value = [];
}

// ── Modals & Forms ───────────────────────────────────────────────────
const showAddSimple = ref(false);
const showEditSimple = ref(false);
const editingSimple = ref<SimpleQueue | null>(null);

const simpleForm = useForm({
    id: '',
    name: '',
    target: '',
    max_upload: '10M',
    max_download: '10M',
    limit_at_upload: '',
    limit_at_download: '',
    burst_limit: '',
    priority: '8/8',
    parent: 'none',
    queue_type: 'default/default',
    comment: '',
});

// Quick Bandwidth Modal (1-click preset / custom)
const showQuickLimitModal = ref(false);
const targetQueueForQuickLimit = ref<SimpleQueue | null>(null);
const quickLimitUpload = ref('10M');
const quickLimitDownload = ref('10M');

function openQuickLimit(q: SimpleQueue) {
    targetQueueForQuickLimit.value = q;
    const parts = (q['max-limit'] ?? '10M/10M').split('/');
    quickLimitUpload.value = parts[0] || '10M';
    quickLimitDownload.value = parts[1] || '10M';
    showQuickLimitModal.value = true;
}

function applyPreset(up: string, down: string) {
    quickLimitUpload.value = up;
    quickLimitDownload.value = down;
}

function submitQuickLimit() {
    if (!targetQueueForQuickLimit.value) return;
    const limit = `${quickLimitUpload.value}/${quickLimitDownload.value}`;
    inertia.put(
        route('routers.queues.simple.quick-limit', props.router.id),
        {
            id: targetQueueForQuickLimit.value['.id'],
            max_limit: limit,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showQuickLimitModal.value = false;
            },
        },
    );
}

// Batch Actions Modal
const showBatchLimitModal = ref(false);
const batchMaxUpload = ref('10M');
const batchMaxDownload = ref('10M');

function submitBatchAction(action: 'enable' | 'disable' | 'change_limit' | 'reset_counters' | 'destroy') {
    if (selectedQueueIds.value.length === 0) return;

    if (action === 'destroy') {
        if (!confirm(`Hapus permanen ${selectedQueueIds.value.length} queue terpilih? Tindakan ini tidak dapat dibatalkan.`)) {
            return;
        }
    } else if (action === 'reset_counters') {
        if (!confirm(`Reset counter traffic (bytes & packets) untuk ${selectedQueueIds.value.length} queue terpilih ke 0?`)) {
            return;
        }
    }

    inertia.post(
        route('routers.queues.simple.batch', props.router.id),
        {
            action,
            ids: selectedQueueIds.value,
            max_limit: action === 'change_limit' ? `${batchMaxUpload.value}/${batchMaxDownload.value}` : undefined,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedQueueIds.value = [];
                showBatchLimitModal.value = false;
            },
        },
    );
}

// Tree Modals & Forms
const showAddTree = ref(false);
const showEditTree = ref(false);
const editingTree = ref<QueueTreeNode | null>(null);

const treeForm = useForm({
    id: '',
    name: '',
    parent: 'global',
    packet_mark: '',
    max_limit: '100M',
    limit_at: '50M',
    priority: '8',
});

// Type Form
const showAddType = ref(false);
const typeForm = useForm({
    name: '',
    kind: 'pcq',
    pcq_rate: '0',
    pcq_classifier: 'dst-address',
});

// Parent options for simple queues
const parentQueueOptions = computed(() => {
    return props.simpleQueues
        .map((q) => q.name)
        .filter((name) => editingSimple.value ? name !== editingSimple.value.name : true);
});

// ── Actions ──────────────────────────────────────────────────────────
function refreshData() {
    inertia.reload({ preserveScroll: true });
}

function openAddModal() {
    if (activeTab.value === 'simple') {
        simpleForm.reset();
        simpleForm.parent = 'none';
        simpleForm.queue_type = 'default/default';
        simpleForm.priority = '8/8';
        showAddSimple.value = true;
    } else if (activeTab.value === 'tree') {
        treeForm.reset();
        treeForm.parent = 'global';
        treeForm.priority = '8';
        showAddTree.value = true;
    } else if (activeTab.value === 'types') {
        typeForm.reset();
        showAddType.value = true;
    }
}

function openEditSimple(item: SimpleQueue) {
    editingSimple.value = item;
    const maxParts = (item['max-limit'] ?? '').split('/');
    const limitParts = (item['limit-at'] ?? '').split('/');

    simpleForm.reset();
    simpleForm.id = item['.id'];
    simpleForm.name = item.name;
    simpleForm.target = item.target ?? '';
    simpleForm.max_upload = maxParts[0] ?? '10M';
    simpleForm.max_download = maxParts[1] ?? '10M';
    simpleForm.limit_at_upload = limitParts[0] ?? '';
    simpleForm.limit_at_download = limitParts[1] ?? '';
    simpleForm.burst_limit = item['burst-limit'] ?? '';
    simpleForm.priority = item.priority ?? '8/8';
    simpleForm.parent = item.parent || 'none';
    simpleForm.queue_type = item.queue || 'default/default';
    simpleForm.comment = item.comment ?? '';
    showEditSimple.value = true;
}

function saveNewSimple() {
    const limitAt = (simpleForm.limit_at_upload || simpleForm.limit_at_download)
        ? `${simpleForm.limit_at_upload || '0'}/${simpleForm.limit_at_download || '0'}`
        : '';

    const payload = {
        name: simpleForm.name,
        target: simpleForm.target,
        max_limit: `${simpleForm.max_upload}/${simpleForm.max_download}`,
        limit_at: limitAt,
        burst_limit: simpleForm.burst_limit,
        priority: simpleForm.priority,
        parent: simpleForm.parent,
        queue_type: simpleForm.queue_type,
        comment: simpleForm.comment,
    };
    simpleForm.transform(() => payload).post(route('routers.queues.simple.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddSimple.value = false;
            simpleForm.reset();
        },
    });
}

function updateExistingSimple() {
    const limitAt = (simpleForm.limit_at_upload || simpleForm.limit_at_download)
        ? `${simpleForm.limit_at_upload || '0'}/${simpleForm.limit_at_download || '0'}`
        : '';

    const payload = {
        id: simpleForm.id,
        name: simpleForm.name,
        target: simpleForm.target,
        max_limit: `${simpleForm.max_upload}/${simpleForm.max_download}`,
        limit_at: limitAt,
        burst_limit: simpleForm.burst_limit,
        priority: simpleForm.priority,
        parent: simpleForm.parent,
        queue_type: simpleForm.queue_type,
        comment: simpleForm.comment,
    };
    simpleForm.transform(() => payload).put(route('routers.queues.simple.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditSimple.value = false;
        },
    });
}

function toggleSimple(item: SimpleQueue, disable: boolean) {
    inertia.post(
        route('routers.queues.simple.toggle', props.router.id),
        { id: item['.id'], disable },
        { preserveScroll: true },
    );
}

function removeSimple(item: SimpleQueue) {
    if (confirm(`Hapus Simple Queue "${item.name}"?`)) {
        inertia.delete(route('routers.queues.simple.destroy', props.router.id), {
            data: { id: item['.id'] },
            preserveScroll: true,
            onSuccess: () => {
                if (selectedId.value === item['.id']) selectedId.value = null;
            },
        });
    }
}

function resetCounterSimple(item: SimpleQueue) {
    if (confirm(`Reset counter traffic (bytes & packets) untuk "${item.name}"?`)) {
        inertia.post(
            route('routers.queues.simple.reset-counters', props.router.id),
            { id: item['.id'] },
            { preserveScroll: true },
        );
    }
}

function resetAllCounters() {
    if (confirm(`Reset SELURUH counter traffic Simple Queue pada router ${props.router.name}?`)) {
        inertia.post(
            route('routers.queues.simple.reset-counters', props.router.id),
            {},
            { preserveScroll: true },
        );
    }
}

// Tree Actions
function openEditTree(item: QueueTreeNode) {
    editingTree.value = item;
    treeForm.reset();
    treeForm.id = item['.id'];
    treeForm.name = item.name;
    treeForm.parent = item.parent || 'global';
    treeForm.packet_mark = item['packet-mark'] || '';
    treeForm.max_limit = item['max-limit'] || '';
    treeForm.limit_at = item['limit-at'] || '';
    treeForm.priority = item.priority || '8';
    showEditTree.value = true;
}

function saveNewTree() {
    treeForm.post(route('routers.queues.tree.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddTree.value = false;
            treeForm.reset();
        },
    });
}

function updateExistingTree() {
    treeForm.put(route('routers.queues.tree.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditTree.value = false;
        },
    });
}

function toggleTree(item: QueueTreeNode, disable: boolean) {
    inertia.post(
        route('routers.queues.tree.toggle', props.router.id),
        { id: item['.id'], disable },
        { preserveScroll: true },
    );
}

function removeTree(item: QueueTreeNode) {
    if (confirm(`Hapus Queue Tree Node "${item.name}"?`)) {
        inertia.delete(route('routers.queues.tree.destroy', props.router.id), {
            data: { id: item['.id'] },
            preserveScroll: true,
        });
    }
}

// Type Actions
function saveNewType() {
    typeForm.post(route('routers.queues.type.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddType.value = false;
            typeForm.reset();
        },
    });
}

function removeType(item: QueueType) {
    if (confirm(`Hapus Queue Type "${item.name}"?`)) {
        inertia.delete(route('routers.queues.type.destroy', props.router.id), {
            data: { id: item['.id'] },
            preserveScroll: true,
        });
    }
}

// Clipboard Helper
const copiedKey = ref<string | null>(null);
function copyText(text: string, key: string) {
    navigator.clipboard.writeText(text);
    copiedKey.value = key;
    setTimeout(() => {
        if (copiedKey.value === key) copiedKey.value = null;
    }, 1500);
}

// ── CSV Exports ──────────────────────────────────────────────────────
function exportSimpleCsv() {
    const headers = ['Name', 'Target', 'Max Limit (Up/Down)', 'Limit At', 'Burst Limit', 'Priority', 'Parent', 'Queue Type', 'Bytes (Up/Down)', 'Packets', 'Status', 'Comment'];
    const rows = filteredSimple.value.map((q) => [
        `"${q.name}"`,
        `"${q.target || ''}"`,
        `"${q['max-limit'] || 'unlimited'}"`,
        `"${q['limit-at'] || ''}"`,
        `"${q['burst-limit'] || ''}"`,
        `"${q.priority || '8/8'}"`,
        `"${q.parent || 'none'}"`,
        `"${q.queue || 'default'}"`,
        `"${q.bytes || '0/0'}"`,
        `"${q.packets || '0/0'}"`,
        `"${q.disabled === 'true' ? 'disabled' : 'enabled'}"`,
        `"${(q.comment || '').replace(/"/g, '""')}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `simple-queues-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportTreeCsv() {
    const headers = ['Name', 'Parent', 'Packet Mark', 'Max Limit', 'Limit At', 'Priority', 'Status'];
    const rows = filteredTree.value.map((t) => [
        `"${t.name}"`,
        `"${t.parent || 'global'}"`,
        `"${t['packet-mark'] || ''}"`,
        `"${t['max-limit'] || ''}"`,
        `"${t['limit-at'] || ''}"`,
        `"${t.priority || '8'}"`,
        `"${t.disabled === 'true' ? 'disabled' : 'enabled'}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `queue-tree-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportTypesCsv() {
    const headers = ['Name', 'Kind', 'PCQ Rate', 'PCQ Limit', 'Classifier'];
    const rows = filteredTypes.value.map((y) => [
        `"${y.name}"`,
        `"${y.kind || ''}"`,
        `"${y['pcq-rate'] || ''}"`,
        `"${y['pcq-limit'] || ''}"`,
        `"${y['pcq-classifier'] || ''}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `queue-types-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<template>
    <Head :title="`Queues — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- ── Top KPI Statistics Summary Grid ────────────────────────── -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Simple Queues -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Total Simple Queues</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.totalQueues }}</span>
                        <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">Rules</span>
                    </div>
                </div>

                <!-- Active / Running -->
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3.5 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">
                        <StatusDot color="emerald" :pulse="true" />
                        <span>Aktif (Running)</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-emerald-800 dark:text-emerald-200">{{ stats.enabledQueues }}</span>
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400">Shaping On</span>
                    </div>
                </div>

                <!-- Disabled -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-zinc-500">
                        <StatusDot color="zinc" />
                        <span>Disabled</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-500 dark:text-zinc-400">{{ stats.disabledQueues }}</span>
                        <span class="text-[10px] text-zinc-400">Nonaktif</span>
                    </div>
                </div>

                <!-- Total Transferred Traffic -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Total Traffic Processed</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-lg font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.totalTrafficFormatted }}</span>
                        <span class="text-[10px] font-mono text-zinc-400">Up + Down</span>
                    </div>
                </div>

                <!-- Queue Tree Nodes -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Queue Tree Nodes</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.treeActive }}</span>
                        <span class="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-950 dark:text-sky-300">Tree</span>
                    </div>
                </div>

                <!-- Queue Types -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Queue Types (PCQ)</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.typesCount }}</span>
                        <span class="rounded bg-violet-50 px-1.5 py-0.5 text-[10px] font-medium text-violet-700 dark:bg-violet-950 dark:text-violet-300">Algoritma</span>
                    </div>
                </div>
            </div>

            <!-- ── WinBox Window Card ────────────────────────────────────── -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- WinBox Header with Tabs -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold text-indigo-600 dark:text-indigo-400">⚖️</span>
                            <h2 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">Queue Management & Bandwidth Shaping</h2>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /queue
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                                <StatusDot color="emerald" :pulse="true" />
                                {{ stats.enabledQueues }} Running
                            </span>
                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ simpleQueues.length }} Total Rules
                            </span>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <nav class="-mb-px flex space-x-1 overflow-x-auto text-xs font-medium">
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors"
                            :class="activeTab === 'simple'
                                ? 'border-indigo-600 font-semibold text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'simple'; selectedId = null"
                        >
                            <span>Simple Queues</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ simpleQueues.length }}</span>
                        </button>
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors"
                            :class="activeTab === 'tree'
                                ? 'border-indigo-600 font-semibold text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'tree'; selectedId = null"
                        >
                            <span>Queue Tree (QoS)</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ queueTree.length }}</span>
                        </button>
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors"
                            :class="activeTab === 'types'
                                ? 'border-indigo-600 font-semibold text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'types'; selectedId = null"
                        >
                            <span>Queue Types (PCQ / FIFO)</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ queueTypes.length }}</span>
                        </button>
                    </nav>
                </div>

                <!-- WinBox Action Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage" size="sm" variant="primary" class="gap-1.5" @click="openAddModal">
                            <span>＋</span> Add
                        </Btn>

                        <template v-if="can.manage && activeTab === 'simple' && selectedItem">
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedItem.disabled === 'false'"
                                title="Aktifkan queue terpilih"
                                @click="toggleSimple(selectedItem, false)"
                            >
                                ✓ Enable
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedItem.disabled === 'true'"
                                title="Nonaktifkan queue terpilih"
                                @click="toggleSimple(selectedItem, true)"
                            >
                                ✕ Disable
                            </Btn>
                            <Btn size="sm" variant="secondary" title="Edit konfigurasi queue" @click="openEditSimple(selectedItem)">
                                ✏ Edit
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                class="border border-indigo-200 font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300"
                                title="Ubah limit bandwidth cepat (Preset)"
                                @click="openQuickLimit(selectedItem)"
                            >
                                ⚡ Limit
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                title="Reset counter byte & paket ke 0"
                                @click="resetCounterSimple(selectedItem)"
                            >
                                0️⃣ Reset
                            </Btn>
                            <Btn
                                size="sm"
                                variant="danger"
                                title="Hapus queue"
                                @click="removeSimple(selectedItem)"
                            >
                                🗑 Remove
                            </Btn>
                        </template>

                        <!-- Tree item selected actions -->
                        <template v-if="can.manage && activeTab === 'tree' && selectedItem">
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedItem.disabled === 'false'"
                                @click="toggleTree(selectedItem, false)"
                            >
                                ✓ Enable
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedItem.disabled === 'true'"
                                @click="toggleTree(selectedItem, true)"
                            >
                                ✕ Disable
                            </Btn>
                            <Btn size="sm" variant="secondary" @click="openEditTree(selectedItem)">
                                ✏ Edit
                            </Btn>
                            <Btn size="sm" variant="danger" @click="removeTree(selectedItem)">
                                🗑 Remove
                            </Btn>
                        </template>

                        <!-- Types item selected actions -->
                        <template v-if="can.manage && activeTab === 'types' && selectedItem">
                            <Btn size="sm" variant="danger" @click="removeType(selectedItem)">
                                🗑 Remove
                            </Btn>
                        </template>

                        <!-- Reset All Counters Button -->
                        <Btn
                            v-if="can.manage && activeTab === 'simple' && simpleQueues.length > 0"
                            size="sm"
                            variant="ghost"
                            class="text-zinc-600 hover:text-zinc-800 dark:text-zinc-400"
                            title="Reset seluruh counter traffic Simple Queue ke 0"
                            @click="resetAllCounters"
                        >
                            0️⃣ Reset All
                        </Btn>

                        <!-- CSV Export Buttons -->
                        <Btn
                            v-if="activeTab === 'simple'"
                            size="sm"
                            variant="ghost"
                            title="Export Simple Queues ke CSV"
                            @click="exportSimpleCsv"
                        >
                            📥 Export CSV
                        </Btn>
                        <Btn
                            v-else-if="activeTab === 'tree'"
                            size="sm"
                            variant="ghost"
                            title="Export Queue Tree ke CSV"
                            @click="exportTreeCsv"
                        >
                            📥 Export CSV
                        </Btn>
                        <Btn
                            v-else-if="activeTab === 'types'"
                            size="sm"
                            variant="ghost"
                            title="Export Queue Types ke CSV"
                            @click="exportTypesCsv"
                        >
                            📥 Export CSV
                        </Btn>

                        <Btn size="sm" variant="ghost" title="Refresh data dari MikroTik" @click="refreshData">
                            ↻ Refresh
                        </Btn>
                    </div>

                    <!-- Search & Filter Controls -->
                    <div class="flex flex-wrap items-center gap-2">
                        <select
                            v-if="activeTab === 'simple'"
                            v-model="statusFilter"
                            class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        >
                            <option value="all">Semua Status</option>
                            <option value="enabled">Active / Enabled</option>
                            <option value="disabled">Disabled Saja</option>
                        </select>

                        <div class="relative">
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Cari queue (nama, target)..."
                                class="w-48 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 pl-8 text-xs placeholder-zinc-400 transition-all focus:w-60 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                            <span class="pointer-events-none absolute left-2.5 top-2 text-xs text-zinc-400">⌕</span>
                        </div>
                    </div>
                </div>

                <!-- ── Multi-Select Batch Actions Bar ─────────────────────── -->
                <div
                    v-if="activeTab === 'simple' && selectedQueueIds.length > 0"
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-200 bg-indigo-50/90 px-4 py-2 text-xs text-indigo-900 dark:border-indigo-900/50 dark:bg-indigo-950/40 dark:text-indigo-200"
                >
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-5 items-center justify-center rounded-full bg-indigo-600 px-2 text-[11px] font-bold text-white">
                            {{ selectedQueueIds.length }}
                        </span>
                        <span class="font-medium">Simple Queue terpilih</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn
                            size="sm"
                            variant="secondary"
                            class="bg-white hover:bg-zinc-100 dark:bg-zinc-800"
                            title="Aktifkan seluruh queue terpilih"
                            @click="submitBatchAction('enable')"
                        >
                            ✓ Aktifkan
                        </Btn>
                        <Btn
                            size="sm"
                            variant="secondary"
                            class="bg-white hover:bg-zinc-100 dark:bg-zinc-800"
                            title="Nonaktifkan seluruh queue terpilih"
                            @click="submitBatchAction('disable')"
                        >
                            ✕ Nonaktifkan
                        </Btn>
                        <Btn
                            size="sm"
                            variant="secondary"
                            class="border border-indigo-300 bg-white font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:bg-zinc-800 dark:text-indigo-300"
                            title="Ubah limit bandwidth serentak"
                            @click="showBatchLimitModal = true"
                        >
                            ⚡ Ubah Bandwidth
                        </Btn>
                        <Btn
                            size="sm"
                            variant="secondary"
                            class="bg-white hover:bg-zinc-100 dark:bg-zinc-800"
                            title="Reset counter trafik queue terpilih"
                            @click="submitBatchAction('reset_counters')"
                        >
                            0️⃣ Reset Counters
                        </Btn>
                        <Btn
                            size="sm"
                            variant="danger"
                            title="Hapus permanen seluruh queue terpilih"
                            @click="submitBatchAction('destroy')"
                        >
                            🗑 Hapus
                        </Btn>
                        <button
                            type="button"
                            class="ml-2 text-xs text-zinc-500 hover:text-zinc-700 underline dark:text-zinc-400"
                            @click="clearQueueSelection"
                        >
                            Batal
                        </button>
                    </div>
                </div>

                <!-- ── Tables ────────────────────────────────────────────────── -->
                <div class="overflow-x-auto">
                    <!-- Tab 1: Simple Queues -->
                    <table v-if="activeTab === 'simple'" class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-10 px-3 py-2.5 text-center">
                                    <input
                                        type="checkbox"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                                        :checked="paginatedSimple.length > 0 && paginatedSimple.every((q) => selectedQueueIds.includes(q['.id']))"
                                        @change="toggleSelectAllVisible(paginatedSimple)"
                                    />
                                </th>
                                <th class="cursor-pointer select-none px-3 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('status')">
                                    Status
                                    <span v-if="sortKey === 'status'" class="ml-1 text-indigo-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('name')">
                                    Queue Name
                                    <span v-if="sortKey === 'name'" class="ml-1 text-indigo-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('target')">
                                    Target IP / Subnet
                                    <span v-if="sortKey === 'target'" class="ml-1 text-indigo-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('max-limit')">
                                    Max Limit (Up / Down)
                                    <span v-if="sortKey === 'max-limit'" class="ml-1 text-indigo-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('limit-at')">
                                    Limit At (CIR)
                                    <span v-if="sortKey === 'limit-at'" class="ml-1 text-indigo-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('priority')">
                                    Priority
                                    <span v-if="sortKey === 'priority'" class="ml-1 text-indigo-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('bytes')">
                                    Transferred Traffic (Up / Down)
                                    <span v-if="sortKey === 'bytes'" class="ml-1 text-indigo-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('comment')">
                                    Comment
                                    <span v-if="sortKey === 'comment'" class="ml-1 text-indigo-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="paginatedSimple.length === 0">
                                <td colspan="10" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada Simple Queue yang cocok dengan filter pencarian.
                                </td>
                            </tr>
                            <tr
                                v-for="q in paginatedSimple"
                                :key="q['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="[
                                    selectedId === q['.id'] && 'bg-indigo-50/70 dark:bg-indigo-950/40',
                                    selectedQueueIds.includes(q['.id']) && 'bg-indigo-50/40 dark:bg-indigo-950/20',
                                    q.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="selectedId = q['.id']"
                                @dblclick="can.manage && openEditSimple(q)"
                            >
                                <td class="px-3 py-2.5 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                                        :checked="selectedQueueIds.includes(q['.id'])"
                                        @change="toggleQueueSelection(q['.id'])"
                                    />
                                </td>
                                <td class="px-3 py-2.5">
                                    <StatusDot :color="q.disabled === 'true' ? 'zinc' : 'emerald'" :pulse="q.disabled !== 'true'" />
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ q.name }}</span>
                                        <span v-if="q.parent && q.parent !== 'none'" class="rounded bg-zinc-100 px-1 py-0.2 font-mono text-[10px] text-zinc-500 dark:bg-zinc-800" :title="'Parent: ' + q.parent">
                                            ↳ {{ q.parent }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-700 dark:text-zinc-300">
                                    <div class="flex items-center gap-1">
                                        <span>{{ q.target || 'any' }}</span>
                                        <button
                                            v-if="q.target"
                                            type="button"
                                            class="text-[10px] text-zinc-400 hover:text-zinc-600"
                                            title="Salin Target"
                                            @click.stop="copyText(q.target, 'target-' + q['.id'])"
                                        >
                                            {{ copiedKey === 'target-' + q['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 font-mono font-semibold text-indigo-700 dark:text-indigo-400">
                                    {{ q['max-limit'] || 'unlimited' }}
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    {{ q['limit-at'] || '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">
                                    {{ q.priority || '8/8' }}
                                </td>
                                <td class="px-4 py-2.5 font-mono tabular-nums text-zinc-600 dark:text-zinc-400">
                                    <div class="text-[11px]" :title="`Bytes: ${q.bytes || '0/0'}`">
                                        {{ parseBytes(q.bytes).formatted }}
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500 italic">
                                    {{ q.comment || '' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end items-center gap-1">
                                        <Btn
                                            size="sm"
                                            variant="ghost"
                                            class="px-1.5 text-indigo-600 hover:text-indigo-700"
                                            title="Ubah Bandwidth Cepat (Preset)"
                                            @click="openQuickLimit(q)"
                                        >
                                            ⚡ Limit
                                        </Btn>
                                        <Btn size="sm" @click="toggleSimple(q, q.disabled !== 'true')">
                                            {{ q.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" @click="openEditSimple(q)">Edit</Btn>
                                        <Btn
                                            size="sm"
                                            variant="ghost"
                                            class="px-1.5 text-zinc-400 hover:text-zinc-700"
                                            title="Reset counter trafik ke 0"
                                            @click="resetCounterSimple(q)"
                                        >
                                            0️⃣
                                        </Btn>
                                        <Btn size="sm" variant="danger" @click="removeSimple(q)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Tab 2: Queue Tree -->
                    <table v-else-if="activeTab === 'tree'" class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">State</th>
                                <th class="px-4 py-2.5">Name</th>
                                <th class="px-4 py-2.5">Parent</th>
                                <th class="px-4 py-2.5">Packet Mark</th>
                                <th class="px-4 py-2.5">Max Limit</th>
                                <th class="px-4 py-2.5">Limit At (CIR)</th>
                                <th class="px-4 py-2.5">Priority</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredTree.length === 0">
                                <td colspan="9" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada Queue Tree rule.
                                </td>
                            </tr>
                            <tr
                                v-for="(t, idx) in filteredTree"
                                :key="t['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="[
                                    selectedId === t['.id'] && 'bg-indigo-50/70 dark:bg-indigo-950/40',
                                    t.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="selectedId = t['.id']"
                                @dblclick="can.manage && openEditTree(t)"
                            >
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5">
                                    <StatusDot :color="t.disabled === 'true' ? 'zinc' : 'emerald'" />
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">{{ t.name }}</td>
                                <td class="px-4 py-2.5 font-mono text-zinc-700 dark:text-zinc-300">{{ t.parent || 'global' }}</td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">{{ t['packet-mark'] || 'none' }}</td>
                                <td class="px-4 py-2.5 font-mono font-semibold text-indigo-700 dark:text-indigo-400">{{ t['max-limit'] || '—' }}</td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">{{ t['limit-at'] || '—' }}</td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">{{ t.priority || '8' }}</td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end items-center gap-1">
                                        <Btn size="sm" @click="toggleTree(t, t.disabled !== 'true')">
                                            {{ t.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" @click="openEditTree(t)">Edit</Btn>
                                        <Btn size="sm" variant="danger" @click="removeTree(t)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Tab 3: Queue Types -->
                    <table v-else-if="activeTab === 'types'" class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">Name</th>
                                <th class="px-4 py-2.5">Kind</th>
                                <th class="px-4 py-2.5">PCQ Rate</th>
                                <th class="px-4 py-2.5">PCQ Limit</th>
                                <th class="px-4 py-2.5">Classifier</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredTypes.length === 0">
                                <td colspan="7" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada Queue Type yang cocok.
                                </td>
                            </tr>
                            <tr
                                v-for="(y, idx) in filteredTypes"
                                :key="y['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="selectedId === y['.id'] && 'bg-indigo-50/70 dark:bg-indigo-950/40'"
                                @click="selectedId = y['.id']"
                            >
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">{{ y.name }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ y.kind || '—' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">{{ y['pcq-rate'] || '0' }}</td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">{{ y['pcq-limit'] || '—' }}</td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">{{ y['pcq-classifier'] || '—' }}</td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end items-center gap-1">
                                        <Btn size="sm" variant="danger" @click="removeType(y)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Simple Queues Pagination & Per-Page Controls -->
                <div v-if="activeTab === 'simple'" class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-white px-4 py-2.5 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-2">
                        <span>Tampilkan:</span>
                        <select
                            v-model.number="perPage"
                            class="rounded border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        >
                            <option :value="15">15</option>
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                            <option :value="99999">Semua</option>
                        </select>
                        <span>
                            Total: <strong>{{ sortedSimple.length }}</strong> queue rule
                        </span>
                    </div>

                    <!-- Pagination Pages -->
                    <div v-if="totalPages > 1" class="flex items-center gap-1">
                        <button
                            type="button"
                            class="rounded px-2 py-1 text-zinc-500 hover:bg-zinc-100 disabled:opacity-40 dark:hover:bg-zinc-800"
                            :disabled="currentPage <= 1"
                            @click="currentPage--"
                        >
                            «
                        </button>
                        <span class="px-2 font-medium text-zinc-700 dark:text-zinc-300">
                            Hal {{ currentPage }} dari {{ totalPages }}
                        </span>
                        <button
                            type="button"
                            class="rounded px-2 py-1 text-zinc-500 hover:bg-zinc-100 disabled:opacity-40 dark:hover:bg-zinc-800"
                            :disabled="currentPage >= totalPages"
                            @click="currentPage++"
                        >
                            »
                        </button>
                    </div>
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-between border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-[11px] text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div>
                        <span>
                            Total data: <strong class="text-zinc-700 dark:text-zinc-300">
                                {{ activeTab === 'simple' ? filteredSimple.length : activeTab === 'tree' ? filteredTree.length : filteredTypes.length }}
                            </strong> items
                        </span>
                        <span v-if="selectedItem" class="ml-2 font-mono text-indigo-600 dark:text-indigo-400">
                            • Selected: {{ selectedItem.name }} ({{ selectedItem['.id'] }})
                        </span>
                    </div>
                    <div class="text-zinc-400">
                        Double-click row to edit • Click row to select
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Modal: Add Simple Queue ─────────────────────────────────── -->
        <Modal :open="showAddSimple" title="New Simple Queue" @close="showAddSimple = false">
            <form class="space-y-3.5 text-xs" @submit.prevent="saveNewSimple">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2 sm:col-span-1">
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Queue Name</label>
                        <input
                            v-model="simpleForm.name"
                            type="text"
                            required
                            placeholder="e.g. client-budi"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="col-span-2 sm:col-span-1">
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Target IP / Subnet</label>
                        <input
                            v-model="simpleForm.target"
                            type="text"
                            required
                            placeholder="e.g. 192.168.88.50/32 atau 10.0.0.0/24"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="mb-2 text-xs font-semibold text-zinc-800 dark:text-zinc-200">Max Limit (Bandwidth Maksimal)</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block font-medium text-zinc-600 dark:text-zinc-400">Max Upload (Up)</label>
                            <input
                                v-model="simpleForm.max_upload"
                                type="text"
                                required
                                placeholder="e.g. 5M, 10M, 100M"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-medium text-zinc-600 dark:text-zinc-400">Max Download (Down)</label>
                            <input
                                v-model="simpleForm.max_download"
                                type="text"
                                required
                                placeholder="e.g. 10M, 20M, 100M"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                            />
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Limit At (CIR Guaranteed Upload)</label>
                        <input
                            v-model="simpleForm.limit_at_upload"
                            type="text"
                            placeholder="e.g. 2M (opsional)"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Limit At (CIR Guaranteed Download)</label>
                        <input
                            v-model="simpleForm.limit_at_download"
                            type="text"
                            placeholder="e.g. 5M (opsional)"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Burst Limit</label>
                        <input
                            v-model="simpleForm.burst_limit"
                            type="text"
                            placeholder="e.g. 15M/15M"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Priority</label>
                        <input
                            v-model="simpleForm.priority"
                            type="text"
                            placeholder="8/8"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Parent Queue</label>
                        <select
                            v-model="simpleForm.parent"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <option value="none">none</option>
                            <option v-for="p in parentQueueOptions" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="simpleForm.comment"
                        type="text"
                        placeholder="Catatan pelanggan atau lokasi..."
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn type="button" variant="ghost" @click="showAddSimple = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="simpleForm.processing">Save Queue</Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Edit Simple Queue ────────────────────────────────── -->
        <Modal :open="showEditSimple" :title="`Edit Simple Queue: ${editingSimple?.name}`" @close="showEditSimple = false">
            <form class="space-y-3.5 text-xs" @submit.prevent="updateExistingSimple">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2 sm:col-span-1">
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Queue Name</label>
                        <input
                            v-model="simpleForm.name"
                            type="text"
                            required
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="col-span-2 sm:col-span-1">
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Target IP / Subnet</label>
                        <input
                            v-model="simpleForm.target"
                            type="text"
                            required
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>

                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="mb-2 text-xs font-semibold text-zinc-800 dark:text-zinc-200">Max Limit (Bandwidth Maksimal)</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block font-medium text-zinc-600 dark:text-zinc-400">Max Upload</label>
                            <input
                                v-model="simpleForm.max_upload"
                                type="text"
                                required
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-medium text-zinc-600 dark:text-zinc-400">Max Download</label>
                            <input
                                v-model="simpleForm.max_download"
                                type="text"
                                required
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                            />
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Limit At (CIR Guaranteed Upload)</label>
                        <input
                            v-model="simpleForm.limit_at_upload"
                            type="text"
                            placeholder="e.g. 2M"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Limit At (CIR Guaranteed Download)</label>
                        <input
                            v-model="simpleForm.limit_at_download"
                            type="text"
                            placeholder="e.g. 5M"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Burst Limit</label>
                        <input
                            v-model="simpleForm.burst_limit"
                            type="text"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Priority</label>
                        <input
                            v-model="simpleForm.priority"
                            type="text"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Parent Queue</label>
                        <select
                            v-model="simpleForm.parent"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <option value="none">none</option>
                            <option v-for="p in parentQueueOptions" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="simpleForm.comment"
                        type="text"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn type="button" variant="ghost" @click="showEditSimple = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="simpleForm.processing">Save Changes</Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Quick Limit Presets ──────────────────────────────── -->
        <Modal
            :open="showQuickLimitModal"
            :title="`⚡ Ubah Bandwidth: ${targetQueueForLimit?.name}`"
            @close="showQuickLimitModal = false"
        >
            <div class="space-y-4 text-xs">
                <div>
                    <span class="text-zinc-500">Target IP: </span>
                    <strong class="font-mono text-zinc-800 dark:text-zinc-200">{{ targetQueueForLimit?.target }}</strong>
                </div>

                <!-- Presets Buttons -->
                <div>
                    <label class="mb-2 block font-medium text-zinc-700 dark:text-zinc-300">Pilihan Cepat (Presets)</label>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-200 bg-zinc-50 p-2 text-center hover:border-indigo-500 hover:bg-indigo-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-indigo-950/40"
                            @click="applyPreset('2M', '2M')"
                        >
                            <div class="font-bold text-zinc-900 dark:text-zinc-100">2 Mbps</div>
                            <div class="text-[10px] text-zinc-500">Hemat</div>
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-200 bg-zinc-50 p-2 text-center hover:border-indigo-500 hover:bg-indigo-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-indigo-950/40"
                            @click="applyPreset('5M', '5M')"
                        >
                            <div class="font-bold text-zinc-900 dark:text-zinc-100">5 Mbps</div>
                            <div class="text-[10px] text-zinc-500">Basic Home</div>
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-200 bg-zinc-50 p-2 text-center hover:border-indigo-500 hover:bg-indigo-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-indigo-950/40"
                            @click="applyPreset('10M', '10M')"
                        >
                            <div class="font-bold text-zinc-900 dark:text-zinc-100">10 Mbps</div>
                            <div class="text-[10px] text-zinc-500">Standar</div>
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-200 bg-zinc-50 p-2 text-center hover:border-indigo-500 hover:bg-indigo-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-indigo-950/40"
                            @click="applyPreset('20M', '20M')"
                        >
                            <div class="font-bold text-zinc-900 dark:text-zinc-100">20 Mbps</div>
                            <div class="text-[10px] text-zinc-500">Family</div>
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-200 bg-zinc-50 p-2 text-center hover:border-indigo-500 hover:bg-indigo-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-indigo-950/40"
                            @click="applyPreset('50M', '50M')"
                        >
                            <div class="font-bold text-zinc-900 dark:text-zinc-100">50 Mbps</div>
                            <div class="text-[10px] text-zinc-500">Bisnis / Gamer</div>
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-200 bg-zinc-50 p-2 text-center hover:border-indigo-500 hover:bg-indigo-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-indigo-950/40"
                            @click="applyPreset('100M', '100M')"
                        >
                            <div class="font-bold text-zinc-900 dark:text-zinc-100">100 Mbps</div>
                            <div class="text-[10px] text-zinc-500">Dedicated</div>
                        </button>
                    </div>
                </div>

                <!-- Custom Input -->
                <div class="grid grid-cols-2 gap-3 pt-2">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Max Upload</label>
                        <input
                            v-model="quickLimitUpload"
                            type="text"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Max Download</label>
                        <input
                            v-model="quickLimitDownload"
                            type="text"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showQuickLimitModal = false">Batal</Btn>
                    <Btn variant="primary" @click="submitQuickLimit">
                        Terapkan Limit ({{ quickLimitUpload }}/{{ quickLimitDownload }})
                    </Btn>
                </div>
            </div>
        </Modal>

        <!-- ── Modal: Batch Change Limit ───────────────────────────────── -->
        <Modal
            :open="showBatchLimitModal"
            :title="`⚡ Ubah Bandwidth (${selectedQueueIds.length} Queue Terpilih)`"
            @close="showBatchLimitModal = false"
        >
            <div class="space-y-4 text-xs">
                <p class="text-zinc-600 dark:text-zinc-400">
                    Terapkan batas kecepatan baru untuk seluruh {{ selectedQueueIds.length }} queue terpilih secara serentak.
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Max Upload Baru</label>
                        <input
                            v-model="batchMaxUpload"
                            placeholder="e.g. 10M"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Max Download Baru</label>
                        <input
                            v-model="batchMaxDownload"
                            placeholder="e.g. 20M"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showBatchLimitModal = false">Batal</Btn>
                    <Btn variant="primary" @click="submitBatchAction('change_limit')">
                        Terapkan Bandwidth Baru
                    </Btn>
                </div>
            </div>
        </Modal>

        <!-- ── Modal: Add Queue Tree ───────────────────────────────────── -->
        <Modal :open="showAddTree" title="New Queue Tree Node" @close="showAddTree = false">
            <form class="space-y-3.5 text-xs" @submit.prevent="saveNewTree">
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Node Name</label>
                    <input
                        v-model="treeForm.name"
                        type="text"
                        required
                        placeholder="e.g. Total-Download"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Parent</label>
                    <input
                        v-model="treeForm.parent"
                        type="text"
                        required
                        placeholder="global or interface name (e.g. ether1)"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Max Limit</label>
                        <input
                            v-model="treeForm.max_limit"
                            type="text"
                            placeholder="e.g. 100M"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Limit At (CIR)</label>
                        <input
                            v-model="treeForm.limit_at"
                            type="text"
                            placeholder="e.g. 50M"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Packet Mark (Mangle)</label>
                        <input
                            v-model="treeForm.packet_mark"
                            type="text"
                            placeholder="Optional packet mark"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Priority</label>
                        <input
                            v-model="treeForm.priority"
                            type="text"
                            placeholder="8"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn type="button" variant="ghost" @click="showAddTree = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="treeForm.processing">Add Tree Node</Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Edit Queue Tree ──────────────────────────────────── -->
        <Modal :open="showEditTree" :title="`Edit Queue Tree: ${editingTree?.name}`" @close="showEditTree = false">
            <form class="space-y-3.5 text-xs" @submit.prevent="updateExistingTree">
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Node Name</label>
                    <input
                        v-model="treeForm.name"
                        type="text"
                        required
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Parent</label>
                    <input
                        v-model="treeForm.parent"
                        type="text"
                        required
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Max Limit</label>
                        <input
                            v-model="treeForm.max_limit"
                            type="text"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Limit At (CIR)</label>
                        <input
                            v-model="treeForm.limit_at"
                            type="text"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Packet Mark (Mangle)</label>
                        <input
                            v-model="treeForm.packet_mark"
                            type="text"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Priority</label>
                        <input
                            v-model="treeForm.priority"
                            type="text"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn type="button" variant="ghost" @click="showEditTree = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="treeForm.processing">Save Changes</Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Add Queue Type ───────────────────────────────────── -->
        <Modal :open="showAddType" title="New Queue Type" @close="showAddType = false">
            <form class="space-y-3.5 text-xs" @submit.prevent="saveNewType">
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Type Name</label>
                    <input
                        v-model="typeForm.name"
                        type="text"
                        required
                        placeholder="e.g. pcq-custom"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Kind</label>
                    <select
                        v-model="typeForm.kind"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="pcq">pcq</option>
                        <option value="pfifo">pfifo</option>
                        <option value="bfifo">bfifo</option>
                        <option value="sfq">sfq</option>
                        <option value="red">red</option>
                    </select>
                </div>
                <div v-if="typeForm.kind === 'pcq'" class="space-y-3">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">PCQ Rate (0 = dynamic equal share)</label>
                        <input
                            v-model="typeForm.pcq_rate"
                            type="text"
                            placeholder="0 or rate e.g. 5M"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">PCQ Classifier</label>
                        <select
                            v-model="typeForm.pcq_classifier"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <option value="dst-address">dst-address (download)</option>
                            <option value="src-address">src-address (upload)</option>
                            <option value="dst-address,dst-port">dst-address + dst-port</option>
                            <option value="src-address,src-port">src-address + src-port</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn type="button" variant="ghost" @click="showAddType = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="typeForm.processing">Save Type</Btn>
                </div>
            </form>
        </Modal>
    </RouterWorkspace>
</template>
