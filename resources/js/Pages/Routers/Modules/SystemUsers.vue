<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface SystemUser {
    '.id': string;
    name: string;
    group: string;
    address?: string;
    'last-logged-in'?: string;
    disabled?: string;
    comment?: string;
}

interface UserGroup {
    '.id': string;
    name: string;
    policy?: string;
    comment?: string;
}

interface ActiveUser {
    '.id': string;
    name: string;
    when?: string;
    address?: string;
    via?: string;
}

const props = defineProps<{
    router: any;
    users: SystemUser[];
    groups: UserGroup[];
    activeUsers: ActiveUser[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

type UserTab = 'users' | 'groups' | 'active';
const activeTab = ref<UserTab>('users');

const selectedId = ref<string | null>(null);
const search = ref('');
const groupFilter = ref('all');
const statusFilter = ref<'all' | 'enabled' | 'disabled'>('all');

// ── Multi Selection & Batch Bar ──────────────────────────────────────────
const selectedUserIds = ref<string[]>([]);
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

// ── Sorting ─────────────────────────────────────────────────────────────
type SortField = 'name' | 'group' | 'address' | 'last-logged-in' | 'disabled';
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

// ── Modals & Forms ───────────────────────────────────────────────────────
const showAddUser = ref(false);
const showEditUser = ref(false);
const editingUser = ref<SystemUser | null>(null);

const addForm = useForm({
    name: '',
    group: 'read',
    password: '',
    address: '',
    comment: '',
});

const editForm = useForm({
    id: '',
    name: '',
    group: 'read',
    password: '',
    address: '',
    comment: '',
});

// ── KPI Statistics ───────────────────────────────────────────────────────
const kpiStats = computed(() => {
    const list = props.users ?? [];
    const active = props.activeUsers ?? [];
    return {
        total: list.length,
        full: list.filter((u) => (u.group ?? '').toLowerCase() === 'full').length,
        write: list.filter((u) => (u.group ?? '').toLowerCase() === 'write').length,
        read: list.filter((u) => (u.group ?? '').toLowerCase() === 'read').length,
        activeLogins: active.length,
        disabled: list.filter((u) => u.disabled === 'true').length,
    };
});

// ── Filtered & Sorted Users ──────────────────────────────────────────────
const filteredUsers = computed(() => {
    const q = search.value.trim().toLowerCase();
    const grp = groupFilter.value;
    const st = statusFilter.value;

    return (props.users ?? []).filter((u) => {
        if (grp !== 'all' && u.group !== grp) return false;
        if (st === 'enabled' && u.disabled === 'true') return false;
        if (st === 'disabled' && u.disabled !== 'true') return false;
        if (!q) return true;
        return (
            u.name.toLowerCase().includes(q) ||
            (u.group ?? '').toLowerCase().includes(q) ||
            (u.address ?? '').toLowerCase().includes(q) ||
            (u.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const sortedUsers = computed(() => {
    const list = [...filteredUsers.value];
    const f = sortField.value;
    const dir = sortDirection.value === 'asc' ? 1 : -1;

    return list.sort((a, b) => {
        const valA = (a[f] ?? '').toLowerCase();
        const valB = (b[f] ?? '').toLowerCase();
        return valA.localeCompare(valB) * dir;
    });
});

const totalPages = computed(() => {
    if (perPage.value <= 0) return 1;
    return Math.max(1, Math.ceil(sortedUsers.value.length / perPage.value));
});

const paginatedUsers = computed(() => {
    if (perPage.value <= 0) return sortedUsers.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedUsers.value.slice(start, start + perPage.value);
});

// ── Multi Selection Controls ─────────────────────────────────────────────
const isAllSelected = computed(() => {
    if (sortedUsers.value.length === 0) return false;
    return sortedUsers.value.every((u) => selectedUserIds.value.includes(u['.id']));
});

function toggleSelectAll() {
    if (isAllSelected.value) {
        selectedUserIds.value = [];
    } else {
        selectedUserIds.value = sortedUsers.value.map((u) => u['.id']);
    }
}

function toggleSelect(id: string) {
    const idx = selectedUserIds.value.indexOf(id);
    if (idx >= 0) {
        selectedUserIds.value.splice(idx, 1);
    } else {
        selectedUserIds.value.push(id);
    }
}

// ── Batch Operations ─────────────────────────────────────────────────────
function executeBatch(action: 'enable' | 'disable' | 'destroy') {
    if (selectedUserIds.value.length === 0) return;
    if (action === 'destroy' && !confirm(`Hapus permanen ${selectedUserIds.value.length} router user yang dipilih?`)) {
        return;
    }

    batchLoading.value = true;
    inertia.post(
        route('routers.system.users.batch', props.router.id),
        {
            action,
            ids: selectedUserIds.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                batchLoading.value = false;
                selectedUserIds.value = [];
            },
        },
    );
}

// ── Single Row Operations ────────────────────────────────────────────────
function openAdd() {
    addForm.reset();
    showAddUser.value = true;
}

function openEdit(item: SystemUser) {
    editingUser.value = item;
    editForm.id = item['.id'];
    editForm.name = item.name;
    editForm.group = item.group;
    editForm.password = '';
    editForm.address = item.address || '';
    editForm.comment = item.comment || '';
    showEditUser.value = true;
}

function saveNewUser() {
    addForm.post(route('routers.system.users.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddUser.value = false;
            addForm.reset();
        },
    });
}

function saveUpdatedUser() {
    editForm.put(route('routers.system.users.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditUser.value = false;
        },
    });
}

function toggleUser(item: SystemUser, disable: boolean) {
    inertia.post(
        route('routers.system.users.toggle', props.router.id),
        { id: item['.id'], disable },
        { preserveScroll: true },
    );
}

function removeSingle(item: SystemUser) {
    if (!confirm(`Hapus router user "${item.name}"?`)) return;

    inertia.delete(route('routers.system.users.destroy', props.router.id), {
        data: { id: item['.id'] },
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
    const rows = sortedUsers.value.map((u) => [
        u.name,
        u.group,
        u.address || '0.0.0.0/0',
        u['last-logged-in'] || 'never',
        u.disabled === 'true' ? 'disabled' : 'enabled',
        u.comment || '',
    ]);
    const headers = ['Name', 'Group', 'Allowed Address', 'Last Logged In', 'Status', 'Comment'];
    const csvContent =
        'data:text/csv;charset=utf-8,' +
        [headers.join(','), ...rows.map((e) => e.map((val) => `"${(val || '').replace(/"/g, '""')}"`).join(','))].join(
            '\n',
        );

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `system_users_${props.router.name}_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// ── Groups & Active Users View Filters ───────────────────────────────────
const filteredGroups = computed(() => {
    const q = search.value.trim().toLowerCase();
    return (props.groups ?? []).filter((g) => {
        if (!q) return true;
        return g.name.toLowerCase().includes(q) || (g.comment ?? '').toLowerCase().includes(q);
    });
});

const filteredActive = computed(() => {
    const q = search.value.trim().toLowerCase();
    return (props.activeUsers ?? []).filter((a) => {
        if (!q) return true;
        return (
            a.name.toLowerCase().includes(q) ||
            (a.address ?? '').toLowerCase().includes(q) ||
            (a.via ?? '').toLowerCase().includes(q)
        );
    });
});
</script>

<template>
    <Head :title="`System Users — ${router.name}`" />

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
                    <div class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">Total Users</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ kpiStats.total }}</div>
                    <div class="mt-1 text-[10px] text-zinc-400">Akun terdaftar</div>
                </div>

                <div class="rounded-xl border border-rose-200 bg-rose-50/40 p-3 shadow-xs dark:border-rose-900/40 dark:bg-rose-950/20">
                    <div class="text-[11px] font-medium text-rose-700 dark:text-rose-400">Full Access</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-rose-700 dark:text-rose-300">{{ kpiStats.full }}</div>
                    <div class="mt-1 text-[10px] text-rose-600/70 dark:text-rose-400/60">Superuser / Admin</div>
                </div>

                <div class="rounded-xl border border-amber-200 bg-amber-50/40 p-3 shadow-xs dark:border-amber-900/40 dark:bg-amber-950/20">
                    <div class="text-[11px] font-medium text-amber-700 dark:text-amber-400">Write Access</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-amber-700 dark:text-amber-300">{{ kpiStats.write }}</div>
                    <div class="mt-1 text-[10px] text-amber-600/70 dark:text-amber-400/60">Operator konfigurasi</div>
                </div>

                <div class="rounded-xl border border-blue-200 bg-blue-50/40 p-3 shadow-xs dark:border-blue-900/40 dark:bg-blue-950/20">
                    <div class="text-[11px] font-medium text-blue-700 dark:text-blue-400">Read Only</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-blue-700 dark:text-blue-300">{{ kpiStats.read }}</div>
                    <div class="mt-1 text-[10px] text-blue-600/70 dark:text-blue-400/60">Monitoring / Audit</div>
                </div>

                <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <div class="text-[11px] font-medium text-emerald-700 dark:text-emerald-400">Active Sessions</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-emerald-700 dark:text-emerald-300">{{ kpiStats.activeLogins }}</div>
                    <div class="mt-1 text-[10px] text-emerald-600/70 dark:text-emerald-400/60">Sedang login</div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-zinc-50/60 p-3 shadow-xs dark:border-zinc-800 dark:bg-zinc-900/50">
                    <div class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">Disabled</div>
                    <div class="mt-1 text-xl font-bold tracking-tight text-zinc-600 dark:text-zinc-400">{{ kpiStats.disabled }}</div>
                    <div class="mt-1 text-[10px] text-zinc-400">Akun nonaktif</div>
                </div>
            </div>

            <!-- Main Panel -->
            <div class="flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Header Tabs -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">User Management</span>
                            <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                /user
                            </span>
                        </div>
                    </div>

                    <div class="flex gap-1 overflow-x-auto text-xs">
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'users'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'users'; selectedId = null"
                        >
                            Users ({{ users.length }})
                        </button>
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'groups'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'groups'; selectedId = null"
                        >
                            Groups ({{ groups.length }})
                        </button>
                        <button
                            class="border-b-2 px-3 py-2 font-medium transition-colors"
                            :class="activeTab === 'active'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                            @click="activeTab = 'active'; selectedId = null"
                        >
                            Active Sessions ({{ activeUsers.length }})
                        </button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn v-if="can.manage && activeTab === 'users'" size="sm" variant="primary" class="gap-1.5" @click="openAdd">
                            <span>＋</span> Tambah User
                        </Btn>

                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh
                        </Btn>

                        <Btn size="sm" variant="secondary" title="Export CSV" @click="exportCsv">
                            ⬇ Export CSV
                        </Btn>

                        <div v-if="activeTab === 'users'" class="mx-1 h-4 w-px bg-zinc-300 dark:bg-zinc-700"></div>

                        <!-- Filter Group -->
                        <div v-if="activeTab === 'users'" class="flex items-center gap-1 text-xs text-zinc-500">
                            <span>Group:</span>
                            <select
                                v-model="groupFilter"
                                class="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <option value="all">Semua Group</option>
                                <option v-for="g in groups" :key="g['.id']" :value="g.name">{{ g.name }}</option>
                            </select>
                        </div>

                        <!-- Filter Status -->
                        <div v-if="activeTab === 'users'" class="flex items-center gap-1 text-xs text-zinc-500">
                            <span>Status:</span>
                            <select
                                v-model="statusFilter"
                                class="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <option value="all">Semua Status</option>
                                <option value="enabled">Aktif</option>
                                <option value="disabled">Nonaktif</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari user, group, ip..."
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
                        v-if="activeTab === 'users' && selectedUserIds.length > 0"
                        class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-200 bg-indigo-50 px-4 py-2 dark:border-indigo-900/50 dark:bg-indigo-950/40"
                    >
                        <div class="flex items-center gap-2 text-xs font-medium text-indigo-900 dark:text-indigo-200">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-[11px] font-bold text-white">
                                {{ selectedUserIds.length }}
                            </span>
                            <span>user dipilih</span>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="batchLoading"
                                @click="executeBatch('enable')"
                            >
                                ✓ Aktifkan
                            </Btn>
                            <Btn
                                size="sm"
                                variant="secondary"
                                :disabled="batchLoading"
                                @click="executeBatch('disable')"
                            >
                                ✕ Nonaktifkan
                            </Btn>
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
                                @click="selectedUserIds = []"
                            >
                                Batal
                            </button>
                        </div>
                    </div>
                </transition>

                <!-- Tables -->
                <div class="flex-1 overflow-auto">
                    <!-- 1. Users Table -->
                    <table v-if="activeTab === 'users'" class="w-full text-left text-xs">
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
                                <th class="w-8 px-2 py-2 text-center">St</th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('name')">
                                    Username
                                    <span v-if="sortField === 'name'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('group')">
                                    Group
                                    <span v-if="sortField === 'group'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('address')">
                                    Allowed IP / Subnet
                                    <span v-if="sortField === 'address'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="cursor-pointer px-3 py-2" @click="toggleSort('last-logged-in')">
                                    Last Logged In
                                    <span v-if="sortField === 'last-logged-in'">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                </th>
                                <th class="px-3 py-2">Comment</th>
                                <th v-if="can.manage" class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr v-if="paginatedUsers.length === 0">
                                <td :colspan="can.manage ? 8 : 7" class="px-4 py-8 text-center font-sans text-xs text-zinc-500">
                                    Tidak ada router user yang cocok dengan filter.
                                </td>
                            </tr>
                            <tr
                                v-for="u in paginatedUsers"
                                :key="u['.id']"
                                class="cursor-pointer transition-colors hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20"
                                :class="[
                                    selectedUserIds.includes(u['.id']) ? 'bg-indigo-100/50 dark:bg-indigo-950/40' : '',
                                    u.disabled === 'true' ? 'opacity-60' : ''
                                ]"
                                @click="selectedId = u['.id']"
                                @dblclick="can.manage && openEdit(u)"
                            >
                                <td class="px-3 py-2 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="selectedUserIds.includes(u['.id'])"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                                        @change="toggleSelect(u['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2 text-center font-sans">
                                    <StatusDot :color="u.disabled === 'true' ? 'gray' : 'emerald'" />
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100" :class="{ 'line-through': u.disabled === 'true' }">
                                            {{ u.name }}
                                        </span>
                                        <button
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin username"
                                            @click.stop="copyValue(u.name, 'Username')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <span
                                        class="inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold"
                                        :class="
                                            u.group === 'full'
                                                ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                                                : u.group === 'write'
                                                ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                                                : 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
                                        "
                                    >
                                        {{ u.group }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ u.address || '0.0.0.0/0 (any)' }}</span>
                                        <button
                                            v-if="u.address"
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin allowed address"
                                            @click.stop="copyValue(u.address, 'Allowed IP')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-500">{{ u['last-logged-in'] || 'never' }}</td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ u.comment || '-' }}</td>
                                <td v-if="can.manage" class="px-3 py-2 text-right" @click.stop>
                                    <div class="flex items-center justify-end gap-1 font-sans">
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700"
                                            :title="u.disabled === 'true' ? 'Aktifkan' : 'Nonaktifkan'"
                                            @click="toggleUser(u, u.disabled !== 'true')"
                                        >
                                            {{ u.disabled === 'true' ? '✓' : '✕' }}
                                        </button>
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700"
                                            title="Edit user"
                                            @click="openEdit(u)"
                                        >
                                            ✏
                                        </button>
                                        <button
                                            class="rounded px-1.5 py-0.5 text-xs text-rose-600 hover:bg-rose-100 dark:text-rose-400 dark:hover:bg-rose-950/50"
                                            title="Hapus user"
                                            @click="removeSingle(u)"
                                        >
                                            🗑
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 2. Groups Table -->
                    <table v-else-if="activeTab === 'groups'" class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="w-48 px-3 py-2">Group Name</th>
                                <th class="px-3 py-2">Policies</th>
                                <th class="px-3 py-2">Comment</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr v-for="g in filteredGroups" :key="g['.id']" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <span
                                        class="inline-flex rounded px-2 py-0.5 text-xs font-bold"
                                        :class="
                                            g.name === 'full'
                                                ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                                                : g.name === 'write'
                                                ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'
                                                : 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
                                        "
                                    >
                                        {{ g.name }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="policy in (g.policy || '').split(',')"
                                            :key="policy"
                                            class="rounded bg-zinc-100 px-1 py-0.2 text-[10px] text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                        >
                                            {{ policy.trim() }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-sans italic text-zinc-500">{{ g.comment || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- 3. Active Users Table -->
                    <table v-else class="w-full text-left text-xs">
                        <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                            <tr>
                                <th class="px-3 py-2">Username</th>
                                <th class="px-3 py-2">Login Time</th>
                                <th class="px-3 py-2">Remote IP Address</th>
                                <th class="px-3 py-2">Client / Via</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                            <tr v-if="filteredActive.length === 0">
                                <td colspan="4" class="px-4 py-8 text-center font-sans text-xs text-zinc-500">
                                    Tidak ada sesi login aktif saat ini.
                                </td>
                            </tr>
                            <tr v-for="a in filteredActive" :key="a['.id']" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ a.name }}</span>
                                        <button
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin username"
                                            @click.stop="copyValue(a.name, 'Username')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ a.when || '-' }}</td>
                                <td class="px-3 py-2 font-semibold text-indigo-700 dark:text-indigo-400">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ a.address }}</span>
                                        <button
                                            class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                            title="Salin remote IP"
                                            @click.stop="copyValue(a.address || '', 'IP')"
                                        >
                                            📋
                                        </button>
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex rounded bg-zinc-200/70 px-1.5 py-0.5 text-[10px] uppercase font-bold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ a.via || 'winbox' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer with Pagination -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 bg-zinc-50/80 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-3">
                        <span>
                            Total: <strong>{{ activeTab === 'users' ? sortedUsers.length : activeTab === 'groups' ? filteredGroups.length : filteredActive.length }}</strong> item
                        </span>
                        <span v-if="activeTab === 'users' && selectedUserIds.length > 0" class="font-medium text-indigo-600 dark:text-indigo-400">
                            ({{ selectedUserIds.length }} dipilih)
                        </span>
                    </div>

                    <!-- Pagination Controls for Users -->
                    <div v-if="activeTab === 'users'" class="flex items-center gap-2">
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

            <!-- Modal: Add User -->
            <Modal :show="showAddUser" title="Tambah Router User" @close="showAddUser = false">
                <form class="space-y-3" @submit.prevent="saveNewUser">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Username</label>
                        <input
                            v-model="addForm.name"
                            type="text"
                            required
                            placeholder="e.g. operator2"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Group</label>
                        <select
                            v-model="addForm.group"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <option v-for="g in groups" :key="g['.id']" :value="g.name">{{ g.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Password</label>
                        <input
                            v-model="addForm.password"
                            type="password"
                            placeholder="Biarkan kosong jika tanpa password"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Allowed IP / Subnet</label>
                        <input
                            v-model="addForm.address"
                            type="text"
                            placeholder="0.0.0.0/0 (bisa diakses dari IP mana saja)"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="addForm.comment"
                            type="text"
                            placeholder="Catatan opsional"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Btn type="button" variant="secondary" @click="showAddUser = false">Batal</Btn>
                        <Btn type="submit" variant="primary" :disabled="addForm.processing">Simpan User</Btn>
                    </div>
                </form>
            </Modal>

            <!-- Modal: Edit User -->
            <Modal :show="showEditUser" :title="`Edit User: ${editForm.name}`" @close="showEditUser = false">
                <form class="space-y-3" @submit.prevent="saveUpdatedUser">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Group</label>
                        <select
                            v-model="editForm.group"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <option v-for="g in groups" :key="g['.id']" :value="g.name">{{ g.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Ganti Password (biarkan kosong jika tidak diubah)</label>
                        <input
                            v-model="editForm.password"
                            type="password"
                            placeholder="Password baru"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Allowed IP / Subnet</label>
                        <input
                            v-model="editForm.address"
                            type="text"
                            placeholder="0.0.0.0/0"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                        <input
                            v-model="editForm.comment"
                            type="text"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Btn type="button" variant="secondary" @click="showEditUser = false">Batal</Btn>
                        <Btn type="submit" variant="primary" :disabled="editForm.processing">Simpan Perubahan</Btn>
                    </div>
                </form>
            </Modal>
        </div>
    </RouterWorkspace>
</template>
