<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface FilterRule {
    '.id': string;
    chain: string;
    action: string;
    protocol?: string;
    'src-address'?: string;
    'dst-address'?: string;
    'dst-port'?: string;
    'in-interface'?: string;
    'out-interface'?: string;
    disabled?: string;
    comment?: string;
    bytes?: string;
    packets?: string;
}

interface NatRule {
    '.id': string;
    chain: string;
    action: string;
    protocol?: string;
    'src-address'?: string;
    'dst-address'?: string;
    'dst-port'?: string;
    'to-addresses'?: string;
    'to-ports'?: string;
    'in-interface'?: string;
    'out-interface'?: string;
    disabled?: string;
    comment?: string;
    bytes?: string;
    packets?: string;
}

interface MangleRule {
    '.id': string;
    chain: string;
    action: string;
    'new-routing-mark'?: string;
    'new-packet-mark'?: string;
    'new-connection-mark'?: string;
    passthrough?: string;
    'src-address'?: string;
    'dst-address'?: string;
    disabled?: string;
    comment?: string;
    bytes?: string;
}

interface AddressListEntry {
    '.id': string;
    list: string;
    address: string;
    timeout?: string;
    dynamic?: string;
    disabled?: string;
    comment?: string;
}

interface ConnectionEntry {
    '.id': string;
    protocol?: string;
    'src-address'?: string;
    'dst-address'?: string;
    'reply-src-address'?: string;
    'reply-dst-address'?: string;
    'tcp-state'?: string;
    timeout?: string;
}

const props = defineProps<{
    router: any;
    rules?: FilterRule[];
    filterRules?: FilterRule[];
    natRules?: NatRule[];
    mangleRules?: MangleRule[];
    addressLists?: AddressListEntry[];
    connections?: ConnectionEntry[];
    interfaces?: string[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

const actualFilters = computed(() => props.filterRules ?? props.rules ?? []);
const actualNats = computed(() => props.natRules ?? []);
const actualMangles = computed(() => props.mangleRules ?? []);
const actualAddressLists = computed(() => props.addressLists ?? []);
const actualConnections = computed(() => props.connections ?? []);

type FwTab = 'filter' | 'nat' | 'mangle' | 'address-list' | 'connections';
const activeTab = ref<FwTab>('filter');

const selectedId = ref<string | null>(null);
const search = ref('');
const statusFilter = ref<'all' | 'enabled' | 'disabled'>('all');
const chainFilter = ref<string>('all');
const actionFilter = ref<string>('all');

// ── Multi-selection for Batch Operations ─────────────────────────────
const selectedFilterIds = ref<string[]>([]);
const selectedNatIds = ref<string[]>([]);
const isProcessingBatch = ref(false);

// ── Top KPI Statistics Summary ───────────────────────────────────────
const stats = computed(() => {
    const totalFilters = actualFilters.value.length;
    const activeFilters = actualFilters.value.filter((r) => r.disabled !== 'true').length;
    const totalNats = actualNats.value.length;
    const srcNats = actualNats.value.filter((n) => n.chain === 'srcnat').length;
    const dstNats = actualNats.value.filter((n) => n.chain === 'dstnat').length;
    const totalMangles = actualMangles.value.length;
    const totalLists = actualAddressLists.value.length;
    const totalConns = actualConnections.value.length;

    return {
        totalFilters,
        activeFilters,
        totalNats,
        srcNats,
        dstNats,
        totalMangles,
        totalLists,
        totalConns,
    };
});

// ── Modals ───────────────────────────────────────────────────────────
const showAddFilter = ref(false);
const showAddNat = ref(false);
const showAddMangle = ref(false);
const showAddAddressList = ref(false);

const filterForm = useForm({
    chain: 'forward',
    action: 'accept',
    protocol: '',
    src_address: '',
    dst_address: '',
    dst_port: '',
    in_interface: '',
    out_interface: '',
    comment: '',
});

const natForm = useForm({
    chain: 'srcnat',
    action: 'masquerade',
    protocol: '',
    src_address: '',
    dst_address: '',
    dst_port: '',
    to_addresses: '',
    to_ports: '',
    in_interface: '',
    out_interface: '',
    comment: '',
});

const mangleForm = useForm({
    chain: 'prerouting',
    action: 'mark-routing',
    new_routing_mark: '',
    new_packet_mark: '',
    passthrough: 'yes',
    src_address: '',
    dst_address: '',
    comment: '',
});

const addressListForm = useForm({
    list: '',
    address: '',
    timeout: '',
    comment: '',
});

// ── Filtered Datasets ────────────────────────────────────────────────
const filteredFilters = computed(() => {
    const q = search.value.trim().toLowerCase();
    return actualFilters.value.filter((r) => {
        const isDis = r.disabled === 'true';
        if (statusFilter.value === 'enabled' && isDis) return false;
        if (statusFilter.value === 'disabled' && !isDis) return false;
        if (chainFilter.value !== 'all' && r.chain !== chainFilter.value) return false;
        if (actionFilter.value !== 'all' && r.action !== actionFilter.value) return false;

        if (!q) return true;
        return (
            r.chain.toLowerCase().includes(q) ||
            r.action.toLowerCase().includes(q) ||
            (r['src-address'] ?? '').toLowerCase().includes(q) ||
            (r['dst-address'] ?? '').toLowerCase().includes(q) ||
            (r.protocol ?? '').toLowerCase().includes(q) ||
            (r.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredNats = computed(() => {
    const q = search.value.trim().toLowerCase();
    return actualNats.value.filter((r) => {
        const isDis = r.disabled === 'true';
        if (statusFilter.value === 'enabled' && isDis) return false;
        if (statusFilter.value === 'disabled' && !isDis) return false;
        if (chainFilter.value !== 'all' && r.chain !== chainFilter.value) return false;
        if (actionFilter.value !== 'all' && r.action !== actionFilter.value) return false;

        if (!q) return true;
        return (
            r.chain.toLowerCase().includes(q) ||
            r.action.toLowerCase().includes(q) ||
            (r['to-addresses'] ?? '').toLowerCase().includes(q) ||
            (r['src-address'] ?? '').toLowerCase().includes(q) ||
            (r['dst-address'] ?? '').toLowerCase().includes(q) ||
            (r.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredMangles = computed(() => {
    const q = search.value.trim().toLowerCase();
    return actualMangles.value.filter((r) => {
        const isDis = r.disabled === 'true';
        if (statusFilter.value === 'enabled' && isDis) return false;
        if (statusFilter.value === 'disabled' && !isDis) return false;
        if (!q) return true;
        return (
            r.chain.toLowerCase().includes(q) ||
            r.action.toLowerCase().includes(q) ||
            (r['new-routing-mark'] ?? '').toLowerCase().includes(q) ||
            (r['new-packet-mark'] ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredAddressLists = computed(() => {
    const q = search.value.trim().toLowerCase();
    return actualAddressLists.value.filter((r) => {
        const isDis = r.disabled === 'true';
        if (statusFilter.value === 'enabled' && isDis) return false;
        if (statusFilter.value === 'disabled' && !isDis) return false;
        if (!q) return true;
        return r.list.toLowerCase().includes(q) || r.address.toLowerCase().includes(q) || (r.comment ?? '').toLowerCase().includes(q);
    });
});

const filteredConnections = computed(() => {
    const q = search.value.trim().toLowerCase();
    return actualConnections.value.filter((c) => {
        if (!q) return true;
        return (
            (c['src-address'] ?? '').toLowerCase().includes(q) ||
            (c['dst-address'] ?? '').toLowerCase().includes(q) ||
            (c.protocol ?? '').toLowerCase().includes(q) ||
            (c['tcp-state'] ?? '').toLowerCase().includes(q)
        );
    });
});

// ── Pagination for Filter & NAT Rules ────────────────────────────────
const filterPerPage = ref<number>(25);
const filterPage = ref(1);

const totalFilterPages = computed(() => {
    if (filterPerPage.value === 0) return 1;
    return Math.ceil(filteredFilters.value.length / filterPerPage.value) || 1;
});

const paginatedFilters = computed(() => {
    if (filterPerPage.value === 0) return filteredFilters.value;
    const start = (filterPage.value - 1) * filterPerPage.value;
    return filteredFilters.value.slice(start, start + filterPerPage.value);
});

const natPerPage = ref<number>(25);
const natPage = ref(1);

const totalNatPages = computed(() => {
    if (natPerPage.value === 0) return 1;
    return Math.ceil(filteredNats.value.length / natPerPage.value) || 1;
});

const paginatedNats = computed(() => {
    if (natPerPage.value === 0) return filteredNats.value;
    const start = (natPage.value - 1) * natPerPage.value;
    return filteredNats.value.slice(start, start + natPerPage.value);
});

watch([search, statusFilter, chainFilter, actionFilter, filterPerPage, natPerPage, activeTab], () => {
    filterPage.value = 1;
    natPage.value = 1;
    chainFilter.value = 'all';
    actionFilter.value = 'all';
});

// ── Checkbox Selection States ────────────────────────────────────────
const isAllFiltersSelected = computed(() => {
    if (paginatedFilters.value.length === 0) return false;
    return paginatedFilters.value.every((r) => selectedFilterIds.value.includes(r['.id']));
});

function toggleSelectAllFilters() {
    if (isAllFiltersSelected.value) {
        const currentIds = new Set(paginatedFilters.value.map((r) => r['.id']));
        selectedFilterIds.value = selectedFilterIds.value.filter((id) => !currentIds.has(id));
    } else {
        const newIds = new Set([...selectedFilterIds.value, ...paginatedFilters.value.map((r) => r['.id'])]);
        selectedFilterIds.value = Array.from(newIds);
    }
}

function toggleSelectFilter(id: string) {
    const idx = selectedFilterIds.value.indexOf(id);
    if (idx >= 0) selectedFilterIds.value.splice(idx, 1);
    else selectedFilterIds.value.push(id);
}

const isAllNatsSelected = computed(() => {
    if (paginatedNats.value.length === 0) return false;
    return paginatedNats.value.every((r) => selectedNatIds.value.includes(r['.id']));
});

function toggleSelectAllNats() {
    if (isAllNatsSelected.value) {
        const currentIds = new Set(paginatedNats.value.map((r) => r['.id']));
        selectedNatIds.value = selectedNatIds.value.filter((id) => !currentIds.has(id));
    } else {
        const newIds = new Set([...selectedNatIds.value, ...paginatedNats.value.map((r) => r['.id'])]);
        selectedNatIds.value = Array.from(newIds);
    }
}

function toggleSelectNat(id: string) {
    const idx = selectedNatIds.value.indexOf(id);
    if (idx >= 0) selectedNatIds.value.splice(idx, 1);
    else selectedNatIds.value.push(id);
}

// ── Batch Operations ─────────────────────────────────────────────────
function batchFiltersAction(action: 'enable' | 'disable' | 'destroy') {
    if (selectedFilterIds.value.length === 0) return;
    if (action === 'destroy' && !confirm(`Hapus ${selectedFilterIds.value.length} filter rule terpilih?`)) return;

    isProcessingBatch.value = true;
    inertia.post(
        route('routers.firewall.batch', props.router.id),
        { action, ids: selectedFilterIds.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isProcessingBatch.value = false;
                selectedFilterIds.value = [];
                selectedId.value = null;
            },
        },
    );
}

function batchNatAction(action: 'enable' | 'disable' | 'destroy') {
    if (selectedNatIds.value.length === 0) return;
    if (action === 'destroy' && !confirm(`Hapus ${selectedNatIds.value.length} NAT rule terpilih?`)) return;

    isProcessingBatch.value = true;
    inertia.post(
        route('routers.firewall.nat.batch', props.router.id),
        { action, ids: selectedNatIds.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isProcessingBatch.value = false;
                selectedNatIds.value = [];
                selectedId.value = null;
            },
        },
    );
}

// ── Reset Counters ───────────────────────────────────────────────────
function resetFilterCounter(item?: FilterRule) {
    const id = item ? item['.id'] : null;
    const msg = id ? `Reset counter firewall rule #${id}?` : `Reset SELURUH counter filter rules?`;
    if (confirm(msg)) {
        inertia.post(
            route('routers.firewall.reset-counters', props.router.id),
            id ? { id } : {},
            { preserveScroll: true },
        );
    }
}

function resetNatCounter(item?: NatRule) {
    const id = item ? item['.id'] : null;
    const msg = id ? `Reset counter NAT rule #${id}?` : `Reset SELURUH counter NAT rules?`;
    if (confirm(msg)) {
        inertia.post(
            route('routers.firewall.nat.reset-counters', props.router.id),
            id ? { id } : {},
            { preserveScroll: true },
        );
    }
}

// ── Selected Item ────────────────────────────────────────────────────
const selectedItem = computed(() => {
    if (!selectedId.value) return null;
    if (activeTab.value === 'filter') return actualFilters.value.find((r) => r['.id'] === selectedId.value) ?? null;
    if (activeTab.value === 'nat') return actualNats.value.find((r) => r['.id'] === selectedId.value) ?? null;
    if (activeTab.value === 'mangle') return actualMangles.value.find((r) => r['.id'] === selectedId.value) ?? null;
    if (activeTab.value === 'address-list') return actualAddressLists.value.find((r) => r['.id'] === selectedId.value) ?? null;
    return null;
});

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
function downloadCsv(headers: string[], rows: (string | number)[][], filename: string) {
    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportFiltersCsv() {
    const headers = ['Chain', 'Action', 'Src Address', 'Dst Address', 'Protocol', 'Dst Port', 'In Interface', 'Out Interface', 'Bytes', 'Packets', 'Status', 'Comment'];
    const rows = filteredFilters.value.map((r) => [
        `"${r.chain}"`,
        `"${r.action}"`,
        `"${r['src-address'] || 'any'}"`,
        `"${r['dst-address'] || 'any'}"`,
        `"${r.protocol || 'all'}"`,
        `"${r['dst-port'] || ''}"`,
        `"${r['in-interface'] || ''}"`,
        `"${r['out-interface'] || ''}"`,
        `"${r.bytes || '0'}"`,
        `"${r.packets || '0'}"`,
        `"${r.disabled === 'true' ? 'disabled' : 'enabled'}"`,
        `"${(r.comment || '').replace(/"/g, '""')}"`,
    ]);
    downloadCsv(headers, rows, `firewall-filters-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
}

function exportNatsCsv() {
    const headers = ['Chain', 'Action', 'Src Address', 'Dst Address', 'To Addresses', 'To Ports', 'Out Interface', 'Bytes', 'Status', 'Comment'];
    const rows = filteredNats.value.map((r) => [
        `"${r.chain}"`,
        `"${r.action}"`,
        `"${r['src-address'] || 'any'}"`,
        `"${r['dst-address'] || 'any'}"`,
        `"${r['to-addresses'] || ''}"`,
        `"${r['to-ports'] || ''}"`,
        `"${r['out-interface'] || ''}"`,
        `"${r.bytes || '0'}"`,
        `"${r.disabled === 'true' ? 'disabled' : 'enabled'}"`,
        `"${(r.comment || '').replace(/"/g, '""')}"`,
    ]);
    downloadCsv(headers, rows, `firewall-nat-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
}

function exportManglesCsv() {
    const headers = ['Chain', 'Action', 'New Mark', 'Passthrough', 'Status', 'Comment'];
    const rows = filteredMangles.value.map((r) => [
        `"${r.chain}"`,
        `"${r.action}"`,
        `"${r['new-routing-mark'] || r['new-packet-mark'] || r['new-connection-mark'] || ''}"`,
        `"${r.passthrough || 'yes'}"`,
        `"${r.disabled === 'true' ? 'disabled' : 'enabled'}"`,
        `"${(r.comment || '').replace(/"/g, '""')}"`,
    ]);
    downloadCsv(headers, rows, `firewall-mangle-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
}

function exportAddressListsCsv() {
    const headers = ['List Name', 'Address', 'Timeout', 'Status', 'Comment'];
    const rows = filteredAddressLists.value.map((r) => [
        `"${r.list}"`,
        `"${r.address}"`,
        `"${r.timeout || 'permanent'}"`,
        `"${r.disabled === 'true' ? 'disabled' : 'enabled'}"`,
        `"${(r.comment || '').replace(/"/g, '""')}"`,
    ]);
    downloadCsv(headers, rows, `firewall-address-lists-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
}

function exportConnectionsCsv() {
    const headers = ['Protocol', 'Src Address', 'Dst Address', 'Reply Src', 'Reply Dst', 'TCP State', 'Timeout'];
    const rows = filteredConnections.value.map((c) => [
        `"${c.protocol || 'ip'}"`,
        `"${c['src-address'] || ''}"`,
        `"${c['dst-address'] || ''}"`,
        `"${c['reply-src-address'] || ''}"`,
        `"${c['reply-dst-address'] || ''}"`,
        `"${c['tcp-state'] || ''}"`,
        `"${c.timeout || ''}"`,
    ]);
    downloadCsv(headers, rows, `firewall-connections-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
}

// ── Actions ──────────────────────────────────────────────────────────
function refreshData() {
    inertia.reload({ preserveScroll: true });
}

function openAddModal() {
    if (activeTab.value === 'filter') {
        filterForm.reset();
        showAddFilter.value = true;
    } else if (activeTab.value === 'nat') {
        natForm.reset();
        showAddNat.value = true;
    } else if (activeTab.value === 'mangle') {
        mangleForm.reset();
        showAddMangle.value = true;
    } else if (activeTab.value === 'address-list') {
        addressListForm.reset();
        showAddAddressList.value = true;
    }
}

function toggleRule(item: any, disable: boolean) {
    const url = activeTab.value === 'filter'
        ? route('routers.firewall.toggle', props.router.id)
        : activeTab.value === 'nat'
        ? route('routers.firewall.nat.toggle', props.router.id)
        : activeTab.value === 'mangle'
        ? route('routers.firewall.mangle.toggle', props.router.id)
        : route('routers.firewall.address-list.toggle', props.router.id);

    inertia.post(url, { id: item['.id'], disable }, { preserveScroll: true });
}

function removeSelected() {
    if (!selectedItem.value) return;
    const id = selectedItem.value['.id'];

    if (!confirm(`Hapus rule firewall ${id}?`)) return;

    const url = activeTab.value === 'filter'
        ? route('routers.firewall.destroy', props.router.id)
        : activeTab.value === 'nat'
        ? route('routers.firewall.nat.destroy', props.router.id)
        : activeTab.value === 'mangle'
        ? route('routers.firewall.mangle.destroy', props.router.id)
        : route('routers.firewall.address-list.destroy', props.router.id);

    inertia.delete(url, {
        data: { id },
        preserveScroll: true,
        onSuccess: () => { selectedId.value = null; },
    });
}

function saveFilter() {
    filterForm.post(route('routers.firewall.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddFilter.value = false;
            filterForm.reset();
        },
    });
}

function saveNat() {
    natForm.post(route('routers.firewall.nat.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddNat.value = false;
            natForm.reset();
        },
    });
}

function saveMangle() {
    mangleForm.post(route('routers.firewall.mangle.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddMangle.value = false;
            mangleForm.reset();
        },
    });
}

function saveAddressList() {
    addressListForm.post(route('routers.firewall.address-list.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddAddressList.value = false;
            addressListForm.reset();
        },
    });
}
</script>

<template>
    <Head :title="`Firewall & NAT — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- ── Top KPI Statistics Summary Grid ────────────────────────── -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Filter Rules Total -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Filter Rules</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.totalFilters }}</span>
                        <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">Rules</span>
                    </div>
                </div>

                <!-- Active / Running -->
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3.5 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">
                        <StatusDot color="emerald" :pulse="true" />
                        <span>Filter Aktif</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-emerald-800 dark:text-emerald-200">{{ stats.activeFilters }}</span>
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400">Enforced</span>
                    </div>
                </div>

                <!-- NAT Rules -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">NAT Rules</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.totalNats }}</span>
                        <span class="text-[10px] font-mono text-zinc-500">{{ stats.srcNats }}src / {{ stats.dstNats }}dst</span>
                    </div>
                </div>

                <!-- Mangle Rules -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Mangle Rules</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.totalMangles }}</span>
                        <span class="rounded bg-violet-50 px-1.5 py-0.5 text-[10px] font-medium text-violet-700 dark:bg-violet-950 dark:text-violet-300">Packet/Route</span>
                    </div>
                </div>

                <!-- Address Lists -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Address Lists</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.totalLists }}</span>
                        <span class="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-950 dark:text-sky-300">IP Groups</span>
                    </div>
                </div>

                <!-- Active Connections -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Active Connections</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.totalConns }}</span>
                        <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Tracked</span>
                    </div>
                </div>
            </div>

            <!-- ── Main WinBox Window Card ────────────────────────────────── -->
            <div class="flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- WinBox Header with Tabs -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold text-indigo-600 dark:text-indigo-400">🛡️</span>
                            <h2 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">Firewall & Security Rules</h2>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /ip/firewall
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                                <StatusDot color="emerald" :pulse="true" />
                                {{ stats.activeFilters }} Active Rules
                            </span>
                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                Chain Order Preserved
                            </span>
                        </div>
                    </div>

                    <div class="flex gap-1 overflow-x-auto text-xs">
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'filter'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'filter'; selectedId = null"
                        >
                            Filter Rules ({{ actualFilters.length }})
                        </button>
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'nat'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'nat'; selectedId = null"
                        >
                            NAT ({{ actualNats.length }})
                        </button>
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'mangle'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'mangle'; selectedId = null"
                        >
                            Mangle ({{ actualMangles.length }})
                        </button>
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'address-list'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'address-list'; selectedId = null"
                        >
                            Address Lists ({{ actualAddressLists.length }})
                        </button>
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'connections'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'connections'; selectedId = null"
                        >
                            Connections ({{ actualConnections.length }})
                        </button>
                    </div>
                </div>

                <!-- WinBox Action Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage && activeTab !== 'connections'" size="sm" variant="primary" class="gap-1.5" @click="openAddModal">
                            <span>＋</span> Add
                        </Btn>

                        <template v-if="can.manage && selectedItem">
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedItem.disabled === 'false'"
                                @click="toggleRule(selectedItem, false)"
                            >
                                ✓ Enable
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="selectedItem.disabled === 'true'"
                                @click="toggleRule(selectedItem, true)"
                            >
                                ✕ Disable
                            </Btn>
                            <Btn
                                v-if="activeTab === 'filter'"
                                size="sm"
                                variant="secondary"
                                title="Reset rule counters"
                                @click="resetFilterCounter(selectedItem)"
                            >
                                0 Reset Counter
                            </Btn>
                            <Btn
                                v-if="activeTab === 'nat'"
                                size="sm"
                                variant="secondary"
                                title="Reset NAT counters"
                                @click="resetNatCounter(selectedItem)"
                            >
                                0 Reset Counter
                            </Btn>
                        </template>

                        <Btn
                            v-if="can.manage && activeTab !== 'connections'"
                            size="sm"
                            variant="secondary"
                            class="text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/30"
                            :disabled="!selectedItem"
                            @click="removeSelected"
                        >
                            🗑 Remove
                        </Btn>

                        <div class="mx-1 h-4 w-px bg-zinc-300 dark:bg-zinc-700"></div>

                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh
                        </Btn>

                        <!-- Reset All Counters -->
                        <Btn
                            v-if="can.manage && activeTab === 'filter'"
                            size="sm"
                            variant="ghost"
                            class="text-zinc-600 dark:text-zinc-300"
                            title="Reset seluruh counter filter"
                            @click="resetFilterCounter()"
                        >
                            0 Reset All
                        </Btn>
                        <Btn
                            v-if="can.manage && activeTab === 'nat'"
                            size="sm"
                            variant="ghost"
                            class="text-zinc-600 dark:text-zinc-300"
                            title="Reset seluruh counter NAT"
                            @click="resetNatCounter()"
                        >
                            0 Reset All
                        </Btn>

                        <!-- CSV Export Button -->
                        <Btn
                            size="sm"
                            variant="ghost"
                            class="gap-1.5 text-zinc-600 dark:text-zinc-300"
                            title="Export data ke format CSV"
                            @click="activeTab === 'filter' ? exportFiltersCsv() : activeTab === 'nat' ? exportNatsCsv() : activeTab === 'mangle' ? exportManglesCsv() : activeTab === 'address-list' ? exportAddressListsCsv() : exportConnectionsCsv()"
                        >
                            <span>📥</span> Export CSV
                        </Btn>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Chain filter pills for Filter Rules -->
                        <div v-if="activeTab === 'filter'" class="flex items-center rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800 text-[11px]">
                            <button
                                type="button"
                                class="rounded-md px-2 py-0.5 transition-all"
                                :class="chainFilter === 'all' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="chainFilter = 'all'"
                            >
                                All Chains
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2 py-0.5 transition-all"
                                :class="chainFilter === 'forward' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="chainFilter = 'forward'"
                            >
                                forward
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2 py-0.5 transition-all"
                                :class="chainFilter === 'input' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="chainFilter = 'input'"
                            >
                                input
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2 py-0.5 transition-all"
                                :class="chainFilter === 'output' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="chainFilter = 'output'"
                            >
                                output
                            </button>
                        </div>

                        <!-- Chain filter pills for NAT Rules -->
                        <div v-if="activeTab === 'nat'" class="flex items-center rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800 text-[11px]">
                            <button
                                type="button"
                                class="rounded-md px-2 py-0.5 transition-all"
                                :class="chainFilter === 'all' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="chainFilter = 'all'"
                            >
                                All Chains
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2 py-0.5 transition-all"
                                :class="chainFilter === 'srcnat' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="chainFilter = 'srcnat'"
                            >
                                srcnat
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2 py-0.5 transition-all"
                                :class="chainFilter === 'dstnat' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="chainFilter = 'dstnat'"
                            >
                                dstnat
                            </button>
                        </div>

                        <select
                            v-if="activeTab !== 'connections'"
                            v-model="statusFilter"
                            class="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <option value="all">Semua Status</option>
                            <option value="enabled">Aktif (Enabled)</option>
                            <option value="disabled">Nonaktif (Disabled)</option>
                        </select>

                        <div class="relative">
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Cari rule..."
                                class="w-44 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 text-xs focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                            />
                            <span v-if="search" class="absolute right-2 top-1 cursor-pointer text-xs text-zinc-400 hover:text-zinc-600" @click="search = ''">✕</span>
                        </div>
                    </div>
                </div>

                <!-- ── Batch Actions Sticky Floating Bar for Filter Rules ──────── -->
                <transition
                    enter-active-class="transition duration-150 ease-out"
                    enter-from-class="opacity-0 -translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 -translate-y-2"
                >
                    <div
                        v-if="can.manage && activeTab === 'filter' && selectedFilterIds.length > 0"
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/95 px-4 py-2 backdrop-blur-xs dark:border-indigo-900/60 dark:bg-indigo-950/80"
                    >
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white dark:bg-indigo-500">
                                {{ selectedFilterIds.length }}
                            </span>
                            <span class="text-xs font-semibold text-indigo-950 dark:text-indigo-100">
                                Filter rule dipilih
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-emerald-700 shadow-xs hover:bg-emerald-50 dark:bg-zinc-800 dark:text-emerald-300 dark:hover:bg-zinc-700"
                                @click="batchFiltersAction('enable')"
                            >
                                ✓ Enable
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-amber-700 shadow-xs hover:bg-amber-50 dark:bg-zinc-800 dark:text-amber-300 dark:hover:bg-zinc-700"
                                @click="batchFiltersAction('disable')"
                            >
                                ✕ Disable
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-rose-700 shadow-xs hover:bg-rose-50 dark:bg-zinc-800 dark:text-rose-300 dark:hover:bg-zinc-700"
                                @click="batchFiltersAction('destroy')"
                            >
                                🗑 Hapus
                            </button>
                            <button
                                type="button"
                                class="text-xs text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
                                @click="selectedFilterIds = []"
                            >
                                Batal
                            </button>
                        </div>
                    </div>
                </transition>

                <!-- ── Batch Actions Sticky Floating Bar for NAT Rules ─────────── -->
                <transition
                    enter-active-class="transition duration-150 ease-out"
                    enter-from-class="opacity-0 -translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 -translate-y-2"
                >
                    <div
                        v-if="can.manage && activeTab === 'nat' && selectedNatIds.length > 0"
                        class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/95 px-4 py-2 backdrop-blur-xs dark:border-indigo-900/60 dark:bg-indigo-950/80"
                    >
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white dark:bg-indigo-500">
                                {{ selectedNatIds.length }}
                            </span>
                            <span class="text-xs font-semibold text-indigo-950 dark:text-indigo-100">
                                NAT rule dipilih
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-emerald-700 shadow-xs hover:bg-emerald-50 dark:bg-zinc-800 dark:text-emerald-300 dark:hover:bg-zinc-700"
                                @click="batchNatAction('enable')"
                            >
                                ✓ Enable
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-amber-700 shadow-xs hover:bg-amber-50 dark:bg-zinc-800 dark:text-amber-300 dark:hover:bg-zinc-700"
                                @click="batchNatAction('disable')"
                            >
                                ✕ Disable
                            </button>
                            <button
                                type="button"
                                :disabled="isProcessingBatch"
                                class="inline-flex items-center gap-1 rounded-md bg-white px-2.5 py-1 text-xs font-medium text-rose-700 shadow-xs hover:bg-rose-50 dark:bg-zinc-800 dark:text-rose-300 dark:hover:bg-zinc-700"
                                @click="batchNatAction('destroy')"
                            >
                                🗑 Hapus
                            </button>
                            <button
                                type="button"
                                class="text-xs text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
                                @click="selectedNatIds = []"
                            >
                                Batal
                            </button>
                        </div>
                    </div>
                </transition>

                <!-- Table View -->
                <div class="flex-1 overflow-auto">
                    <!-- 1. Filter Rules -->
                    <table v-if="activeTab === 'filter'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th v-if="can.manage" class="w-8 px-3 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        :checked="isAllFiltersSelected"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelectAllFilters"
                                    />
                                </th>
                                <th class="w-8 px-3 py-2 text-center">#</th>
                                <th class="px-3 py-2">Chain</th>
                                <th class="px-3 py-2">Action</th>
                                <th class="px-3 py-2">Src Address</th>
                                <th class="px-3 py-2">Dst Address</th>
                                <th class="px-3 py-2">Protocol</th>
                                <th class="px-3 py-2">Dst Port</th>
                                <th class="px-3 py-2">In/Out Interface</th>
                                <th class="px-3 py-2">Traffic (Bytes)</th>
                                <th class="px-3 py-2">Comment</th>
                                <th class="px-3 py-2 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="(r, idx) in paginatedFilters"
                                :key="r['.id']"
                                class="group cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedId === r['.id'] ? 'bg-indigo-100/60 dark:bg-indigo-950/40' : '',
                                    selectedFilterIds.includes(r['.id']) ? 'bg-indigo-50/80 dark:bg-indigo-950/50' : '',
                                    r.disabled === 'true' ? 'opacity-50 line-through' : ''
                                ]"
                                @click="selectedId = r['.id']"
                            >
                                <td v-if="can.manage" class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="selectedFilterIds.includes(r['.id'])"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelectFilter(r['.id'])"
                                    />
                                </td>
                                <td class="px-3 py-2 text-center font-sans text-zinc-400">
                                    <StatusDot :color="r.disabled === 'true' ? 'zinc' : 'emerald'" />
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ r.chain }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-semibold" :class="r.action === 'drop' || r.action === 'reject' ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">
                                    {{ r.action }}
                                </td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">
                                    <div class="flex items-center gap-1">
                                        <span>{{ r['src-address'] || 'any' }}</span>
                                        <button
                                            v-if="r['src-address']"
                                            type="button"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] text-zinc-400 hover:text-indigo-600"
                                            @click.stop="copyText(r['src-address'], 'src-' + r['.id'])"
                                        >
                                            {{ copiedKey === 'src-' + r['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">
                                    <div class="flex items-center gap-1">
                                        <span>{{ r['dst-address'] || 'any' }}</span>
                                        <button
                                            v-if="r['dst-address']"
                                            type="button"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] text-zinc-400 hover:text-indigo-600"
                                            @click.stop="copyText(r['dst-address'], 'dst-' + r['.id'])"
                                        >
                                            {{ copiedKey === 'dst-' + r['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ r.protocol || 'all' }}</td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ r['dst-port'] || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ r['in-interface'] || r['out-interface'] || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ r.bytes || '0' }}</td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ r.comment || '-' }}</td>
                                <td class="px-3 py-2 text-right" @click.stop>
                                    <div class="flex items-center justify-end gap-1 font-sans">
                                        <button
                                            v-if="can.manage"
                                            type="button"
                                            class="rounded px-1.5 py-0.5 text-[10px] font-medium transition-colors"
                                            :class="r.disabled === 'true'
                                                ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300'
                                                : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300'"
                                            @click="toggleRule(r, r.disabled !== 'true')"
                                        >
                                            {{ r.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </button>
                                        <button
                                            v-if="can.manage"
                                            type="button"
                                            class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300"
                                            title="Reset Counter"
                                            @click="resetFilterCounter(r)"
                                        >
                                            0
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="filteredFilters.length === 0">
                                <td colspan="12" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No firewall filter rules found matching criteria.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 2. NAT Rules -->
                    <table v-else-if="activeTab === 'nat'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th v-if="can.manage" class="w-8 px-3 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        :checked="isAllNatsSelected"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelectAllNats"
                                    />
                                </th>
                                <th class="w-8 px-3 py-2 text-center">#</th>
                                <th class="px-3 py-2">Chain</th>
                                <th class="px-3 py-2">Action</th>
                                <th class="px-3 py-2">Src Address</th>
                                <th class="px-3 py-2">Dst Address</th>
                                <th class="px-3 py-2">To Addresses</th>
                                <th class="px-3 py-2">To Ports</th>
                                <th class="px-3 py-2">Out Interface</th>
                                <th class="px-3 py-2">Bytes</th>
                                <th class="px-3 py-2">Comment</th>
                                <th class="px-3 py-2 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="(r, idx) in paginatedNats"
                                :key="r['.id']"
                                class="group cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedId === r['.id'] ? 'bg-indigo-100/60 dark:bg-indigo-950/40' : '',
                                    selectedNatIds.includes(r['.id']) ? 'bg-indigo-50/80 dark:bg-indigo-950/50' : '',
                                    r.disabled === 'true' ? 'opacity-50 line-through' : ''
                                ]"
                                @click="selectedId = r['.id']"
                            >
                                <td v-if="can.manage" class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="selectedNatIds.includes(r['.id'])"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelectNat(r['.id'])"
                                    />
                                </td>
                                <td class="px-3 py-2 text-center font-sans">
                                    <StatusDot :color="r.disabled === 'true' ? 'zinc' : 'emerald'" />
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ r.chain }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-semibold text-indigo-600 dark:text-indigo-400">{{ r.action }}</td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ r['src-address'] || 'any' }}</td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ r['dst-address'] || 'any' }}</td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ r['to-addresses'] || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ r['to-ports'] || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ r['out-interface'] || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ r.bytes || '0' }}</td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ r.comment || '-' }}</td>
                                <td class="px-3 py-2 text-right" @click.stop>
                                    <div class="flex items-center justify-end gap-1 font-sans">
                                        <button
                                            v-if="can.manage"
                                            type="button"
                                            class="rounded px-1.5 py-0.5 text-[10px] font-medium transition-colors"
                                            :class="r.disabled === 'true'
                                                ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300'
                                                : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300'"
                                            @click="toggleRule(r, r.disabled !== 'true')"
                                        >
                                            {{ r.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </button>
                                        <button
                                            v-if="can.manage"
                                            type="button"
                                            class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300"
                                            title="Reset Counter"
                                            @click="resetNatCounter(r)"
                                        >
                                            0
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="filteredNats.length === 0">
                                <td colspan="12" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No NAT rules found.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 3. Mangle Rules -->
                    <table v-else-if="activeTab === 'mangle'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">#</th>
                                <th class="px-3 py-2">Chain</th>
                                <th class="px-3 py-2">Action</th>
                                <th class="px-3 py-2">New Routing / Packet Mark</th>
                                <th class="px-3 py-2">Passthrough</th>
                                <th class="px-3 py-2">Bytes</th>
                                <th class="px-3 py-2">Comment</th>
                                <th class="px-3 py-2 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="r in filteredMangles"
                                :key="r['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedId === r['.id'] ? 'bg-indigo-100/60 dark:bg-indigo-950/40' : '',
                                    r.disabled === 'true' ? 'opacity-50 line-through' : ''
                                ]"
                                @click="selectedId = r['.id']"
                            >
                                <td class="px-3 py-2 text-center font-sans">
                                    <StatusDot :color="r.disabled === 'true' ? 'zinc' : 'emerald'" />
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ r.chain }}</td>
                                <td class="px-3 py-2 font-semibold text-indigo-600 dark:text-indigo-400">{{ r.action }}</td>
                                <td class="px-3 py-2 text-zinc-700 dark:text-zinc-300">
                                    {{ r['new-routing-mark'] || r['new-packet-mark'] || r['new-connection-mark'] || '-' }}
                                </td>
                                <td class="px-3 py-2 text-zinc-500">{{ r.passthrough || 'yes' }}</td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ r.bytes || '0' }}</td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ r.comment || '-' }}</td>
                                <td class="px-3 py-2 text-right" @click.stop>
                                    <button
                                        v-if="can.manage"
                                        type="button"
                                        class="rounded px-2 py-0.5 font-sans text-[10px] font-medium"
                                        :class="r.disabled === 'true' ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-600'"
                                        @click="toggleRule(r, r.disabled !== 'true')"
                                    >
                                        {{ r.disabled === 'true' ? 'Enable' : 'Disable' }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="filteredMangles.length === 0">
                                <td colspan="8" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No mangle rules found.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 4. Address Lists -->
                    <table v-else-if="activeTab === 'address-list'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">#</th>
                                <th class="px-3 py-2">List Name</th>
                                <th class="px-3 py-2">Address</th>
                                <th class="px-3 py-2">Timeout</th>
                                <th class="px-3 py-2">Comment</th>
                                <th class="px-3 py-2 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="r in filteredAddressLists"
                                :key="r['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedId === r['.id'] ? 'bg-indigo-100/60 dark:bg-indigo-950/40' : '',
                                    r.disabled === 'true' ? 'opacity-50 line-through' : ''
                                ]"
                                @click="selectedId = r['.id']"
                            >
                                <td class="px-3 py-2 text-center font-sans">
                                    <StatusDot :color="r.disabled === 'true' ? 'zinc' : 'emerald'" />
                                </td>
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ r.list }}</td>
                                <td class="px-3 py-2 text-indigo-700 dark:text-indigo-400">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ r.address }}</span>
                                        <button
                                            type="button"
                                            class="text-[10px] text-zinc-400 hover:text-indigo-600"
                                            @click.stop="copyText(r.address, 'addr-' + r['.id'])"
                                        >
                                            {{ copiedKey === 'addr-' + r['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-500">{{ r.timeout || 'permanent' }}</td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ r.comment || '-' }}</td>
                                <td class="px-3 py-2 text-right" @click.stop>
                                    <button
                                        v-if="can.manage"
                                        type="button"
                                        class="rounded px-2 py-0.5 font-sans text-[10px] font-medium"
                                        :class="r.disabled === 'true' ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-600'"
                                        @click="toggleRule(r, r.disabled !== 'true')"
                                    >
                                        {{ r.disabled === 'true' ? 'Enable' : 'Disable' }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="filteredAddressLists.length === 0">
                                <td colspan="6" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No address lists defined.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 5. Active Connections -->
                    <table v-else-if="activeTab === 'connections'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="px-3 py-2">Protocol</th>
                                <th class="px-3 py-2">Source</th>
                                <th class="px-3 py-2">Destination</th>
                                <th class="px-3 py-2">Reply Source</th>
                                <th class="px-3 py-2">Reply Destination</th>
                                <th class="px-3 py-2">TCP State</th>
                                <th class="px-3 py-2">Timeout</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr
                                v-for="c in filteredConnections"
                                :key="c['.id']"
                                class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40"
                            >
                                <td class="px-3 py-2 font-semibold uppercase text-zinc-700 dark:text-zinc-300">{{ c.protocol || 'ip' }}</td>
                                <td class="px-3 py-2 text-indigo-700 dark:text-indigo-400">{{ c['src-address'] }}</td>
                                <td class="px-3 py-2 text-zinc-900 dark:text-zinc-100">{{ c['dst-address'] }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ c['reply-src-address'] }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ c['reply-dst-address'] }}</td>
                                <td class="px-3 py-2 capitalize text-zinc-600 dark:text-zinc-400">{{ c['tcp-state'] || '-' }}</td>
                                <td class="px-3 py-2 text-zinc-500">{{ c.timeout || '-' }}</td>
                            </tr>
                            <tr v-if="filteredConnections.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                    No active connections.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── Pagination & WinBox Status Footer ──────────────────────── -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50/80 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-4">
                        <span>
                            Total: <strong>{{ activeTab === 'filter' ? filteredFilters.length : activeTab === 'nat' ? filteredNats.length : activeTab === 'mangle' ? filteredMangles.length : activeTab === 'address-list' ? filteredAddressLists.length : filteredConnections.length }}</strong> rules
                        </span>
                        <span v-if="selectedItem" class="font-mono text-indigo-600 dark:text-indigo-400">
                            Selected: {{ selectedItem['.id'] }}
                        </span>
                    </div>

                    <!-- Pagination Controls for Filter Tab -->
                    <div v-if="activeTab === 'filter'" class="flex items-center gap-2">
                        <div class="flex items-center gap-1.5 text-xs text-zinc-500">
                            <span>Per halaman:</span>
                            <select
                                v-model.number="filterPerPage"
                                class="rounded border border-zinc-300 bg-white px-2 py-0.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option :value="15">15</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="0">Semua</option>
                            </select>
                        </div>

                        <div v-if="filterPerPage > 0" class="flex items-center gap-1">
                            <button
                                type="button"
                                :disabled="filterPage <= 1"
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-zinc-100 disabled:opacity-40 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                @click="filterPage--"
                            >
                                ‹ Prev
                            </button>
                            <span class="px-1 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                {{ filterPage }} / {{ totalFilterPages }}
                            </span>
                            <button
                                type="button"
                                :disabled="filterPage >= totalFilterPages"
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-zinc-100 disabled:opacity-40 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                @click="filterPage++"
                            >
                                Next ›
                            </button>
                        </div>
                    </div>

                    <!-- Pagination Controls for NAT Tab -->
                    <div v-if="activeTab === 'nat'" class="flex items-center gap-2">
                        <div class="flex items-center gap-1.5 text-xs text-zinc-500">
                            <span>Per halaman:</span>
                            <select
                                v-model.number="natPerPage"
                                class="rounded border border-zinc-300 bg-white px-2 py-0.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option :value="15">15</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="0">Semua</option>
                            </select>
                        </div>

                        <div v-if="natPerPage > 0" class="flex items-center gap-1">
                            <button
                                type="button"
                                :disabled="natPage <= 1"
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-zinc-100 disabled:opacity-40 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                @click="natPage--"
                            >
                                ‹ Prev
                            </button>
                            <span class="px-1 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                {{ natPage }} / {{ totalNatPages }}
                            </span>
                            <button
                                type="button"
                                :disabled="natPage >= totalNatPages"
                                class="rounded border border-zinc-300 px-2 py-0.5 text-xs hover:bg-zinc-100 disabled:opacity-40 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                @click="natPage++"
                            >
                                Next ›
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: Add Filter Rule -->
        <Modal :show="showAddFilter" title="New Filter Rule" @close="showAddFilter = false">
            <form class="space-y-3" @submit.prevent="saveFilter">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Chain</label>
                        <select v-model="filterForm.chain" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="input">input</option>
                            <option value="forward">forward</option>
                            <option value="output">output</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Action</label>
                        <select v-model="filterForm.action" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="accept">accept</option>
                            <option value="drop">drop</option>
                            <option value="reject">reject</option>
                            <option value="passthrough">passthrough</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Protocol</label>
                        <input v-model="filterForm.protocol" type="text" placeholder="tcp, udp, icmp" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Dst Port</label>
                        <input v-model="filterForm.dst_port" type="text" placeholder="e.g. 80,443" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Src Address</label>
                        <input v-model="filterForm.src_address" type="text" placeholder="e.g. 192.168.88.0/24" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Dst Address</label>
                        <input v-model="filterForm.dst_address" type="text" placeholder="e.g. 10.0.0.1" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">In Interface</label>
                    <input v-model="filterForm.in_interface" type="text" placeholder="e.g. ether1" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input v-model="filterForm.comment" type="text" placeholder="Rule note" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showAddFilter = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="filterForm.processing">Add Filter Rule</Btn>
                </div>
            </form>
        </Modal>

        <!-- Modal: Add NAT Rule -->
        <Modal :show="showAddNat" title="New NAT Rule" @close="showAddNat = false">
            <form class="space-y-3" @submit.prevent="saveNat">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Chain</label>
                        <select v-model="natForm.chain" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="srcnat">srcnat</option>
                            <option value="dstnat">dstnat</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Action</label>
                        <select v-model="natForm.action" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="masquerade">masquerade</option>
                            <option value="dst-nat">dst-nat (port forward)</option>
                            <option value="src-nat">src-nat</option>
                            <option value="redirect">redirect</option>
                        </select>
                    </div>
                </div>
                <div v-if="natForm.action === 'dst-nat'" class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">To Addresses</label>
                        <input v-model="natForm.to_addresses" type="text" placeholder="e.g. 192.168.88.20" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">To Ports</label>
                        <input v-model="natForm.to_ports" type="text" placeholder="e.g. 80" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Protocol</label>
                        <input v-model="natForm.protocol" type="text" placeholder="tcp, udp" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Dst Port</label>
                        <input v-model="natForm.dst_port" type="text" placeholder="e.g. 8080" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Out Interface (for masquerade)</label>
                    <input v-model="natForm.out_interface" type="text" placeholder="e.g. ether1" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input v-model="natForm.comment" type="text" placeholder="Rule note" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showAddNat = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="natForm.processing">Add NAT Rule</Btn>
                </div>
            </form>
        </Modal>

        <!-- Modal: Add Mangle Rule -->
        <Modal :show="showAddMangle" title="New Mangle Rule" @close="showAddMangle = false">
            <form class="space-y-3" @submit.prevent="saveMangle">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Chain</label>
                        <select v-model="mangleForm.chain" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="prerouting">prerouting</option>
                            <option value="forward">forward</option>
                            <option value="postrouting">postrouting</option>
                            <option value="input">input</option>
                            <option value="output">output</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Action</label>
                        <select v-model="mangleForm.action" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="mark-routing">mark-routing</option>
                            <option value="mark-packet">mark-packet</option>
                            <option value="mark-connection">mark-connection</option>
                            <option value="change-mss">change-mss</option>
                        </select>
                    </div>
                </div>
                <div v-if="mangleForm.action === 'mark-routing'">
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">New Routing Mark</label>
                    <input v-model="mangleForm.new_routing_mark" type="text" placeholder="e.g. to_WAN2" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div v-if="mangleForm.action === 'mark-packet'">
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">New Packet Mark</label>
                    <input v-model="mangleForm.new_packet_mark" type="text" placeholder="e.g. voip_traffic" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Src Address</label>
                        <input v-model="mangleForm.src_address" type="text" placeholder="192.168.88.100" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Passthrough</label>
                        <select v-model="mangleForm.passthrough" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="yes">yes</option>
                            <option value="no">no</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input v-model="mangleForm.comment" type="text" placeholder="Mangle note" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showAddMangle = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="mangleForm.processing">Add Mangle Rule</Btn>
                </div>
            </form>
        </Modal>

        <!-- Modal: Add Address List -->
        <Modal :show="showAddAddressList" title="New Address List Entry" @close="showAddAddressList = false">
            <form class="space-y-3" @submit.prevent="saveAddressList">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">List Name</label>
                    <input v-model="addressListForm.list" type="text" required placeholder="e.g. IP_Bypass or Blocked_IPs" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">IP Address or Subnet</label>
                    <input v-model="addressListForm.address" type="text" required placeholder="e.g. 192.168.88.50 or 10.0.0.0/24" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Timeout (optional, e.g. 1d, 1h)</label>
                    <input v-model="addressListForm.timeout" type="text" placeholder="permanent if empty" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input v-model="addressListForm.comment" type="text" placeholder="Note" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showAddAddressList = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="addressListForm.processing">Add Entry</Btn>
                </div>
            </form>
        </Modal>
    </RouterWorkspace>
</template>
