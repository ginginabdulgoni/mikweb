<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import http from '@/lib/http';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface HotspotUser {
    '.id': string;
    name: string;
    password?: string;
    profile?: string;
    server?: string;
    'mac-address'?: string;
    'limit-uptime'?: string;
    'limit-bytes-total'?: string;
    uptime?: string;
    'bytes-in'?: string;
    'bytes-out'?: string;
    disabled?: string;
    comment?: string;
}

interface ActiveSession {
    '.id': string;
    user: string;
    server?: string;
    address?: string;
    'mac-address'?: string;
    uptime?: string;
    'bytes-in'?: string;
    'bytes-out'?: string;
    'time-left'?: string;
}

interface UserProfile {
    '.id': string;
    name: string;
    'rate-limit'?: string;
    'shared-users'?: string;
    'session-timeout'?: string;
    'idle-timeout'?: string;
    'keepalive-timeout'?: string;
    'status-autorefresh'?: string;
    'address-pool'?: string;
    'parent-queue'?: string;
    'mac-cookie-timeout'?: string;
}

interface HotspotServer {
    '.id': string;
    name: string;
    interface: string;
    profile?: string;
    'address-pool'?: string;
    'idle-timeout'?: string;
    disabled?: string;
}

interface ServerProfile {
    '.id': string;
    name: string;
    'hotspot-address'?: string;
    'dns-name'?: string;
    'html-directory'?: string;
    'login-by'?: string;
}

interface IpBinding {
    '.id': string;
    'mac-address'?: string;
    address?: string;
    'to-address'?: string;
    server?: string;
    type: string;
    disabled?: string;
    comment?: string;
}

interface HotspotHost {
    '.id': string;
    'mac-address': string;
    address?: string;
    'to-address'?: string;
    server?: string;
    authorized?: string;
    bypassed?: string;
}

interface Pool {
    '.id': string;
    name: string;
    ranges?: string;
    'next-pool'?: string;
}

const props = defineProps<{
    router: any;
    servers: HotspotServer[];
    serverProfiles: ServerProfile[];
    users: HotspotUser[];
    userProfiles: UserProfile[];
    active: ActiveSession[];
    ipBindings: IpBinding[];
    hosts: HotspotHost[];
    interfaces: string[];
    pools?: Pool[];
    deviceError: string | null;
    can: { manage: boolean };
}>();

// ── Tab Navigation ───────────────────────────────────────────────────
type TabKey = 'users' | 'active' | 'non-aktif' | 'user-profiles' | 'servers' | 'server-profiles' | 'ip-bindings' | 'hosts';
const activeTab = ref<TabKey>('users');

// ── Selection & Filter States ────────────────────────────────────────
const selectedId = ref<string | null>(null);
const searchQuery = ref('');
const profileFilter = ref('all');
const serverFilter = ref('all');
const nonActiveFilter = ref<'all' | 'disabled' | 'offline'>('all');

// Multi-selection for Hotspot Users / Vouchers
const selectedUserIds = ref<string[]>([]);

function fmtBytes(bytesStr?: string): string {
    const n = Number(bytesStr ?? 0);
    if (!n) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(n) / Math.log(1024));
    return `${(n / 1024 ** i).toFixed(1)} ${u[i]}`;
}

// Active usernames map for O(1) online lookup
const activeUserMap = computed(() => {
    const map = new Map<string, ActiveSession>();
    for (const a of props.active) {
        map.set(a.user, a);
    }
    return map;
});

const isOnline = (name: string): boolean => activeUserMap.value.has(name);
const isDisabled = (u: HotspotUser): boolean => u.disabled === 'true';

// ── Top KPI Summary Statistics ───────────────────────────────────────
const stats = computed(() => {
    const totalUsers = props.users.length;
    const onlineCount = props.active.length;
    const disabledCount = props.users.filter((u) => u.disabled === 'true').length;
    const offlineCount = Math.max(0, totalUsers - onlineCount - disabledCount);
    const bypassedBindings = props.ipBindings.filter((b) => b.type === 'bypassed').length;
    const blockedBindings = props.ipBindings.filter((b) => b.type === 'blocked').length;
    const serversCount = props.servers.filter((s) => s.disabled !== 'true').length;
    const hostsCount = props.hosts.length;

    return {
        totalUsers,
        onlineCount,
        offlineCount,
        disabledCount,
        bypassedBindings,
        blockedBindings,
        serversCount,
        hostsCount,
    };
});

// ── Filtered Datasets ────────────────────────────────────────────────
const filteredUsers = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.users.filter((u) => {
        if (profileFilter.value !== 'all' && (u.profile ?? '') !== profileFilter.value) return false;
        if (serverFilter.value !== 'all' && (u.server ?? '') !== serverFilter.value) return false;
        if (!q) return true;
        return (
            u.name.toLowerCase().includes(q) ||
            (u.comment ?? '').toLowerCase().includes(q) ||
            (u['mac-address'] ?? '').toLowerCase().includes(q) ||
            (u.profile ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredActive = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.active.filter((a) => {
        if (serverFilter.value !== 'all' && (a.server ?? '') !== serverFilter.value) return false;
        if (!q) return true;
        return (
            a.user.toLowerCase().includes(q) ||
            (a.address ?? '').toLowerCase().includes(q) ||
            (a['mac-address'] ?? '').toLowerCase().includes(q)
        );
    });
});

const nonActiveUsers = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.users.filter((u) => {
        const online = isOnline(u.name);
        const disabled = isDisabled(u);

        if (online) return false;

        if (nonActiveFilter.value === 'disabled' && !disabled) return false;
        if (nonActiveFilter.value === 'offline' && disabled) return false;

        if (profileFilter.value !== 'all' && (u.profile ?? '') !== profileFilter.value) return false;
        if (serverFilter.value !== 'all' && (u.server ?? '') !== serverFilter.value) return false;

        if (!q) return true;
        return (
            u.name.toLowerCase().includes(q) ||
            (u.comment ?? '').toLowerCase().includes(q) ||
            (u['mac-address'] ?? '').toLowerCase().includes(q) ||
            (u.profile ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredUserProfiles = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.userProfiles.filter((p) => {
        if (!q) return true;
        return (
            p.name.toLowerCase().includes(q) ||
            (p['rate-limit'] ?? '').toLowerCase().includes(q) ||
            (p['address-pool'] ?? '').toLowerCase().includes(q) ||
            (p['parent-queue'] ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredServerProfiles = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.serverProfiles.filter((sp) => {
        if (!q) return true;
        return (
            sp.name.toLowerCase().includes(q) ||
            (sp['dns-name'] ?? '').toLowerCase().includes(q) ||
            (sp['hotspot-address'] ?? '').toLowerCase().includes(q) ||
            (sp['html-directory'] ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredIpBindings = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.ipBindings.filter((b) => {
        if (!q) return true;
        return (
            (b['mac-address'] ?? '').toLowerCase().includes(q) ||
            (b.address ?? '').toLowerCase().includes(q) ||
            (b.type ?? '').toLowerCase().includes(q) ||
            (b.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredHosts = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.hosts.filter((h) => {
        if (!q) return true;
        return (
            h['mac-address'].toLowerCase().includes(q) ||
            (h.address ?? '').toLowerCase().includes(q) ||
            (h['to-address'] ?? '').toLowerCase().includes(q)
        );
    });
});

// ── Sorting & Pagination for Hotspot Users ───────────────────────────
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

const sortedUsers = computed(() => {
    const list = [...filteredUsers.value];
    list.sort((a, b) => {
        let valA = '';
        let valB = '';
        if (sortKey.value === 'name') {
            valA = a.name;
            valB = b.name;
        } else if (sortKey.value === 'profile') {
            valA = a.profile ?? '';
            valB = b.profile ?? '';
        } else if (sortKey.value === 'server') {
            valA = a.server ?? '';
            valB = b.server ?? '';
        } else if (sortKey.value === 'uptime') {
            valA = a.uptime ?? '';
            valB = b.uptime ?? '';
        } else if (sortKey.value === 'bytes') {
            const bytesA = Number(a['bytes-in'] ?? 0) + Number(a['bytes-out'] ?? 0);
            const bytesB = Number(b['bytes-in'] ?? 0) + Number(b['bytes-out'] ?? 0);
            return sortOrder.value === 'asc' ? bytesA - bytesB : bytesB - bytesA;
        } else if (sortKey.value === 'limit-uptime') {
            valA = a['limit-uptime'] ?? '';
            valB = b['limit-uptime'] ?? '';
        } else if (sortKey.value === 'status') {
            valA = isOnline(a.name) ? '1' : (isDisabled(a) ? '3' : '2');
            valB = isOnline(b.name) ? '1' : (isDisabled(b) ? '3' : '2');
        } else if (sortKey.value === 'comment') {
            valA = a.comment ?? '';
            valB = b.comment ?? '';
        }
        const cmp = valA.localeCompare(valB, undefined, { numeric: true });
        return sortOrder.value === 'asc' ? cmp : -cmp;
    });
    return list;
});

const paginatedUsers = computed(() => {
    if (perPage.value >= 9999) return sortedUsers.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedUsers.value.slice(start, start + perPage.value);
});

const totalPages = computed(() => {
    if (perPage.value >= 9999) return 1;
    return Math.max(1, Math.ceil(sortedUsers.value.length / perPage.value));
});

watch([searchQuery, serverFilter, profileFilter, perPage], () => {
    currentPage.value = 1;
});

// Multi-select helpers
function toggleSelectAllVisible(items: HotspotUser[]) {
    const visibleIds = items.map((i) => i['.id']);
    const allSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedUserIds.value.includes(id));
    if (allSelected) {
        selectedUserIds.value = selectedUserIds.value.filter((id) => !visibleIds.includes(id));
    } else {
        const set = new Set([...selectedUserIds.value, ...visibleIds]);
        selectedUserIds.value = Array.from(set);
    }
}

function toggleUserSelection(id: string) {
    const idx = selectedUserIds.value.indexOf(id);
    if (idx >= 0) {
        selectedUserIds.value.splice(idx, 1);
    } else {
        selectedUserIds.value.push(id);
    }
}

function clearUserSelection() {
    selectedUserIds.value = [];
}

// ── Modals & Forms ───────────────────────────────────────────────────
const showUserModal = ref(false);
const editingUser = ref<HotspotUser | null>(null);
const userForm = useForm({
    id: '',
    name: '',
    password: '',
    profile: '',
    server: 'all',
    mac_address: '',
    limit_uptime: '',
    limit_bytes_total: '',
    comment: '',
});

// Quick Password Modal
const showPasswordModal = ref(false);
const targetUserForPassword = ref<HotspotUser | null>(null);
const passwordForm = useForm({
    id: '',
    password: '',
});

function openChangePassword(u: HotspotUser) {
    targetUserForPassword.value = u;
    passwordForm.reset();
    passwordForm.id = u['.id'];
    passwordForm.password = '';
    showPasswordModal.value = true;
}

function generateRandomPassword() {
    const chars = '23456789abcdefghjkmnpqrstuvwxyz';
    let result = '';
    for (let i = 0; i < 6; i++) {
        result += chars[Math.floor(Math.random() * chars.length)];
    }
    passwordForm.password = result;
}

function submitChangePassword() {
    passwordForm.put(route('routers.hotspot.users.password', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showPasswordModal.value = false;
        },
    });
}

// Batch Actions Modal
const showBatchProfileModal = ref(false);
const batchTargetProfile = ref('default');

function submitBatchAction(action: 'enable' | 'disable' | 'change_profile' | 'destroy') {
    if (selectedUserIds.value.length === 0) return;

    if (action === 'destroy') {
        if (!confirm(`Hapus ${selectedUserIds.value.length} user / voucher hotspot terpilih? Tindakan ini permanen.`)) {
            return;
        }
    }

    inertia.post(
        route('routers.hotspot.users.batch', props.router.id),
        {
            action,
            ids: selectedUserIds.value,
            target_profile: action === 'change_profile' ? batchTargetProfile.value : undefined,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedUserIds.value = [];
                showBatchProfileModal.value = false;
            },
        },
    );
}

// User Profile Form
const showProfileModal = ref(false);
const editingProfile = ref<UserProfile | null>(null);
const profileForm = useForm({
    id: '',
    name: '',
    rate_limit: '',
    shared_users: '1',
    session_timeout: '',
    idle_timeout: '',
    keepalive_timeout: '2m',
    status_autorefresh: '1m',
    address_pool: '',
    parent_queue: '',
    mac_cookie_timeout: '',
});

// Server Form
const showServerModal = ref(false);
const editingServer = ref<HotspotServer | null>(null);
const serverForm = useForm({
    id: '',
    name: '',
    interface: '',
    profile: 'hsprof1',
    address_pool: '',
    idle_timeout: '5m',
});

// Server Profile Form
const showServerProfileModal = ref(false);
const editingServerProfile = ref<ServerProfile | null>(null);
const serverProfileForm = useForm({
    id: '',
    name: '',
    hotspot_address: '',
    dns_name: '',
    html_directory: 'hotspot',
    login_by: 'http-chap,cookie',
});

// IP Binding Form
const showBindingModal = ref(false);
const editingBinding = ref<IpBinding | null>(null);
const bindingForm = useForm({
    id: '',
    mac_address: '',
    address: '',
    to_address: '',
    server: 'all',
    type: 'bypassed',
    comment: '',
});

// Quick Bypass Modal (1-click bypass from Active / Hosts)
const showQuickBypassModal = ref(false);
const quickBypassForm = useForm({
    mac_address: '',
    address: '',
    server: 'all',
    comment: '',
});

function openQuickBypass(mac: string, ip?: string, server?: string, comment?: string) {
    quickBypassForm.reset();
    quickBypassForm.mac_address = mac;
    quickBypassForm.address = ip ?? '';
    quickBypassForm.server = server ?? 'all';
    quickBypassForm.comment = comment ?? `Bypass device ${mac}`;
    showQuickBypassModal.value = true;
}

function submitQuickBypass() {
    quickBypassForm.post(route('routers.hotspot.ip-bindings.quick-bypass', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showQuickBypassModal.value = false;
        },
    });
}

// Profile & Server Name lists
const userProfileNames = computed(() => props.userProfiles.map((p) => p.name));
const serverNames = computed(() => props.servers.map((s) => s.name));

// ── Hotspot User Actions ─────────────────────────────────────────────
function openAddUser() {
    editingUser.value = null;
    userForm.reset();
    userForm.server = 'all';
    userForm.profile = userProfileNames.value[0] ?? 'default';
    showUserModal.value = true;
}

function openEditUser(u: HotspotUser) {
    editingUser.value = u;
    userForm.reset();
    userForm.id = u['.id'];
    userForm.name = u.name;
    userForm.profile = u.profile ?? 'default';
    userForm.server = u.server ?? 'all';
    userForm.mac_address = u['mac-address'] ?? '';
    userForm.limit_uptime = u['limit-uptime'] ?? '';
    userForm.limit_bytes_total = u['limit-bytes-total'] ?? '';
    userForm.comment = u.comment ?? '';
    showUserModal.value = true;
}

function submitUser() {
    const opts = { preserveScroll: true, onSuccess: () => (showUserModal.value = false) };
    if (editingUser.value) {
        userForm.put(route('routers.hotspot.users.update', props.router.id), opts);
    } else {
        userForm.post(route('routers.hotspot.users.store', props.router.id), opts);
    }
}

function toggleUser(u: HotspotUser) {
    inertia.post(
        route('routers.hotspot.users.toggle', props.router.id),
        { id: u['.id'], disable: u.disabled !== 'true' },
        { preserveScroll: true },
    );
}

function removeUser(u: HotspotUser) {
    if (confirm(`Hapus hotspot user "${u.name}"?`)) {
        inertia.delete(route('routers.hotspot.users.destroy', props.router.id), {
            data: { id: u['.id'] },
            preserveScroll: true,
            onSuccess: () => {
                if (selectedId.value === u['.id']) selectedId.value = null;
            },
        });
    }
}

function disconnectSession(a: ActiveSession) {
    if (confirm(`Disconnect session for ${a.user}?`)) {
        inertia.post(
            route('routers.hotspot.active.disconnect', props.router.id),
            { id: a['.id'] },
            { preserveScroll: true },
        );
    }
}

function disconnectAllActive() {
    if (confirm(`Disconnect seluruh (${props.active.length}) sesi aktif Hotspot saat ini? Seluruh perangkat klien akan logout.`)) {
        inertia.post(
            route('routers.hotspot.active.disconnect-all', props.router.id),
            {},
            { preserveScroll: true },
        );
    }
}

// ── User Profile Actions ─────────────────────────────────────────────
function openAddProfile() {
    editingProfile.value = null;
    profileForm.reset();
    profileForm.shared_users = '1';
    profileForm.keepalive_timeout = '2m';
    profileForm.status_autorefresh = '1m';
    showProfileModal.value = true;
}

function openEditProfile(p: UserProfile) {
    editingProfile.value = p;
    profileForm.reset();
    profileForm.id = p['.id'];
    profileForm.name = p.name;
    profileForm.rate_limit = p['rate-limit'] ?? '';
    profileForm.shared_users = p['shared-users'] ?? '1';
    profileForm.session_timeout = p['session-timeout'] ?? '';
    profileForm.idle_timeout = p['idle-timeout'] ?? '';
    profileForm.keepalive_timeout = p['keepalive-timeout'] ?? '2m';
    profileForm.status_autorefresh = p['status-autorefresh'] ?? '1m';
    profileForm.address_pool = p['address-pool'] ?? '';
    profileForm.parent_queue = p['parent-queue'] ?? '';
    profileForm.mac_cookie_timeout = p['mac-cookie-timeout'] ?? '';
    showProfileModal.value = true;
}

function submitProfile() {
    const opts = { preserveScroll: true, onSuccess: () => (showProfileModal.value = false) };
    if (editingProfile.value) {
        profileForm.put(route('routers.hotspot.user-profiles.update', props.router.id), opts);
    } else {
        profileForm.post(route('routers.hotspot.user-profiles.store', props.router.id), opts);
    }
}

function removeProfile(p: UserProfile) {
    if (confirm(`Hapus hotspot user profile "${p.name}"?`)) {
        inertia.delete(route('routers.hotspot.user-profiles.destroy', props.router.id), {
            data: { id: p['.id'] },
            preserveScroll: true,
        });
    }
}

// ── Server Actions ───────────────────────────────────────────────────
function openAddServer() {
    editingServer.value = null;
    serverForm.reset();
    serverForm.interface = props.interfaces?.[0] ?? '';
    serverForm.profile = props.serverProfiles?.[0]?.name ?? 'hsprof1';
    serverForm.idle_timeout = '5m';
    showServerModal.value = true;
}

function openEditServer(s: HotspotServer) {
    editingServer.value = s;
    serverForm.reset();
    serverForm.id = s['.id'];
    serverForm.name = s.name;
    serverForm.interface = s.interface;
    serverForm.profile = s.profile ?? 'hsprof1';
    serverForm.address_pool = s['address-pool'] ?? '';
    serverForm.idle_timeout = s['idle-timeout'] ?? '5m';
    showServerModal.value = true;
}

function submitServer() {
    const opts = { preserveScroll: true, onSuccess: () => (showServerModal.value = false) };
    if (editingServer.value) {
        serverForm.put(route('routers.hotspot.servers.update', props.router.id), opts);
    } else {
        serverForm.post(route('routers.hotspot.servers.store', props.router.id), opts);
    }
}

function toggleServer(s: HotspotServer) {
    inertia.post(
        route('routers.hotspot.servers.toggle', props.router.id),
        { id: s['.id'], disable: s.disabled !== 'true' },
        { preserveScroll: true },
    );
}

function removeServer(s: HotspotServer) {
    if (confirm(`Hapus hotspot server "${s.name}"?`)) {
        inertia.delete(route('routers.hotspot.servers.destroy', props.router.id), {
            data: { id: s['.id'] },
            preserveScroll: true,
        });
    }
}

// ── Server Profile Actions ───────────────────────────────────────────
function openAddServerProfile() {
    editingServerProfile.value = null;
    serverProfileForm.reset();
    serverProfileForm.html_directory = 'hotspot';
    serverProfileForm.login_by = 'http-chap,cookie';
    showServerProfileModal.value = true;
}

function openEditServerProfile(sp: ServerProfile) {
    editingServerProfile.value = sp;
    serverProfileForm.reset();
    serverProfileForm.id = sp['.id'];
    serverProfileForm.name = sp.name;
    serverProfileForm.hotspot_address = sp['hotspot-address'] ?? '';
    serverProfileForm.dns_name = sp['dns-name'] ?? '';
    serverProfileForm.html_directory = sp['html-directory'] ?? 'hotspot';
    serverProfileForm.login_by = sp['login-by'] ?? 'http-chap,cookie';
    showServerProfileModal.value = true;
}

function submitServerProfile() {
    const opts = { preserveScroll: true, onSuccess: () => (showServerProfileModal.value = false) };
    if (editingServerProfile.value) {
        serverProfileForm.put(route('routers.hotspot.server-profiles.update', props.router.id), opts);
    } else {
        serverProfileForm.post(route('routers.hotspot.server-profiles.store', props.router.id), opts);
    }
}

function removeServerProfile(sp: ServerProfile) {
    if (confirm(`Hapus server profile "${sp.name}"?`)) {
        inertia.delete(route('routers.hotspot.server-profiles.destroy', props.router.id), {
            data: { id: sp['.id'] },
            preserveScroll: true,
        });
    }
}

// ── IP Binding Actions ───────────────────────────────────────────────
function openAddBinding() {
    editingBinding.value = null;
    bindingForm.reset();
    bindingForm.server = 'all';
    bindingForm.type = 'bypassed';
    showBindingModal.value = true;
}

function openEditBinding(b: IpBinding) {
    editingBinding.value = b;
    bindingForm.reset();
    bindingForm.id = b['.id'];
    bindingForm.mac_address = b['mac-address'] ?? '';
    bindingForm.address = b.address ?? '';
    bindingForm.to_address = b['to-address'] ?? '';
    bindingForm.server = b.server ?? 'all';
    bindingForm.type = b.type;
    bindingForm.comment = b.comment ?? '';
    showBindingModal.value = true;
}

function submitBinding() {
    const opts = { preserveScroll: true, onSuccess: () => (showBindingModal.value = false) };
    if (editingBinding.value) {
        bindingForm.put(route('routers.hotspot.ip-bindings.update', props.router.id), opts);
    } else {
        bindingForm.post(route('routers.hotspot.ip-bindings.store', props.router.id), opts);
    }
}

function toggleBinding(b: IpBinding) {
    inertia.post(
        route('routers.hotspot.ip-bindings.toggle', props.router.id),
        { id: b['.id'], disable: b.disabled !== 'true' },
        { preserveScroll: true },
    );
}

function removeBinding(b: IpBinding) {
    if (confirm(`Hapus IP binding ${b['mac-address'] || b.address}?`)) {
        inertia.delete(route('routers.hotspot.ip-bindings.destroy', props.router.id), {
            data: { id: b['.id'] },
            preserveScroll: true,
        });
    }
}

// ── Host Actions ─────────────────────────────────────────────────────
function removeHost(h: HotspotHost) {
    if (confirm(`Hapus host entry ${h['mac-address']} (${h.address || 'no ip'})?`)) {
        inertia.delete(route('routers.hotspot.hosts.destroy', props.router.id), {
            data: { id: h['.id'] },
            preserveScroll: true,
        });
    }
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

// ── CSV Exports ──────────────────────────────────────────────────────
function exportUsersCsv() {
    const headers = ['Username / Voucher', 'Profile', 'Server', 'MAC Address', 'Limit Uptime', 'Limit Bytes Total', 'Uptime', 'Bytes In', 'Bytes Out', 'Status', 'Comment'];
    const rows = filteredUsers.value.map((u) => {
        const status = isOnline(u.name) ? 'online' : (isDisabled(u) ? 'disabled' : 'offline');
        return [
            `"${u.name}"`,
            `"${u.profile || 'default'}"`,
            `"${u.server || 'all'}"`,
            `"${u['mac-address'] || ''}"`,
            `"${u['limit-uptime'] || ''}"`,
            `"${u['limit-bytes-total'] || ''}"`,
            `"${u.uptime || ''}"`,
            `"${u['bytes-in'] || ''}"`,
            `"${u['bytes-out'] || ''}"`,
            `"${status}"`,
            `"${(u.comment || '').replace(/"/g, '""')}"`,
        ];
    });

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `hotspot-users-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportActiveCsv() {
    const headers = ['Username', 'Server', 'IP Address', 'MAC Address', 'Uptime', 'Time Left', 'Bytes In', 'Bytes Out'];
    const rows = filteredActive.value.map((a) => [
        `"${a.user}"`,
        `"${a.server || 'hotspot'}"`,
        `"${a.address || ''}"`,
        `"${a['mac-address'] || ''}"`,
        `"${a.uptime || ''}"`,
        `"${a['time-left'] || ''}"`,
        `"${a['bytes-in'] || ''}"`,
        `"${a['bytes-out'] || ''}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `hotspot-active-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportBindingsCsv() {
    const headers = ['Type', 'MAC Address', 'Address', 'To Address', 'Server', 'Disabled', 'Comment'];
    const rows = filteredIpBindings.value.map((b) => [
        `"${b.type}"`,
        `"${b['mac-address'] || ''}"`,
        `"${b.address || ''}"`,
        `"${b['to-address'] || ''}"`,
        `"${b.server || 'all'}"`,
        `"${b.disabled === 'true' ? 'yes' : 'no'}"`,
        `"${(b.comment || '').replace(/"/g, '""')}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `hotspot-bindings-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportHostsCsv() {
    const headers = ['MAC Address', 'IP Address', 'To Address', 'Server', 'Authorized', 'Bypassed'];
    const rows = filteredHosts.value.map((h) => [
        `"${h['mac-address']}"`,
        `"${h.address || ''}"`,
        `"${h['to-address'] || ''}"`,
        `"${h.server || 'hotspot'}"`,
        `"${h.authorized === 'true' ? 'yes' : 'no'}"`,
        `"${h.bypassed === 'true' ? 'yes' : 'no'}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `hotspot-hosts-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// ── Winbox Toolbar Handler ───────────────────────────────────────────
function handleAdd() {
    if (activeTab.value === 'users' || activeTab.value === 'non-aktif') openAddUser();
    else if (activeTab.value === 'user-profiles') openAddProfile();
    else if (activeTab.value === 'servers') openAddServer();
    else if (activeTab.value === 'server-profiles') openAddServerProfile();
    else if (activeTab.value === 'ip-bindings') openAddBinding();
}

function handleSelectedEdit() {
    if (!selectedId.value) return;
    if (activeTab.value === 'users' || activeTab.value === 'non-aktif') {
        const item = props.users.find((u) => u['.id'] === selectedId.value);
        if (item) openEditUser(item);
    } else if (activeTab.value === 'user-profiles') {
        const item = props.userProfiles.find((p) => p['.id'] === selectedId.value);
        if (item) openEditProfile(item);
    } else if (activeTab.value === 'servers') {
        const item = props.servers.find((s) => s['.id'] === selectedId.value);
        if (item) openEditServer(item);
    } else if (activeTab.value === 'server-profiles') {
        const item = props.serverProfiles.find((sp) => sp['.id'] === selectedId.value);
        if (item) openEditServerProfile(item);
    } else if (activeTab.value === 'ip-bindings') {
        const item = props.ipBindings.find((b) => b['.id'] === selectedId.value);
        if (item) openEditBinding(item);
    }
}

function handleSelectedRemove() {
    if (!selectedId.value) return;
    if (activeTab.value === 'users' || activeTab.value === 'non-aktif') {
        const item = props.users.find((u) => u['.id'] === selectedId.value);
        if (item) removeUser(item);
    } else if (activeTab.value === 'user-profiles') {
        const item = props.userProfiles.find((p) => p['.id'] === selectedId.value);
        if (item) removeProfile(item);
    } else if (activeTab.value === 'servers') {
        const item = props.servers.find((s) => s['.id'] === selectedId.value);
        if (item) removeServer(item);
    } else if (activeTab.value === 'server-profiles') {
        const item = props.serverProfiles.find((sp) => sp['.id'] === selectedId.value);
        if (item) removeServerProfile(item);
    } else if (activeTab.value === 'ip-bindings') {
        const item = props.ipBindings.find((b) => b['.id'] === selectedId.value);
        if (item) removeBinding(item);
    }
}

function handleSelectedToggle(disable: boolean) {
    if (!selectedId.value) return;
    if (activeTab.value === 'users' || activeTab.value === 'non-aktif') {
        const item = props.users.find((u) => u['.id'] === selectedId.value);
        if (item) toggleUser(item);
    } else if (activeTab.value === 'servers') {
        const item = props.servers.find((s) => s['.id'] === selectedId.value);
        if (item) toggleServer(item);
    } else if (activeTab.value === 'ip-bindings') {
        const item = props.ipBindings.find((b) => b['.id'] === selectedId.value);
        if (item) toggleBinding(item);
    }
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}

// ── Hotspot Voucher Generator & Print Templates ───────────────────────
interface GeneratedVoucher {
    username: string;
    password?: string;
    profile: string;
    limit_uptime?: string | null;
    price?: number | string | null;
    comment?: string | null;
}

const showGenerateModal = ref(false);
const isGenerating = ref(false);
const generateError = ref<string | null>(null);

const voucherForm = ref({
    quantity: 10,
    server: 'all',
    profile: '',
    prefix: '',
    length: 5,
    charset: 'alphanumeric',
    limit_uptime: '',
    price: 5000,
    comment: 'vc',
});

const showPrintModal = ref(false);
const printLayout = ref<'thermal58' | 'thermal80' | 'gridA4'>('gridA4');
const printVouchers = ref<GeneratedVoucher[]>([]);

function openGenerateModal() {
    generateError.value = null;
    voucherForm.value.profile = props.userProfiles[0]?.name ?? 'default';
    voucherForm.value.server = 'all';
    showGenerateModal.value = true;
}

async function handleGenerateVouchers() {
    isGenerating.value = true;
    generateError.value = null;
    try {
        const { data } = await http.post<any>(
            route('routers.hotspot.vouchers.generate', props.router.id),
            voucherForm.value,
        );
        showGenerateModal.value = false;
        if (data.vouchers && data.vouchers.length > 0) {
            printVouchers.value = data.vouchers;
            showPrintModal.value = true;
        }
        inertia.reload({ preserveScroll: true });
    } catch (e: any) {
        generateError.value = e?.response?.data?.message ?? 'Gagal generate voucher hotspot.';
    } finally {
        isGenerating.value = false;
    }
}

function printNow() {
    window.print();
}

function openPrintBatch() {
    if (selectedUserIds.value.length > 0) {
        const selected = props.users.filter((u) => selectedUserIds.value.includes(u['.id']));
        printVouchers.value = selected.map((u) => ({
            username: u.name,
            password: u.password || u.name,
            profile: u.profile ?? 'default',
            limit_uptime: u['limit-uptime'],
            comment: u.comment,
        }));
        showPrintModal.value = true;
        return;
    }

    if (selectedId.value) {
        const u = props.users.find((user) => user['.id'] === selectedId.value);
        if (u) {
            printVouchers.value = [{
                username: u.name,
                password: u.password || u.name,
                profile: u.profile ?? 'default',
                limit_uptime: u['limit-uptime'],
                comment: u.comment,
            }];
            showPrintModal.value = true;
            return;
        }
    }

    const list = props.users.map((u) => ({
        username: u.name,
        password: u.password || u.name,
        profile: u.profile ?? 'default',
        limit_uptime: u['limit-uptime'],
        comment: u.comment,
    }));
    printVouchers.value = list.slice(0, 60);
    showPrintModal.value = true;
}
</script>

<template>
    <Head title="Hotspot Management" />
    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- ── Top KPI Statistics Summary Grid ────────────────────────── -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Users -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Total Hotspot Users</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.totalUsers }}</span>
                        <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-950 dark:text-amber-300">Voucher</span>
                    </div>
                </div>

                <!-- Active Online -->
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-3.5 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-400">
                        <StatusDot color="emerald" :pulse="true" />
                        <span>Online (Aktif)</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-emerald-800 dark:text-emerald-200">{{ stats.onlineCount }}</span>
                        <span class="text-[10px] text-emerald-600 dark:text-emerald-400">Sesi live</span>
                    </div>
                </div>

                <!-- Offline -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-zinc-500">
                        <StatusDot color="amber" />
                        <span>Offline</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-700 dark:text-zinc-300">{{ stats.offlineCount }}</span>
                        <span class="text-[10px] text-zinc-400">Belum login</span>
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

                <!-- IP Bindings -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">IP Bindings</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ ipBindings.length }}</span>
                        <span class="text-[10px] text-zinc-500">
                            {{ stats.bypassedBindings }} bypass
                        </span>
                    </div>
                </div>

                <!-- Hosts Detected -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Detected Hosts</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.hostsCount }}</span>
                        <span class="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-950 dark:text-sky-300">Perangkat</span>
                    </div>
                </div>
            </div>

            <!-- ── WinBox-style Window Container ────────────────────────── -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Window Title & Tabs Bar -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold text-amber-500">🔥</span>
                            <h2 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">Hotspot Management</h2>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                                <StatusDot color="emerald" :pulse="true" />
                                {{ active.length }} Online
                            </span>
                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ users.length }} Total User
                            </span>
                        </div>
                    </div>

                    <!-- Tabs (Winbox style) -->
                    <nav class="-mb-px flex space-x-1 overflow-x-auto text-xs font-medium">
                        <!-- Tab: Users -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors"
                            :class="activeTab === 'users'
                                ? 'border-amber-500 font-semibold text-amber-600 dark:border-amber-400 dark:text-amber-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'users'; selectedId = null"
                        >
                            <span>Users (Voucher / Akun)</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ users.length }}</span>
                        </button>

                        <!-- Tab: Active -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors"
                            :class="activeTab === 'active'
                                ? 'border-amber-500 font-semibold text-amber-600 dark:border-amber-400 dark:text-amber-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'active'; selectedId = null"
                        >
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            <span>Active</span>
                            <span class="rounded-full bg-emerald-100 px-1.5 py-0.2 text-[10px] text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">{{ active.length }}</span>
                        </button>

                        <!-- Tab: Non-Aktif -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors"
                            :class="activeTab === 'non-aktif'
                                ? 'border-amber-500 font-semibold text-amber-600 dark:border-amber-400 dark:text-amber-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'non-aktif'; selectedId = null"
                        >
                            <span>Non-Aktif</span>
                            <span class="rounded-full bg-amber-100 px-1.5 py-0.2 text-[10px] text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">{{ nonActiveUsers.length }}</span>
                        </button>

                        <!-- Tab: User Profiles -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors"
                            :class="activeTab === 'user-profiles'
                                ? 'border-amber-500 font-semibold text-amber-600 dark:border-amber-400 dark:text-amber-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'user-profiles'; selectedId = null"
                        >
                            <span>User Profiles</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ userProfiles.length }}</span>
                        </button>

                        <!-- Tab: Servers -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors"
                            :class="activeTab === 'servers'
                                ? 'border-amber-500 font-semibold text-amber-600 dark:border-amber-400 dark:text-amber-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'servers'; selectedId = null"
                        >
                            <span>Servers</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ servers.length }}</span>
                        </button>

                        <!-- Tab: Server Profiles -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors"
                            :class="activeTab === 'server-profiles'
                                ? 'border-amber-500 font-semibold text-amber-600 dark:border-amber-400 dark:text-amber-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'server-profiles'; selectedId = null"
                        >
                            <span>Server Profiles</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ serverProfiles.length }}</span>
                        </button>

                        <!-- Tab: IP Bindings -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors"
                            :class="activeTab === 'ip-bindings'
                                ? 'border-amber-500 font-semibold text-amber-600 dark:border-amber-400 dark:text-amber-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'ip-bindings'; selectedId = null"
                        >
                            <span>IP Bindings (Bypass)</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ ipBindings.length }}</span>
                        </button>

                        <!-- Tab: Hosts -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors"
                            :class="activeTab === 'hosts'
                                ? 'border-amber-500 font-semibold text-amber-600 dark:border-amber-400 dark:text-amber-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'hosts'; selectedId = null"
                        >
                            <span>Hosts</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ hosts.length }}</span>
                        </button>
                    </nav>
                </div>

                <!-- WinBox Action Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
                    <!-- Action Buttons -->
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn
                            v-if="can.manage && activeTab !== 'active' && activeTab !== 'hosts'"
                            size="sm"
                            variant="primary"
                            @click="handleAdd"
                        >
                            + Add
                        </Btn>

                        <Btn
                            v-if="can.manage && (activeTab === 'users' || activeTab === 'non-aktif')"
                            size="sm"
                            variant="secondary"
                            class="border border-amber-300 bg-amber-50 font-semibold text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-900/60"
                            title="Generate batch hotspot vouchers"
                            @click="openGenerateModal"
                        >
                            🎟️ Generate Voucher
                        </Btn>

                        <a
                            v-if="(activeTab === 'users' || activeTab === 'non-aktif') && users.length > 0"
                            :href="route('routers.hotspot.vouchers.print', router.id)"
                            target="_blank"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50/60 px-2.5 py-1 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300 dark:hover:bg-indigo-900/60"
                            title="Buka Lembar Cetak Lengkap (Grid A4 / Thermal 80mm / 58mm)"
                        >
                            <span>🖨️ Cetak Voucher Lembar / Thermal</span>
                            <span class="text-[10px] opacity-75">↗</span>
                        </a>

                        <Btn
                            v-if="can.manage && (activeTab === 'users' || activeTab === 'non-aktif' || activeTab === 'servers' || activeTab === 'ip-bindings')"
                            size="sm"
                            :disabled="!selectedId"
                            title="Enable selected"
                            @click="handleSelectedToggle(false)"
                        >
                            ✓ Enable
                        </Btn>

                        <Btn
                            v-if="can.manage && (activeTab === 'users' || activeTab === 'non-aktif' || activeTab === 'servers' || activeTab === 'ip-bindings')"
                            size="sm"
                            :disabled="!selectedId"
                            title="Disable selected"
                            @click="handleSelectedToggle(true)"
                        >
                            ✕ Disable
                        </Btn>

                        <Btn
                            v-if="can.manage && activeTab !== 'active' && activeTab !== 'hosts'"
                            size="sm"
                            :disabled="!selectedId"
                            title="Edit selected"
                            @click="handleSelectedEdit"
                        >
                            ✏ Edit
                        </Btn>

                        <Btn
                            v-if="can.manage && activeTab !== 'active' && activeTab !== 'hosts'"
                            size="sm"
                            variant="danger"
                            :disabled="!selectedId"
                            title="Remove selected"
                            @click="handleSelectedRemove"
                        >
                            🗑 Remove
                        </Btn>

                        <!-- Disconnect All Active Sessions Button -->
                        <Btn
                            v-if="can.manage && activeTab === 'active' && active.length > 0"
                            size="sm"
                            variant="danger"
                            title="Disconnect semua sesi aktif sekaligus"
                            @click="disconnectAllActive"
                        >
                            ⚡ Disconnect All ({{ active.length }})
                        </Btn>

                        <!-- Export CSV Buttons -->
                        <Btn
                            v-if="activeTab === 'users' || activeTab === 'non-aktif'"
                            size="sm"
                            variant="ghost"
                            title="Export data user / voucher ke file CSV"
                            @click="exportUsersCsv"
                        >
                            📥 Export CSV
                        </Btn>
                        <Btn
                            v-else-if="activeTab === 'active'"
                            size="sm"
                            variant="ghost"
                            title="Export data sesi aktif ke file CSV"
                            @click="exportActiveCsv"
                        >
                            📥 Export CSV
                        </Btn>
                        <Btn
                            v-else-if="activeTab === 'ip-bindings'"
                            size="sm"
                            variant="ghost"
                            title="Export IP bindings ke file CSV"
                            @click="exportBindingsCsv"
                        >
                            📥 Export CSV
                        </Btn>
                        <Btn
                            v-else-if="activeTab === 'hosts'"
                            size="sm"
                            variant="ghost"
                            title="Export hosts ke file CSV"
                            @click="exportHostsCsv"
                        >
                            📥 Export CSV
                        </Btn>

                        <Btn size="sm" variant="ghost" title="Refresh" @click="refreshData">
                            ↻ Refresh
                        </Btn>
                    </div>

                    <!-- Search & Filters -->
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Non-Aktif sub-filter pills -->
                        <div v-if="activeTab === 'non-aktif'" class="flex items-center rounded-lg bg-zinc-100 p-0.5 text-xs dark:bg-zinc-800">
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 transition-all"
                                :class="nonActiveFilter === 'all' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="nonActiveFilter = 'all'"
                            >
                                Semua
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 transition-all"
                                :class="nonActiveFilter === 'disabled' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="nonActiveFilter = 'disabled'"
                            >
                                Disabled Saja
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 transition-all"
                                :class="nonActiveFilter === 'offline' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="nonActiveFilter = 'offline'"
                            >
                                Offline Saja
                            </button>
                        </div>

                        <!-- Server filter dropdown -->
                        <select
                            v-if="activeTab === 'users' || activeTab === 'active' || activeTab === 'non-aktif'"
                            v-model="serverFilter"
                            class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        >
                            <option value="all">Semua Server</option>
                            <option v-for="s in serverNames" :key="s" :value="s">{{ s }}</option>
                        </select>

                        <!-- Profile filter dropdown -->
                        <select
                            v-if="activeTab === 'users' || activeTab === 'non-aktif'"
                            v-model="profileFilter"
                            class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        >
                            <option value="all">Semua Profile</option>
                            <option v-for="p in userProfileNames" :key="p" :value="p">{{ p }}</option>
                        </select>

                        <!-- Search Input -->
                        <div class="relative">
                            <input
                                v-model="searchQuery"
                                type="search"
                                placeholder="Cari (Winbox search)..."
                                class="w-44 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 pl-8 text-xs placeholder-zinc-400 transition-all focus:w-60 focus:outline-hidden focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                            <span class="pointer-events-none absolute left-2.5 top-2 text-xs text-zinc-400">⌕</span>
                        </div>
                    </div>
                </div>

                <!-- ── Multi-Select Batch Actions Bar ─────────────────────── -->
                <div
                    v-if="(activeTab === 'users' || activeTab === 'non-aktif') && selectedUserIds.length > 0"
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-200 bg-amber-50/90 px-4 py-2 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-5 items-center justify-center rounded-full bg-amber-600 px-2 text-[11px] font-bold text-white">
                            {{ selectedUserIds.length }}
                        </span>
                        <span class="font-medium">user / voucher hotspot terpilih</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn
                            size="sm"
                            variant="secondary"
                            class="bg-white hover:bg-zinc-100 dark:bg-zinc-800"
                            title="Aktifkan seluruh user terpilih"
                            @click="submitBatchAction('enable')"
                        >
                            ✓ Aktifkan
                        </Btn>
                        <Btn
                            size="sm"
                            variant="secondary"
                            class="bg-white hover:bg-zinc-100 dark:bg-zinc-800"
                            title="Nonaktifkan seluruh user terpilih"
                            @click="submitBatchAction('disable')"
                        >
                            ✕ Nonaktifkan
                        </Btn>
                        <Btn
                            size="sm"
                            variant="secondary"
                            class="bg-white hover:bg-zinc-100 dark:bg-zinc-800"
                            title="Ubah profil paket untuk seluruh user terpilih"
                            @click="showBatchProfileModal = true"
                        >
                            📂 Ganti Profile
                        </Btn>
                        <Btn
                            size="sm"
                            variant="secondary"
                            class="border border-indigo-200 bg-white font-medium text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:bg-zinc-800 dark:text-indigo-300"
                            title="Cetak voucher terpilih"
                            @click="openPrintBatch"
                        >
                            🖨️ Cetak Terpilih ({{ selectedUserIds.length }})
                        </Btn>
                        <Btn
                            size="sm"
                            variant="danger"
                            title="Hapus permanen seluruh user terpilih"
                            @click="submitBatchAction('destroy')"
                        >
                            🗑 Hapus
                        </Btn>
                        <button
                            type="button"
                            class="ml-2 text-xs text-zinc-500 hover:text-zinc-700 underline dark:text-zinc-400"
                            @click="clearUserSelection"
                        >
                            Batal
                        </button>
                    </div>
                </div>

                <!-- Tab 1: Users (Vouchers / Accounts) -->
                <div v-if="activeTab === 'users'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-10 px-3 py-2.5 text-center">
                                    <input
                                        type="checkbox"
                                        class="rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800"
                                        :checked="paginatedUsers.length > 0 && paginatedUsers.every((u) => selectedUserIds.includes(u['.id']))"
                                        @change="toggleSelectAllVisible(paginatedUsers)"
                                    />
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('status')">
                                    Status
                                    <span v-if="sortKey === 'status'" class="ml-1 text-amber-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('name')">
                                    User (Voucher)
                                    <span v-if="sortKey === 'name'" class="ml-1 text-amber-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('profile')">
                                    Profile
                                    <span v-if="sortKey === 'profile'" class="ml-1 text-amber-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('server')">
                                    Server
                                    <span v-if="sortKey === 'server'" class="ml-1 text-amber-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('uptime')">
                                    Uptime
                                    <span v-if="sortKey === 'uptime'" class="ml-1 text-amber-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('bytes')">
                                    Bytes (In / Out)
                                    <span v-if="sortKey === 'bytes'" class="ml-1 text-amber-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('limit-uptime')">
                                    Limit Uptime
                                    <span v-if="sortKey === 'limit-uptime'" class="ml-1 text-amber-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer select-none px-4 py-2.5 hover:text-zinc-700 dark:hover:text-zinc-200" @click="setSort('comment')">
                                    Comment
                                    <span v-if="sortKey === 'comment'" class="ml-1 text-amber-500">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="paginatedUsers.length === 0">
                                <td colspan="10" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada user hotspot yang cocok.
                                </td>
                            </tr>
                            <tr
                                v-for="u in paginatedUsers"
                                :key="u['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="[
                                    selectedId === u['.id'] && 'bg-amber-50/70 dark:bg-amber-950/40',
                                    selectedUserIds.includes(u['.id']) && 'bg-amber-50/40 dark:bg-amber-950/20',
                                    isDisabled(u) && 'opacity-60',
                                ]"
                                @click="selectedId = u['.id']"
                                @dblclick="openEditUser(u)"
                            >
                                <td class="px-3 py-2.5 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800"
                                        :checked="selectedUserIds.includes(u['.id'])"
                                        @change="toggleUserSelection(u['.id'])"
                                    />
                                </td>
                                <td class="px-4 py-2.5">
                                    <span v-if="isOnline(u.name)" class="inline-flex items-center gap-1.5 font-medium text-emerald-600 dark:text-emerald-400">
                                        <StatusDot color="emerald" :pulse="true" /> Online
                                    </span>
                                    <span v-else-if="isDisabled(u)" class="inline-flex items-center gap-1.5 text-zinc-400">
                                        <StatusDot color="zinc" /> Disabled
                                    </span>
                                    <span v-else class="inline-flex items-center gap-1.5 text-zinc-500">
                                        <StatusDot color="amber" /> Offline
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ u.name }}</span>
                                        <span v-if="u['mac-address']" class="font-mono text-[10px] text-zinc-400" :title="'MAC Lock: ' + u['mac-address']">
                                            [🔒 {{ u['mac-address'] }}]
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ u.profile || 'default' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ u.server || 'all' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-500">
                                    {{ u.uptime || '0s' }}
                                </td>
                                <td class="px-4 py-2.5 font-mono tabular-nums text-zinc-600 dark:text-zinc-400">
                                    {{ fmtBytes(u['bytes-in']) }} / {{ fmtBytes(u['bytes-out']) }}
                                </td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-500">
                                    {{ u['limit-uptime'] || 'unlimited' }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500 italic">
                                    {{ u.comment || '' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end items-center gap-1">
                                        <Btn
                                            size="sm"
                                            variant="ghost"
                                            class="px-1.5 text-amber-600 hover:text-amber-700"
                                            title="Ubah Password Cepat"
                                            @click="openChangePassword(u)"
                                        >
                                            🔑
                                        </Btn>
                                        <Btn size="sm" @click="toggleUser(u)">
                                            {{ isDisabled(u) ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" @click="openEditUser(u)">Edit</Btn>
                                        <Btn size="sm" variant="danger" @click="removeUser(u)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Users Pagination & Per-Page Controls -->
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-white px-4 py-2.5 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900">
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
                                Total: <strong>{{ sortedUsers.length }}</strong> user
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
                </div>

                <!-- Tab 2: Active Sessions -->
                <div v-if="activeTab === 'active'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">Status</th>
                                <th class="px-4 py-2.5">User</th>
                                <th class="px-4 py-2.5">Server</th>
                                <th class="px-4 py-2.5">IP Address</th>
                                <th class="px-4 py-2.5">MAC Address</th>
                                <th class="px-4 py-2.5">Uptime</th>
                                <th class="px-4 py-2.5">Time Left</th>
                                <th class="px-4 py-2.5 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredActive.length === 0">
                                <td colspan="9" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada user hotspot aktif saat ini.
                                </td>
                            </tr>
                            <tr
                                v-for="(a, idx) in filteredActive"
                                :key="a['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="selectedId === a['.id'] && 'bg-amber-50/70 dark:bg-amber-950/40'"
                                @click="selectedId = a['.id']"
                            >
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center gap-1.5 font-medium text-emerald-600 dark:text-emerald-400">
                                        <StatusDot color="emerald" :pulse="true" /> Online
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ a.user }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">
                                    {{ a.server || 'hotspot' }}
                                </td>
                                <td class="px-4 py-2.5 font-mono font-medium text-zinc-800 dark:text-zinc-200">
                                    <div class="flex items-center gap-1">
                                        <span>{{ a.address || '—' }}</span>
                                        <button
                                            v-if="a.address"
                                            type="button"
                                            class="text-[10px] text-zinc-400 hover:text-zinc-600"
                                            title="Salin IP"
                                            @click.stop="copyText(a.address, 'ip-' + a['.id'])"
                                        >
                                            {{ copiedKey === 'ip-' + a['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    <div class="flex items-center gap-1">
                                        <span>{{ a['mac-address'] || '—' }}</span>
                                        <button
                                            v-if="a['mac-address']"
                                            type="button"
                                            class="text-[10px] text-zinc-400 hover:text-zinc-600"
                                            title="Salin MAC"
                                            @click.stop="copyText(a['mac-address'], 'mac-' + a['.id'])"
                                        >
                                            {{ copiedKey === 'mac-' + a['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-600 dark:text-zinc-400">
                                    {{ a.uptime || '—' }}
                                </td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-600 dark:text-zinc-400">
                                    {{ a['time-left'] || '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end items-center gap-1">
                                        <Btn
                                            v-if="a['mac-address']"
                                            size="sm"
                                            variant="secondary"
                                            class="border border-sky-300 bg-sky-50 text-sky-800 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300"
                                            title="1-Click Bypass MAC ke IP Bindings (Bebas Login)"
                                            @click="openQuickBypass(a['mac-address'], a.address, a.server, `Bypass dari user aktif ${a.user}`)"
                                        >
                                            ⚡ Bypass
                                        </Btn>
                                        <Btn size="sm" variant="danger" @click="disconnectSession(a)">
                                            Disconnect
                                        </Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Tab 3: Non-Aktif (Offline / Disabled) -->
                <div v-if="activeTab === 'non-aktif'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">Status</th>
                                <th class="px-4 py-2.5">User</th>
                                <th class="px-4 py-2.5">Profile</th>
                                <th class="px-4 py-2.5">Server</th>
                                <th class="px-4 py-2.5">Limit Uptime</th>
                                <th class="px-4 py-2.5">Comment</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="nonActiveUsers.length === 0">
                                <td colspan="8" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada user non-aktif pada filter ini.
                                </td>
                            </tr>
                            <tr
                                v-for="(u, idx) in nonActiveUsers"
                                :key="u['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="[
                                    selectedId === u['.id'] && 'bg-amber-50/70 dark:bg-amber-950/40',
                                    isDisabled(u) && 'opacity-60',
                                ]"
                                @click="selectedId = u['.id']"
                                @dblclick="openEditUser(u)"
                            >
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5">
                                    <span v-if="isDisabled(u)" class="inline-flex items-center gap-1.5 rounded bg-zinc-100 px-2 py-0.5 font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        <StatusDot color="zinc" /> Disabled
                                    </span>
                                    <span v-else class="inline-flex items-center gap-1.5 rounded bg-amber-50 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">
                                        <StatusDot color="amber" /> Offline
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ u.name }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">
                                    {{ u.profile || 'default' }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ u.server || 'all' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-500">
                                    {{ u['limit-uptime'] || 'unlimited' }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500 italic">
                                    {{ u.comment || '' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end items-center gap-1">
                                        <Btn
                                            size="sm"
                                            variant="ghost"
                                            class="px-1.5 text-amber-600 hover:text-amber-700"
                                            title="Ubah Password Cepat"
                                            @click="openChangePassword(u)"
                                        >
                                            🔑
                                        </Btn>
                                        <Btn size="sm" @click="toggleUser(u)">
                                            {{ isDisabled(u) ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" @click="openEditUser(u)">Edit</Btn>
                                        <Btn size="sm" variant="danger" @click="removeUser(u)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Tab 4: User Profiles -->
                <div v-if="activeTab === 'user-profiles'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">Profile Name</th>
                                <th class="px-4 py-2.5">Rate Limit (rx/tx)</th>
                                <th class="px-4 py-2.5">Shared Users</th>
                                <th class="px-4 py-2.5">Address Pool</th>
                                <th class="px-4 py-2.5">Session Timeout</th>
                                <th class="px-4 py-2.5">Idle Timeout</th>
                                <th class="px-4 py-2.5">Parent Queue</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredUserProfiles.length === 0">
                                <td colspan="9" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada user profile.
                                </td>
                            </tr>
                            <tr
                                v-for="(p, idx) in filteredUserProfiles"
                                :key="p['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="selectedId === p['.id'] && 'bg-amber-50/70 dark:bg-amber-950/40'"
                                @click="selectedId = p['.id']"
                                @dblclick="openEditProfile(p)"
                            >
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ p.name }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <span v-if="p['rate-limit']" class="rounded bg-amber-50 px-2 py-0.5 font-mono text-[11px] font-semibold text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
                                        {{ p['rate-limit'] }}
                                    </span>
                                    <span v-else class="text-zinc-400">unlimited</span>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">
                                    {{ p['shared-users'] || '1' }}
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    {{ p['address-pool'] || 'none' }}
                                </td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-500">
                                    {{ p['session-timeout'] || 'none' }}
                                </td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-500">
                                    {{ p['idle-timeout'] || 'none' }}
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    {{ p['parent-queue'] || 'none' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end gap-1">
                                        <Btn size="sm" variant="ghost" @click="openEditProfile(p)">Edit</Btn>
                                        <Btn size="sm" variant="danger" @click="removeProfile(p)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Tab 5: Servers -->
                <div v-if="activeTab === 'servers'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">State</th>
                                <th class="px-4 py-2.5">Server Name</th>
                                <th class="px-4 py-2.5">Interface</th>
                                <th class="px-4 py-2.5">Address Pool</th>
                                <th class="px-4 py-2.5">Profile</th>
                                <th class="px-4 py-2.5">Idle Timeout</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="servers.length === 0">
                                <td colspan="8" class="px-4 py-6 text-center text-zinc-500">No hotspot servers configured.</td>
                            </tr>
                            <tr
                                v-for="(s, idx) in servers"
                                :key="s['.id']"
                                class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 cursor-pointer"
                                :class="[
                                    selectedId === s['.id'] && 'bg-amber-50/70 dark:bg-amber-950/40',
                                    s.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="selectedId = s['.id']"
                                @dblclick="openEditServer(s)"
                            >
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5">
                                    <StatusDot :color="s.disabled === 'true' ? 'zinc' : 'emerald'" :label="s.disabled === 'true' ? 'disabled' : 'running'" />
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">{{ s.name }}</td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">{{ s.interface }}</td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">{{ s['address-pool'] || 'none' }}</td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">{{ s.profile || 'default' }}</td>
                                <td class="px-4 py-2.5 text-zinc-500">{{ s['idle-timeout'] || '5m' }}</td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end gap-1">
                                        <Btn size="sm" @click="toggleServer(s)">{{ s.disabled === 'true' ? 'Enable' : 'Disable' }}</Btn>
                                        <Btn size="sm" variant="ghost" @click="openEditServer(s)">Edit</Btn>
                                        <Btn size="sm" variant="danger" @click="removeServer(s)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Tab 6: Server Profiles -->
                <div v-if="activeTab === 'server-profiles'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">Profile Name</th>
                                <th class="px-4 py-2.5">Hotspot Address</th>
                                <th class="px-4 py-2.5">DNS Name</th>
                                <th class="px-4 py-2.5">HTML Directory</th>
                                <th class="px-4 py-2.5">Login By</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredServerProfiles.length === 0">
                                <td colspan="7" class="px-4 py-6 text-center text-zinc-500">Tidak ada server profile.</td>
                            </tr>
                            <tr
                                v-for="(sp, idx) in filteredServerProfiles"
                                :key="sp['.id']"
                                class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 cursor-pointer"
                                :class="selectedId === sp['.id'] && 'bg-amber-50/70 dark:bg-amber-950/40'"
                                @click="selectedId = sp['.id']"
                                @dblclick="openEditServerProfile(sp)"
                            >
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">{{ sp.name }}</td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">{{ sp['hotspot-address'] || '—' }}</td>
                                <td class="px-4 py-2.5 font-mono font-medium text-amber-600 dark:text-amber-400">{{ sp['dns-name'] || '—' }}</td>
                                <td class="px-4 py-2.5 text-zinc-500">{{ sp['html-directory'] || 'hotspot' }}</td>
                                <td class="px-4 py-2.5 text-zinc-500">{{ sp['login-by'] || 'http-chap' }}</td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end gap-1">
                                        <Btn size="sm" variant="ghost" @click="openEditServerProfile(sp)">Edit</Btn>
                                        <Btn size="sm" variant="danger" @click="removeServerProfile(sp)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Tab 7: IP Bindings -->
                <div v-if="activeTab === 'ip-bindings'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">Type</th>
                                <th class="px-4 py-2.5">MAC Address</th>
                                <th class="px-4 py-2.5">Address</th>
                                <th class="px-4 py-2.5">To Address</th>
                                <th class="px-4 py-2.5">Server</th>
                                <th class="px-4 py-2.5">Comment</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredIpBindings.length === 0">
                                <td colspan="8" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada IP binding.
                                </td>
                            </tr>
                            <tr
                                v-for="(b, idx) in filteredIpBindings"
                                :key="b['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="[
                                    selectedId === b['.id'] && 'bg-amber-50/70 dark:bg-amber-950/40',
                                    b.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="selectedId = b['.id']"
                                @dblclick="openEditBinding(b)"
                            >
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5">
                                    <span
                                        class="rounded px-2 py-0.5 text-[10px] font-semibold uppercase"
                                        :class="b.type === 'bypassed'
                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
                                            : b.type === 'blocked'
                                                ? 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300'
                                                : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'"
                                    >
                                        {{ b.type }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-mono font-medium text-zinc-800 dark:text-zinc-200">
                                    <div class="flex items-center gap-1">
                                        <span>{{ b['mac-address'] || '—' }}</span>
                                        <button
                                            v-if="b['mac-address']"
                                            type="button"
                                            class="text-[10px] text-zinc-400 hover:text-zinc-600"
                                            title="Salin MAC"
                                            @click.stop="copyText(b['mac-address'], 'bmac-' + b['.id'])"
                                        >
                                            {{ copiedKey === 'bmac-' + b['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">
                                    {{ b.address || '—' }}
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">
                                    {{ b['to-address'] || '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500">
                                    {{ b.server || 'all' }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500 italic">
                                    {{ b.comment || '' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end gap-1">
                                        <Btn size="sm" @click="toggleBinding(b)">
                                            {{ b.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" @click="openEditBinding(b)">Edit</Btn>
                                        <Btn size="sm" variant="danger" @click="removeBinding(b)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Tab 8: Hosts -->
                <div v-if="activeTab === 'hosts'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">MAC Address</th>
                                <th class="px-4 py-2.5">IP Address</th>
                                <th class="px-4 py-2.5">To Address</th>
                                <th class="px-4 py-2.5">Server</th>
                                <th class="px-4 py-2.5">Authorized</th>
                                <th class="px-4 py-2.5">Bypassed</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredHosts.length === 0">
                                <td colspan="8" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada host yang terdeteksi di jaringan hotspot.
                                </td>
                            </tr>
                            <tr v-for="(h, idx) in filteredHosts" :key="h['.id']" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5 font-mono font-medium text-zinc-900 dark:text-zinc-100">
                                    <div class="flex items-center gap-1">
                                        <span>{{ h['mac-address'] }}</span>
                                        <button
                                            type="button"
                                            class="text-[10px] text-zinc-400 hover:text-zinc-600"
                                            title="Salin MAC"
                                            @click.stop="copyText(h['mac-address'], 'hmac-' + h['.id'])"
                                        >
                                            {{ copiedKey === 'hmac-' + h['.id'] ? '✓' : '⧉' }}
                                        </button>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">{{ h.address || '—' }}</td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">{{ h['to-address'] || '—' }}</td>
                                <td class="px-4 py-2.5 text-zinc-500">{{ h.server || 'hotspot' }}</td>
                                <td class="px-4 py-2.5">
                                    <span v-if="h.authorized === 'true'" class="text-emerald-600 dark:text-emerald-400 font-medium">yes</span>
                                    <span v-else class="text-zinc-400">no</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span v-if="h.bypassed === 'true'" class="text-blue-600 dark:text-blue-400 font-medium">yes</span>
                                    <span v-else class="text-zinc-400">no</span>
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end items-center gap-1">
                                        <Btn
                                            size="sm"
                                            variant="secondary"
                                            class="border border-sky-300 bg-sky-50 text-sky-800 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300"
                                            title="Bypass perangkat ini ke IP Bindings"
                                            @click="openQuickBypass(h['mac-address'], h.address, h.server, `Bypass host ${h['mac-address']}`)"
                                        >
                                            ⚡ Bypass
                                        </Btn>
                                        <Btn
                                            size="sm"
                                            variant="danger"
                                            title="Hapus host entry"
                                            @click="removeHost(h)"
                                        >
                                            Del
                                        </Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-between border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-[11px] text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div>
                        <span>
                            Total data: <strong class="text-zinc-700 dark:text-zinc-300">
                                {{ activeTab === 'users' ? filteredUsers.length : activeTab === 'active' ? filteredActive.length : activeTab === 'non-aktif' ? nonActiveUsers.length : activeTab === 'user-profiles' ? filteredUserProfiles.length : activeTab === 'server-profiles' ? filteredServerProfiles.length : activeTab === 'ip-bindings' ? filteredIpBindings.length : activeTab === 'hosts' ? filteredHosts.length : servers.length }}
                            </strong> items
                        </span>
                        <span v-if="selectedId" class="text-amber-600 dark:text-amber-400">
                            • Selected ({{ selectedId }})
                        </span>
                    </div>
                    <div class="text-zinc-400">
                        Double-click row to edit • Click row to select
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Modal: Add / Edit User ─────────────────────────────────── -->
        <Modal
            :open="showUserModal"
            :title="editingUser ? `Edit Hotspot User: ${editingUser.name}` : 'Add Hotspot User (Voucher)'"
            @close="showUserModal = false"
        >
            <form class="grid grid-cols-2 gap-4 text-xs" @submit.prevent="submitUser">
                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Username (Voucher Code)</label>
                    <input
                        v-model="userForm.name"
                        placeholder="e.g. vc-2001"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                    <p v-if="userForm.errors.name" class="mt-1 text-red-500">{{ userForm.errors.name }}</p>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Password</label>
                    <input
                        v-model="userForm.password"
                        placeholder="e.g. 123456"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">User Profile</label>
                    <select
                        v-model="userForm.profile"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option v-for="p in userProfileNames" :key="p" :value="p">{{ p }}</option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Hotspot Server</label>
                    <select
                        v-model="userForm.server"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="all">all</option>
                        <option v-for="s in serverNames" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">MAC Address (Lock to device)</label>
                    <input
                        v-model="userForm.mac_address"
                        placeholder="00:00:00:00:00:00"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Limit Uptime (Duration)</label>
                    <input
                        v-model="userForm.limit_uptime"
                        placeholder="e.g. 1h, 1d, 30m"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Limit Bytes Total (Quota Kuota)</label>
                    <input
                        v-model="userForm.limit_bytes_total"
                        placeholder="e.g. 1073741824 (1GB) or leave blank"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="userForm.comment"
                        placeholder="e.g. Voucher Meja 5"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 mt-2 flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showUserModal = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="userForm.processing">
                        {{ editingUser ? 'Save Changes' : 'Create User' }}
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Quick Change Password ───────────────────────────── -->
        <Modal
            :open="showPasswordModal"
            :title="`Ubah Password: ${targetUserForPassword?.name}`"
            @close="showPasswordModal = false"
        >
            <form class="space-y-4 text-xs" @submit.prevent="submitChangePassword">
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Password Baru</label>
                    <div class="flex gap-2">
                        <input
                            v-model="passwordForm.password"
                            type="text"
                            placeholder="Ketik password baru..."
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                            required
                        />
                        <Btn type="button" variant="secondary" size="sm" @click="generateRandomPassword">
                            Acak
                        </Btn>
                    </div>
                    <p class="mt-1 text-[11px] text-zinc-500">
                        Password akan langsung diperbarui di MikroTik Hotspot tanpa merubah parameter lain.
                    </p>
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showPasswordModal = false">Batal</Btn>
                    <Btn type="submit" variant="primary" :disabled="passwordForm.processing">
                        {{ passwordForm.processing ? 'Menyimpan...' : 'Simpan Password' }}
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Batch Change Profile ────────────────────────────── -->
        <Modal
            :open="showBatchProfileModal"
            :title="`Ganti Profile (${selectedUserIds.length} User Terpilih)`"
            @close="showBatchProfileModal = false"
        >
            <div class="space-y-4 text-xs">
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Pilih User Profile Baru</label>
                    <select
                        v-model="batchTargetProfile"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option v-for="p in userProfileNames" :key="p" :value="p">{{ p }}</option>
                    </select>
                    <p class="mt-1 text-[11px] text-zinc-500">
                        Profil {{ selectedUserIds.length }} user akan diperbarui secara serentak ke paket ini.
                    </p>
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showBatchProfileModal = false">Batal</Btn>
                    <Btn variant="primary" @click="submitBatchAction('change_profile')">
                        Terapkan Profile Baru
                    </Btn>
                </div>
            </div>
        </Modal>

        <!-- ── Modal: Add / Edit User Profile ─────────────────────────── -->
        <Modal
            :open="showProfileModal"
            :title="editingProfile ? `Edit User Profile: ${editingProfile.name}` : 'Add Hotspot User Profile'"
            @close="showProfileModal = false"
        >
            <form class="grid grid-cols-2 gap-4 text-xs" @submit.prevent="submitProfile">
                <div class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Profile Name</label>
                    <input
                        v-model="profileForm.name"
                        placeholder="e.g. Paket-5Mbps"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                    <p v-if="profileForm.errors.name" class="mt-1 text-red-500">{{ profileForm.errors.name }}</p>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Rate Limit (rx/tx)</label>
                    <input
                        v-model="profileForm.rate_limit"
                        placeholder="e.g. 5M/5M"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Shared Users (Simultaneous)</label>
                    <input
                        v-model="profileForm.shared_users"
                        placeholder="1"
                        type="number"
                        min="1"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Session Timeout</label>
                    <input
                        v-model="profileForm.session_timeout"
                        placeholder="e.g. 1h, 12h"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Idle Timeout</label>
                    <input
                        v-model="profileForm.idle_timeout"
                        placeholder="e.g. 5m, 15m"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Keepalive Timeout</label>
                    <input
                        v-model="profileForm.keepalive_timeout"
                        placeholder="2m"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Parent Queue (QoS)</label>
                    <input
                        v-model="profileForm.parent_queue"
                        placeholder="none atau nama parent queue"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Address Pool</label>
                    <select
                        v-model="profileForm.address_pool"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="">(none / Kosong)</option>
                        <optgroup v-if="(pools ?? []).length > 0" label="Pilihan IP Pool (MikroTik)">
                            <option v-for="p in (pools ?? [])" :key="p['.id']" :value="p.name">
                                Pool: {{ p.name }} ({{ p.ranges || 'range' }})
                            </option>
                        </optgroup>
                    </select>
                </div>

                <div class="col-span-2 mt-2 flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showProfileModal = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="profileForm.processing">
                        {{ editingProfile ? 'Save Profile' : 'Create Profile' }}
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Add / Edit Hotspot Server ───────────────────────── -->
        <Modal
            :open="showServerModal"
            :title="editingServer ? `Edit Server: ${editingServer.name}` : 'Add Hotspot Server'"
            @close="showServerModal = false"
        >
            <form class="grid grid-cols-2 gap-4 text-xs" @submit.prevent="submitServer">
                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Server Name</label>
                    <input
                        v-model="serverForm.name"
                        placeholder="hotspot1"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Interface</label>
                    <select
                        v-model="serverForm.interface"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    >
                        <option value="">Pilih Interface</option>
                        <option v-for="i in interfaces" :key="i" :value="i">{{ i }}</option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Server Profile</label>
                    <select
                        v-model="serverForm.profile"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option v-for="sp in serverProfiles" :key="sp['.id']" :value="sp.name">{{ sp.name }}</option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Idle Timeout</label>
                    <input
                        v-model="serverForm.idle_timeout"
                        placeholder="5m"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 mt-2 flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showServerModal = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="serverForm.processing">
                        {{ editingServer ? 'Save Server' : 'Create Server' }}
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Add / Edit Server Profile ───────────────────────── -->
        <Modal
            :open="showServerProfileModal"
            :title="editingServerProfile ? `Edit Server Profile: ${editingServerProfile.name}` : 'Add Hotspot Server Profile'"
            @close="showServerProfileModal = false"
        >
            <form class="grid grid-cols-2 gap-4 text-xs" @submit.prevent="submitServerProfile">
                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Profile Name</label>
                    <input
                        v-model="serverProfileForm.name"
                        placeholder="hsprof1"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Hotspot Address</label>
                    <input
                        v-model="serverProfileForm.hotspot_address"
                        placeholder="192.168.88.1"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">DNS Name (Domain Login)</label>
                    <input
                        v-model="serverProfileForm.dns_name"
                        placeholder="e.g. wifi.login atau hotspot.net"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">HTML Directory</label>
                    <input
                        v-model="serverProfileForm.html_directory"
                        placeholder="hotspot"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Login By</label>
                    <input
                        v-model="serverProfileForm.login_by"
                        placeholder="e.g. http-chap,cookie,mac"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 mt-2 flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showServerProfileModal = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="serverProfileForm.processing">
                        {{ editingServerProfile ? 'Save Server Profile' : 'Create Server Profile' }}
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Add / Edit IP Binding ───────────────────────────── -->
        <Modal
            :open="showBindingModal"
            :title="editingBinding ? 'Edit IP Binding' : 'Add IP Binding (Bypass / Block)'"
            @close="showBindingModal = false"
        >
            <form class="grid grid-cols-2 gap-4 text-xs" @submit.prevent="submitBinding">
                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">MAC Address</label>
                    <input
                        v-model="bindingForm.mac_address"
                        placeholder="00:1A:2B:3C:4D:5E"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Type</label>
                    <select
                        v-model="bindingForm.type"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="bypassed">bypassed (tanpa login)</option>
                        <option value="blocked">blocked (blokir)</option>
                        <option value="regular">regular (harus login)</option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Address (Optional)</label>
                    <input
                        v-model="bindingForm.address"
                        placeholder="192.168.88.200"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">To Address (Optional)</label>
                    <input
                        v-model="bindingForm.to_address"
                        placeholder="192.168.88.200"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="bindingForm.comment"
                        placeholder="e.g. Smart TV Kasir / CCTV"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 mt-2 flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showBindingModal = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="bindingForm.processing">
                        {{ editingBinding ? 'Save Binding' : 'Create Binding' }}
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Quick Bypass Device ─────────────────────────────── -->
        <Modal
            :open="showQuickBypassModal"
            :title="`⚡ Quick Bypass Perangkat: ${quickBypassForm.mac_address}`"
            @close="showQuickBypassModal = false"
        >
            <form class="space-y-4 text-xs" @submit.prevent="submitQuickBypass">
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">MAC Address</label>
                    <input
                        v-model="quickBypassForm.mac_address"
                        class="w-full rounded-lg border border-zinc-300 bg-zinc-100 px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        readonly
                        required
                    />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">IP Address (Opsional)</label>
                        <input
                            v-model="quickBypassForm.address"
                            placeholder="192.168.88.x"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Server</label>
                        <select
                            v-model="quickBypassForm.server"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        >
                            <option value="all">all</option>
                            <option v-for="s in serverNames" :key="s" :value="s">{{ s }}</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Keterangan / Komentar</label>
                    <input
                        v-model="quickBypassForm.comment"
                        placeholder="e.g. Printer Kasir / Device Khusus"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                    <p class="mt-1 text-[11px] text-zinc-500">
                        Perangkat ini akan otomatis dapat mengakses internet tanpa perlu login ke Captive Portal hotspot.
                    </p>
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showQuickBypassModal = false">Batal</Btn>
                    <Btn type="submit" variant="primary" :disabled="quickBypassForm.processing">
                        ⚡ Simpan & Bypass Sekarang
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Generate Hotspot Vouchers ────────────────────────── -->
        <Modal
            :open="showGenerateModal"
            title="🎟️ Generate Voucher Hotspot (Batch)"
            @close="showGenerateModal = false"
        >
            <div v-if="generateError" class="mb-3 rounded-lg bg-rose-50 p-2.5 text-xs text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
                ⚠ {{ generateError }}
            </div>
            <form class="grid grid-cols-2 gap-3.5 text-xs" @submit.prevent="handleGenerateVouchers">
                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Jumlah Voucher (Qty)</label>
                    <input
                        v-model.number="voucherForm.quantity"
                        type="number"
                        min="1"
                        max="200"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Server Hotspot</label>
                    <select
                        v-model="voucherForm.server"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="all">Semua Server (all)</option>
                        <option v-for="srv in servers" :key="srv['.id']" :value="srv.name">{{ srv.name }}</option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">User Profile (Paket)</label>
                    <select
                        v-model="voucherForm.profile"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    >
                        <option v-for="p in userProfiles" :key="p['.id']" :value="p.name">
                            {{ p.name }} {{ p['rate-limit'] ? `(${p['rate-limit']})` : '' }}
                        </option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Karakter / Charset</label>
                    <select
                        v-model="voucherForm.charset"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="alphanumeric">Alfanumerik (Huruf & Angka Acak)</option>
                        <option value="numeric">Angka Saja (0-9)</option>
                        <option value="lowercase">Huruf Kecil Saja (a-z)</option>
                        <option value="uppercase">Huruf Besar Saja (A-Z)</option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Prefix Kode (Awalan)</label>
                    <input
                        v-model="voucherForm.prefix"
                        placeholder="e.g. vc- atau kosong"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Panjang Kode (Karakter)</label>
                    <input
                        v-model.number="voucherForm.length"
                        type="number"
                        min="3"
                        max="12"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Limit Waktu (Uptime)</label>
                    <input
                        v-model="voucherForm.limit_uptime"
                        placeholder="e.g. 1h, 3h, 1d"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Harga Voucher (Rp)</label>
                    <input
                        v-model.number="voucherForm.price"
                        type="number"
                        placeholder="5000"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 mt-2 flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" :disabled="isGenerating" @click="showGenerateModal = false">Batal</Btn>
                    <Btn type="submit" variant="primary" :disabled="isGenerating">
                        {{ isGenerating ? 'Sedang Generate...' : '⚡ Generate Sekarang' }}
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Print Voucher Preview (Mikhmon Style) ─────────────── -->
        <Teleport to="body">
            <div
                v-if="showPrintModal"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 pt-8 print:p-0 print:bg-white"
                @click.self="showPrintModal = false"
            >
                <div class="w-full max-w-4xl rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900 overflow-hidden print:border-none print:shadow-none print:max-w-none">
                    <!-- Modal Header (Controls) -->
                    <div class="print:hidden flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50 px-5 py-3 dark:border-zinc-800 dark:bg-zinc-800/60">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">🖨️</span>
                            <div>
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                    Cetak Voucher Hotspot
                                </h3>
                                <p class="text-xs text-zinc-500">
                                    {{ printVouchers.length }} voucher siap dicetak.
                                </p>
                            </div>
                        </div>

                        <!-- Template Switcher & Actions -->
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="flex items-center rounded-lg bg-zinc-200/70 p-0.5 text-xs dark:bg-zinc-700">
                                <button
                                    type="button"
                                    class="rounded-md px-2.5 py-1 transition-all"
                                    :class="printLayout === 'gridA4' ? 'bg-white font-semibold text-zinc-900 shadow-xs dark:bg-zinc-800 dark:text-zinc-100' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300'"
                                    @click="printLayout === 'gridA4'"
                                >
                                    📄 Kertas A4 Grid
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md px-2.5 py-1 transition-all"
                                    :class="printLayout === 'thermal80' ? 'bg-white font-semibold text-zinc-900 shadow-xs dark:bg-zinc-800 dark:text-zinc-100' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300'"
                                    @click="printLayout === 'thermal80'"
                                >
                                    🧾 Thermal 80mm
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md px-2.5 py-1 transition-all"
                                    :class="printLayout === 'thermal58' ? 'bg-white font-semibold text-zinc-900 shadow-xs dark:bg-zinc-800 dark:text-zinc-100' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300'"
                                    @click="printLayout === 'thermal58'"
                                >
                                    🧾 Thermal 58mm
                                </button>
                            </div>

                            <Btn size="sm" variant="primary" @click="printNow">
                                🖨️ Cetak (Print)
                            </Btn>

                            <button
                                type="button"
                                class="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-200 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-100"
                                @click="showPrintModal = false"
                            >
                                ✕
                            </button>
                        </div>
                    </div>

                    <!-- Voucher Cards Container (Printable area) -->
                    <div id="voucher-print-area" class="max-h-[75vh] overflow-y-auto p-4 bg-zinc-100 dark:bg-zinc-950/40 print:max-h-none print:overflow-visible print:bg-white print:p-0">
                        <!-- Grid Layout (A4) -->
                        <div
                            v-if="printLayout === 'gridA4'"
                            class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 print:grid-cols-4 print:gap-2"
                        >
                            <div
                                v-for="(v, idx) in printVouchers"
                                :key="idx"
                                class="rounded-xl border-2 border-dashed border-zinc-300 bg-white p-3 text-center shadow-xs transition hover:border-amber-400 dark:border-zinc-700 dark:bg-zinc-900 print:break-inside-avoid print:border-black print:text-black print:shadow-none"
                            >
                                <div class="border-b border-zinc-200 pb-1.5 dark:border-zinc-800 print:border-black">
                                    <div class="text-[10px] font-bold tracking-widest text-amber-600 uppercase print:text-black">
                                        {{ router.name || 'HOTSPOT WI-FI' }}
                                    </div>
                                    <div class="text-[9px] font-medium text-zinc-400 print:text-black">
                                        VOUCHER INTERNET
                                    </div>
                                </div>

                                <div class="py-2.5">
                                    <div class="text-[10px] text-zinc-500 uppercase print:text-black">Kode Voucher</div>
                                    <div class="font-mono text-base font-extrabold tracking-wider text-zinc-900 dark:text-zinc-100 print:text-black">
                                        {{ v.username }}
                                    </div>
                                    <div v-if="v.password && v.password !== v.username" class="text-[11px] font-mono text-zinc-600 dark:text-zinc-400 print:text-black">
                                        Pass: {{ v.password }}
                                    </div>
                                    <div v-else class="text-[9px] text-zinc-400 print:text-black">
                                        (User & Password Sama)
                                    </div>
                                </div>

                                <div class="mt-1 flex items-center justify-between border-t border-zinc-200 pt-1.5 text-[10px] dark:border-zinc-800 print:border-black">
                                    <span class="rounded bg-amber-50 px-1.5 py-0.5 font-semibold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300 print:border print:border-black print:text-black">
                                        {{ v.profile }} {{ v.limit_uptime ? `• ${v.limit_uptime}` : '' }}
                                    </span>
                                    <span class="font-bold text-zinc-900 dark:text-zinc-100 print:text-black">
                                        {{ v.price ? `Rp ${Number(v.price).toLocaleString('id-ID')}` : (v.comment?.split('|')[1]?.trim() || '—') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Thermal Layout (80mm / 58mm) -->
                        <div
                            v-else
                            class="mx-auto space-y-4 print:space-y-3"
                            :class="printLayout === 'thermal58' ? 'max-w-[220px]' : 'max-w-[300px]'"
                        >
                            <div
                                v-for="(v, idx) in printVouchers"
                                :key="idx"
                                class="rounded-xl border border-dashed border-zinc-400 bg-white p-3 text-center shadow-xs dark:bg-zinc-900 print:break-inside-avoid print:border-black print:text-black"
                            >
                                <div class="border-b border-zinc-300 pb-1.5 dark:border-zinc-700 print:border-black">
                                    <div class="text-xs font-bold tracking-wider text-zinc-900 dark:text-zinc-100 print:text-black uppercase">
                                        {{ router.name || 'MIKROTIK HOTSPOT' }}
                                    </div>
                                    <div class="text-[9px] text-zinc-500 print:text-black">
                                        ================================
                                    </div>
                                </div>

                                <div class="py-2">
                                    <div class="text-[10px] text-zinc-500 uppercase print:text-black">Kode Akses / Voucher</div>
                                    <div class="font-mono text-lg font-extrabold tracking-widest text-zinc-900 dark:text-zinc-100 print:text-black">
                                        {{ v.username }}
                                    </div>
                                    <div v-if="v.password && v.password !== v.username" class="text-xs font-mono text-zinc-700 dark:text-zinc-300 print:text-black">
                                        Password: {{ v.password }}
                                    </div>
                                </div>

                                <div class="border-t border-zinc-300 pt-1.5 text-[10px] dark:border-zinc-700 print:border-black">
                                    <div class="flex justify-between font-semibold">
                                        <span>Paket:</span>
                                        <span>{{ v.profile }}</span>
                                    </div>
                                    <div v-if="v.limit_uptime" class="flex justify-between">
                                        <span>Durasi:</span>
                                        <span>{{ v.limit_uptime }}</span>
                                    </div>
                                    <div class="mt-1 flex justify-between border-t border-zinc-200 pt-1 text-xs font-bold print:border-black">
                                        <span>Tarif:</span>
                                        <span>{{ v.price ? `Rp ${Number(v.price).toLocaleString('id-ID')}` : (v.comment?.split('|')[1]?.trim() || '—') }}</span>
                                    </div>
                                    <div class="mt-1 text-[8px] text-zinc-400 print:text-black">
                                        Hubungkan Wi-Fi lalu buka browser untuk login
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </RouterWorkspace>
</template>

<style>
@media print {
    body * {
        visibility: hidden !important;
    }
    #voucher-print-area,
    #voucher-print-area * {
        visibility: visible !important;
    }
    #voucher-print-area {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        background: white !important;
        color: black !important;
        margin: 0 !important;
        padding: 0 !important;
    }
}
</style>
