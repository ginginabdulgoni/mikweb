<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface Secret {
    '.id': string;
    name: string;
    password?: string;
    service?: string;
    profile?: string;
    disabled?: string;
    'local-address'?: string;
    'remote-address'?: string;
    'caller-id'?: string;
    routes?: string;
    comment?: string;
}

interface ActiveSession {
    '.id': string;
    name: string;
    service?: string;
    address?: string;
    uptime?: string;
    'caller-id'?: string;
    encoding?: string;
    'session-id'?: string;
    radius?: string;
    comment?: string;
}

interface Profile {
    '.id': string;
    name: string;
    'local-address'?: string;
    'remote-address'?: string;
    bridge?: string;
    'rate-limit'?: string;
    'dns-server'?: string;
    'only-one'?: string;
    'change-tcp-mss'?: string;
    'use-encryption'?: string;
    'use-mpls'?: string;
    'use-compression'?: string;
    'address-list'?: string;
    'parent-queue'?: string;
    'session-timeout'?: string;
    'idle-timeout'?: string;
    comment?: string;
}

interface PppoeServer {
    '.id': string;
    'service-name': string;
    interface: string;
    'default-profile'?: string;
    'max-mtu'?: string;
    'max-mru'?: string;
    'one-session-per-host'?: string;
    disabled?: string;
}

interface Pool {
    '.id': string;
    name: string;
    ranges?: string;
    'next-pool'?: string;
}

interface PppAaa {
    'use-radius'?: string;
    accounting?: string;
    'interim-update'?: string;
}

const props = defineProps<{
    router: any;
    secrets: Secret[];
    active: ActiveSession[];
    profiles: Profile[];
    servers?: PppoeServer[];
    interfaces?: string[];
    pools?: Pool[];
    aaa?: PppAaa;
    deviceError: string | null;
    can: { manage: boolean };
}>();

// ── IP Pool Map & Resolution Helpers ─────────────────────────────────
const poolMap = computed(() => {
    const map = new Map<string, Pool>();
    for (const p of props.pools ?? []) {
        map.set(p['.id'], p);
        map.set(p.name, p);
    }
    return map;
});

function getPool(val?: string): Pool | undefined {
    if (!val) return undefined;
    return poolMap.value.get(val);
}

function formatAddressOrPool(val?: string): string {
    if (!val) return '—';
    const pool = poolMap.value.get(val);
    if (pool) {
        return pool.name;
    }
    return val;
}

function isKnownPool(val?: string): boolean {
    if (!val) return false;
    return poolMap.value.has(val);
}

function resolvePoolName(val?: string): string {
    if (!val) return '';
    return poolMap.value.get(val)?.name ?? val;
}

// ── Tab Navigation ───────────────────────────────────────────────────
type TabKey = 'secrets' | 'active' | 'non-aktif' | 'profiles' | 'servers' | 'aaa';
const activeTab = ref<TabKey>('secrets');

// ── Selection & Filter States ────────────────────────────────────────
const selectedId = ref<string | null>(null);
const searchQuery = ref('');
const serviceFilter = ref('all');
const profileFilter = ref('all');
const nonActiveFilter = ref<'all' | 'disabled' | 'offline'>('all');

// Multi-select for Secrets
const selectedSecretIds = ref<string[]>([]);

// Active usernames map for O(1) online lookup
const activeMap = computed(() => {
    const map = new Map<string, ActiveSession>();
    for (const a of props.active) {
        map.set(a.name, a);
    }
    return map;
});

const isOnline = (name: string): boolean => activeMap.value.has(name);
const isDisabled = (s: Secret): boolean => s.disabled === 'true';

const selectedSecret = computed(() => {
    if (!selectedId.value) return null;
    return props.secrets.find((s) => s['.id'] === selectedId.value) ?? null;
});

// ── Summary KPI Stats ────────────────────────────────────────────────
const stats = computed(() => {
    const totalSecrets = props.secrets.length;
    const onlineCount = props.active.length;
    const disabledCount = props.secrets.filter((s) => s.disabled === 'true').length;
    const isolirCount = props.secrets.filter((s) => s.profile === 'isolir' || (s.comment ?? '').includes('[ORIG:')).length;
    const offlineCount = Math.max(0, totalSecrets - onlineCount - disabledCount);
    const serversCount = (props.servers ?? []).filter((s) => s.disabled !== 'true').length;

    return {
        totalSecrets,
        onlineCount,
        offlineCount,
        disabledCount,
        isolirCount,
        serversCount,
    };
});

// ── Filtered Datasets ────────────────────────────────────────────────
const filteredSecrets = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.secrets.filter((s) => {
        if (serviceFilter.value !== 'all' && s.service !== serviceFilter.value) return false;
        if (profileFilter.value !== 'all' && (s.profile ?? '') !== profileFilter.value) return false;
        if (!q) return true;
        return (
            s.name.toLowerCase().includes(q) ||
            (s.comment ?? '').toLowerCase().includes(q) ||
            (s['remote-address'] ?? '').toLowerCase().includes(q) ||
            (s['local-address'] ?? '').toLowerCase().includes(q) ||
            (s.profile ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredActive = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.active.filter((a) => {
        if (serviceFilter.value !== 'all' && a.service !== serviceFilter.value) return false;
        if (!q) return true;
        return (
            a.name.toLowerCase().includes(q) ||
            (a.address ?? '').toLowerCase().includes(q) ||
            (a['caller-id'] ?? '').toLowerCase().includes(q)
        );
    });
});

const nonActiveSecrets = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.secrets.filter((s) => {
        const disabled = isDisabled(s);
        const online = isOnline(s.name);

        if (online) return false; // Online users are never non-aktif

        if (nonActiveFilter.value === 'disabled' && !disabled) return false;
        if (nonActiveFilter.value === 'offline' && disabled) return false;

        if (serviceFilter.value !== 'all' && s.service !== serviceFilter.value) return false;
        if (profileFilter.value !== 'all' && (s.profile ?? '') !== profileFilter.value) return false;

        if (!q) return true;
        return (
            s.name.toLowerCase().includes(q) ||
            (s.comment ?? '').toLowerCase().includes(q) ||
            (s['remote-address'] ?? '').toLowerCase().includes(q) ||
            (s.profile ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredProfiles = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.profiles.filter((p) => {
        if (!q) return true;
        return (
            p.name.toLowerCase().includes(q) ||
            (p['rate-limit'] ?? '').toLowerCase().includes(q) ||
            (p['remote-address'] ?? '').toLowerCase().includes(q) ||
            (p['address-list'] ?? '').toLowerCase().includes(q) ||
            (p.comment ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredServers = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return (props.servers ?? []).filter((srv) => {
        if (!q) return true;
        return (
            srv['service-name'].toLowerCase().includes(q) ||
            srv.interface.toLowerCase().includes(q) ||
            (srv['default-profile'] ?? '').toLowerCase().includes(q)
        );
    });
});

// ── Sorting & Pagination for Secrets ─────────────────────────────────
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

const sortedSecrets = computed(() => {
    const list = [...filteredSecrets.value];
    list.sort((a, b) => {
        let valA = '';
        let valB = '';
        if (sortKey.value === 'name') {
            valA = a.name;
            valB = b.name;
        } else if (sortKey.value === 'service') {
            valA = a.service ?? '';
            valB = b.service ?? '';
        } else if (sortKey.value === 'profile') {
            valA = a.profile ?? '';
            valB = b.profile ?? '';
        } else if (sortKey.value === 'remote-address') {
            valA = a['remote-address'] ?? '';
            valB = b['remote-address'] ?? '';
        } else if (sortKey.value === 'comment') {
            valA = a.comment ?? '';
            valB = b.comment ?? '';
        } else if (sortKey.value === 'status') {
            valA = isOnline(a.name) ? '1' : (isDisabled(a) ? '3' : '2');
            valB = isOnline(b.name) ? '1' : (isDisabled(b) ? '3' : '2');
        }
        const cmp = valA.localeCompare(valB, undefined, { numeric: true });
        return sortOrder.value === 'asc' ? cmp : -cmp;
    });
    return list;
});

const paginatedSecrets = computed(() => {
    if (perPage.value >= 9999) return sortedSecrets.value;
    const start = (currentPage.value - 1) * perPage.value;
    return sortedSecrets.value.slice(start, start + perPage.value);
});

const totalPages = computed(() => {
    if (perPage.value >= 9999) return 1;
    return Math.max(1, Math.ceil(sortedSecrets.value.length / perPage.value));
});

watch([searchQuery, serviceFilter, profileFilter, perPage], () => {
    currentPage.value = 1;
});

// Multi-select helper functions
function toggleSelectAllVisible(items: Secret[]) {
    const visibleIds = items.map((i) => i['.id']);
    const allSelected = visibleIds.length > 0 && visibleIds.every((id) => selectedSecretIds.value.includes(id));
    if (allSelected) {
        selectedSecretIds.value = selectedSecretIds.value.filter((id) => !visibleIds.includes(id));
    } else {
        const set = new Set([...selectedSecretIds.value, ...visibleIds]);
        selectedSecretIds.value = Array.from(set);
    }
}

function toggleSecretSelection(id: string) {
    const idx = selectedSecretIds.value.indexOf(id);
    if (idx >= 0) {
        selectedSecretIds.value.splice(idx, 1);
    } else {
        selectedSecretIds.value.push(id);
    }
}

function clearSecretSelection() {
    selectedSecretIds.value = [];
}

// ── Modals & Forms ───────────────────────────────────────────────────
const showSecretModal = ref(false);
const editingSecret = ref<Secret | null>(null);
const secretLocalMode = ref<string>('');
const secretRemoteMode = ref<string>('');

const secretForm = useForm({
    id: '',
    name: '',
    password: '',
    service: 'pppoe',
    profile: '',
    local_address: '',
    remote_address: '',
    caller_id: '',
    routes: '',
    comment: '',
});

// Quick Password Modal
const showPasswordModal = ref(false);
const targetSecretForPassword = ref<Secret | null>(null);
const passwordForm = useForm({
    id: '',
    password: '',
});

function openChangePassword(s: Secret) {
    targetSecretForPassword.value = s;
    passwordForm.reset();
    passwordForm.id = s['.id'];
    passwordForm.password = '';
    showPasswordModal.value = true;
}

function submitChangePassword() {
    passwordForm.put(route('routers.ppp.secrets.password', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showPasswordModal.value = false;
        },
    });
}

// Batch Actions Modal
const showBatchProfileModal = ref(false);
const batchTargetProfile = ref('default');

function submitBatchAction(action: 'enable' | 'disable' | 'isolate' | 'restore' | 'destroy' | 'change_profile') {
    if (selectedSecretIds.value.length === 0) return;

    if (action === 'destroy') {
        if (!confirm(`Hapus ${selectedSecretIds.value.length} PPP secret terpilih? Tindakan ini tidak dapat dibatalkan.`)) {
            return;
        }
    } else if (action === 'isolate') {
        if (!confirm(`Isolir ${selectedSecretIds.value.length} pelanggan terpilih? Profil akan dialihkan ke "isolir" dan sesi aktif di-reset.`)) {
            return;
        }
    } else if (action === 'restore') {
        if (!confirm(`Buka isolasi untuk ${selectedSecretIds.value.length} pelanggan terpilih? Profil akan dikembalikan ke profil asal.`)) {
            return;
        }
    }

    inertia.post(
        route('routers.ppp.secrets.batch', props.router.id),
        {
            action,
            ids: selectedSecretIds.value,
            target_profile: action === 'change_profile' ? batchTargetProfile.value : undefined,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedSecretIds.value = [];
                showBatchProfileModal.value = false;
            },
        },
    );
}

// Batch Generator Modal
const showGenerateModal = ref(false);
const generateForm = useForm({
    count: 10,
    prefix: 'cust-',
    start_index: 1,
    user_format: 'sequential' as 'sequential' | 'random',
    password_mode: 'numeric' as 'same' | 'numeric' | 'alphanumeric' | 'custom',
    custom_password: '',
    service: 'pppoe',
    profile: '',
    local_address: '',
    remote_address: '',
    comment: 'Pelanggan PPPoE Massal',
});

function openGenerateModal() {
    generateForm.reset();
    generateForm.count = 10;
    generateForm.prefix = 'cust-';
    generateForm.start_index = props.secrets.length + 1;
    generateForm.user_format = 'sequential';
    generateForm.password_mode = 'numeric';
    generateForm.service = 'pppoe';
    generateForm.profile = profileNames.value[0] ?? 'default';
    generateForm.comment = 'Pelanggan PPPoE Massal';
    showGenerateModal.value = true;
}

function submitGenerate() {
    generateForm.post(route('routers.ppp.secrets.generate', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showGenerateModal.value = false;
        },
    });
}

// Profile Modal & Form
const showProfileModal = ref(false);
const editingProfile = ref<Profile | null>(null);
const profileLocalMode = ref<string>('');
const profileRemoteMode = ref<string>('');

const profileForm = useForm({
    id: '',
    name: '',
    local_address: '',
    remote_address: '',
    bridge: '',
    rate_limit: '',
    dns_server: '',
    only_one: 'default',
    change_tcp_mss: 'default',
    use_encryption: 'default',
    address_list: '',
    parent_queue: '',
    session_timeout: '',
    idle_timeout: '',
    comment: '',
});

// Server Modal & Form
const showServerModal = ref(false);
const editingServer = ref<PppoeServer | null>(null);
const serverForm = useForm({
    id: '',
    service_name: '',
    interface: '',
    default_profile: 'default',
    max_mtu: '1480',
    max_mru: '1480',
    one_session_per_host: 'true',
});

// AAA Form
const aaaForm = useForm({
    use_radius: props.aaa?.['use-radius'] === 'yes' || props.aaa?.['use-radius'] === 'true' ? 'yes' : 'no',
    accounting: props.aaa?.accounting === 'no' || props.aaa?.accounting === 'false' ? 'no' : 'yes',
    interim_update: props.aaa?.['interim-update'] || '0s',
});

function submitAaa() {
    aaaForm.put(route('routers.ppp.aaa.update', props.router.id), {
        preserveScroll: true,
    });
}

// Profile name choices
const profileNames = computed(() => props.profiles.map((p) => p.name));

function onSecretLocalModeChange(e: Event) {
    const val = (e.target as HTMLSelectElement).value;
    secretLocalMode.value = val;
    if (val !== '__custom__') {
        secretForm.local_address = val;
    }
}

function onSecretRemoteModeChange(e: Event) {
    const val = (e.target as HTMLSelectElement).value;
    secretRemoteMode.value = val;
    if (val !== '__custom__') {
        secretForm.remote_address = val;
    }
}

function onProfileLocalModeChange(e: Event) {
    const val = (e.target as HTMLSelectElement).value;
    profileLocalMode.value = val;
    if (val !== '__custom__') {
        profileForm.local_address = val;
    }
}

function onProfileRemoteModeChange(e: Event) {
    const val = (e.target as HTMLSelectElement).value;
    profileRemoteMode.value = val;
    if (val !== '__custom__') {
        profileForm.remote_address = val;
    }
}

// ── Secret Actions ───────────────────────────────────────────────────
function openAddSecret() {
    editingSecret.value = null;
    secretForm.reset();
    secretForm.service = 'pppoe';
    secretForm.profile = profileNames.value[0] ?? 'default';
    secretLocalMode.value = '';
    secretRemoteMode.value = '';
    showSecretModal.value = true;
}

function openEditSecret(s: Secret) {
    editingSecret.value = s;
    secretForm.reset();
    secretForm.id = s['.id'];
    secretForm.name = s.name;
    secretForm.service = s.service ?? 'pppoe';
    secretForm.profile = s.profile ?? '';

    const localVal = resolvePoolName(s['local-address']);
    secretForm.local_address = localVal;
    secretLocalMode.value = isKnownPool(localVal) ? localVal : (localVal ? '__custom__' : '');

    const remoteVal = resolvePoolName(s['remote-address']);
    secretForm.remote_address = remoteVal;
    secretRemoteMode.value = isKnownPool(remoteVal) ? remoteVal : (remoteVal ? '__custom__' : '');

    secretForm.caller_id = (s as any)['caller-id'] ?? '';
    secretForm.routes = (s as any).routes ?? '';
    secretForm.comment = s.comment ?? '';
    showSecretModal.value = true;
}

function submitSecret() {
    const opts = { preserveScroll: true, onSuccess: () => (showSecretModal.value = false) };
    if (editingSecret.value) {
        secretForm.put(route('routers.ppp.secrets.update', props.router.id), opts);
    } else {
        secretForm.post(route('routers.ppp.secrets.store', props.router.id), opts);
    }
}

function toggleSecret(s: Secret) {
    inertia.post(
        route('routers.ppp.secrets.toggle', props.router.id),
        { id: s['.id'], disable: s.disabled !== 'true' },
        { preserveScroll: true },
    );
}

function removeSecret(s: Secret) {
    if (confirm(`Hapus PPP secret "${s.name}"?`)) {
        inertia.delete(route('routers.ppp.secrets.destroy', props.router.id), {
            data: { id: s['.id'] },
            preserveScroll: true,
            onSuccess: () => {
                if (selectedId.value === s['.id']) selectedId.value = null;
                selectedSecretIds.value = selectedSecretIds.value.filter((id) => id !== s['.id']);
            },
        });
    }
}

function isolateSelectedSecret(s?: Secret) {
    const target = s ?? selectedSecret.value;
    if (!target) return;
    if (confirm(`Isolir pelanggan "${target.name}"? Pelanggan akan dipindahkan ke profil isolir dan sesi koneksi aktifnya akan di-reset otomatis.`)) {
        inertia.post(
            route('routers.ppp.secrets.isolate', props.router.id),
            { id: target['.id'] },
            { preserveScroll: true },
        );
    }
}

function restoreSelectedSecret(s?: Secret) {
    const target = s ?? selectedSecret.value;
    if (!target) return;
    if (confirm(`Buka isolasi pelanggan "${target.name}"? Profil pelanggan akan dikembalikan ke profil awal.`)) {
        inertia.post(
            route('routers.ppp.secrets.restore', props.router.id),
            { id: target['.id'] },
            { preserveScroll: true },
        );
    }
}

function disconnectSession(a: ActiveSession) {
    if (confirm(`Disconnect sesi aktif untuk ${a.name}?`)) {
        inertia.post(
            route('routers.ppp.active.disconnect', props.router.id),
            { id: a['.id'] },
            { preserveScroll: true },
        );
    }
}

function disconnectAllActive() {
    if (confirm(`Disconnect seluruh (${props.active.length}) sesi aktif PPPoE/PPP saat ini? Seluruh perangkat klien akan melakukan reconnect ulang.`)) {
        inertia.post(
            route('routers.ppp.active.disconnect-all', props.router.id),
            {},
            { preserveScroll: true },
        );
    }
}

// ── Profile Actions ──────────────────────────────────────────────────
function openAddProfile() {
    editingProfile.value = null;
    profileForm.reset();
    profileForm.only_one = 'default';
    profileForm.change_tcp_mss = 'default';
    profileForm.use_encryption = 'default';
    profileForm.address_list = '';
    profileForm.parent_queue = '';
    profileForm.session_timeout = '';
    profileForm.idle_timeout = '';
    profileLocalMode.value = '';
    const defaultPool = (props.pools && props.pools.length > 0) ? (props.pools[0]?.name ?? '') : '';
    profileRemoteMode.value = defaultPool;
    profileForm.remote_address = defaultPool;
    showProfileModal.value = true;
}

function openEditProfile(p: Profile) {
    editingProfile.value = p;
    profileForm.reset();
    profileForm.id = p['.id'];
    profileForm.name = p.name;

    const localVal = resolvePoolName(p['local-address']);
    profileForm.local_address = localVal;
    profileLocalMode.value = isKnownPool(localVal) ? localVal : (localVal ? '__custom__' : '');

    const remoteVal = resolvePoolName(p['remote-address']);
    profileForm.remote_address = remoteVal;
    profileRemoteMode.value = isKnownPool(remoteVal) ? remoteVal : (remoteVal ? '__custom__' : '');

    profileForm.bridge = (p as any).bridge ?? '';
    profileForm.rate_limit = p['rate-limit'] ?? '';
    profileForm.dns_server = p['dns-server'] ?? '';
    profileForm.only_one = p['only-one'] ?? 'default';
    profileForm.change_tcp_mss = p['change-tcp-mss'] ?? 'default';
    profileForm.use_encryption = (p as any)['use-encryption'] ?? 'default';
    profileForm.address_list = (p as any)['address-list'] ?? '';
    profileForm.parent_queue = (p as any)['parent-queue'] ?? '';
    profileForm.session_timeout = (p as any)['session-timeout'] ?? '';
    profileForm.idle_timeout = (p as any)['idle-timeout'] ?? '';
    profileForm.comment = p.comment ?? '';
    showProfileModal.value = true;
}

function submitProfile() {
    const opts = { preserveScroll: true, onSuccess: () => (showProfileModal.value = false) };
    if (editingProfile.value) {
        profileForm.put(route('routers.ppp.profiles.update', props.router.id), opts);
    } else {
        profileForm.post(route('routers.ppp.profiles.store', props.router.id), opts);
    }
}

function removeProfile(p: Profile) {
    if (confirm(`Hapus PPP profile "${p.name}"?`)) {
        inertia.delete(route('routers.ppp.profiles.destroy', props.router.id), {
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
    serverForm.default_profile = profileNames.value[0] ?? 'default';
    serverForm.max_mtu = '1480';
    serverForm.max_mru = '1480';
    serverForm.one_session_per_host = 'true';
    showServerModal.value = true;
}

function openEditServer(srv: PppoeServer) {
    editingServer.value = srv;
    serverForm.reset();
    serverForm.id = srv['.id'];
    serverForm.service_name = srv['service-name'];
    serverForm.interface = srv.interface;
    serverForm.default_profile = srv['default-profile'] ?? 'default';
    serverForm.max_mtu = srv['max-mtu'] ?? '1480';
    serverForm.max_mru = srv['max-mru'] ?? '1480';
    serverForm.one_session_per_host = srv['one-session-per-host'] ?? 'true';
    showServerModal.value = true;
}

function submitServer() {
    const opts = { preserveScroll: true, onSuccess: () => (showServerModal.value = false) };
    if (editingServer.value) {
        serverForm.put(route('routers.ppp.servers.update', props.router.id), opts);
    } else {
        serverForm.post(route('routers.ppp.servers.store', props.router.id), opts);
    }
}

function toggleServer(srv: PppoeServer) {
    inertia.post(
        route('routers.ppp.servers.toggle', props.router.id),
        { id: srv['.id'], disable: srv.disabled !== 'true' },
        { preserveScroll: true },
    );
}

function removeServer(srv: PppoeServer) {
    if (confirm(`Hapus PPPoE server "${srv['service-name']}"?`)) {
        inertia.delete(route('routers.ppp.servers.destroy', props.router.id), {
            data: { id: srv['.id'] },
            preserveScroll: true,
        });
    }
}

// ── Export to CSV ────────────────────────────────────────────────────
function exportSecretsCsv() {
    const headers = ['Username', 'Service', 'Profile', 'Local Address', 'Remote Address', 'Caller ID', 'Status', 'Comment'];
    const rows = filteredSecrets.value.map((s) => {
        const status = isOnline(s.name) ? 'Online' : (isDisabled(s) ? 'Disabled' : 'Offline');
        return [
            `"${s.name}"`,
            `"${s.service || 'any'}"`,
            `"${s.profile || 'default'}"`,
            `"${s['local-address'] || ''}"`,
            `"${s['remote-address'] || ''}"`,
            `"${s['caller-id'] || ''}"`,
            `"${status}"`,
            `"${(s.comment || '').replace(/"/g, '""')}"`,
        ];
    });

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `ppp-secrets-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportActiveCsv() {
    const headers = ['Username', 'Service', 'IP Address', 'Caller ID (MAC)', 'Uptime', 'Encoding'];
    const rows = filteredActive.value.map((a) => [
        `"${a.name}"`,
        `"${a.service || 'pppoe'}"`,
        `"${a.address || ''}"`,
        `"${a['caller-id'] || ''}"`,
        `"${a.uptime || ''}"`,
        `"${a.encoding || ''}"`,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `ppp-active-${props.router.name}-${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// ── Winbox Toolbar Handler ───────────────────────────────────────────
function handleAdd() {
    if (activeTab.value === 'secrets' || activeTab.value === 'non-aktif') openAddSecret();
    else if (activeTab.value === 'profiles') openAddProfile();
    else if (activeTab.value === 'servers') openAddServer();
}

function handleSelectedEdit() {
    if (!selectedId.value) return;
    if (activeTab.value === 'secrets' || activeTab.value === 'non-aktif') {
        const item = props.secrets.find((s) => s['.id'] === selectedId.value);
        if (item) openEditSecret(item);
    } else if (activeTab.value === 'profiles') {
        const item = props.profiles.find((p) => p['.id'] === selectedId.value);
        if (item) openEditProfile(item);
    } else if (activeTab.value === 'servers') {
        const item = (props.servers ?? []).find((s) => s['.id'] === selectedId.value);
        if (item) openEditServer(item);
    }
}

function handleSelectedRemove() {
    if (!selectedId.value) return;
    if (activeTab.value === 'secrets' || activeTab.value === 'non-aktif') {
        const item = props.secrets.find((s) => s['.id'] === selectedId.value);
        if (item) removeSecret(item);
    } else if (activeTab.value === 'profiles') {
        const item = props.profiles.find((p) => p['.id'] === selectedId.value);
        if (item) removeProfile(item);
    } else if (activeTab.value === 'servers') {
        const item = (props.servers ?? []).find((s) => s['.id'] === selectedId.value);
        if (item) removeServer(item);
    }
}

function handleSelectedToggle(disable: boolean) {
    if (!selectedId.value) return;
    if (activeTab.value === 'secrets' || activeTab.value === 'non-aktif') {
        const item = props.secrets.find((s) => s['.id'] === selectedId.value);
        if (item) toggleSecret(item);
    } else if (activeTab.value === 'servers') {
        const item = (props.servers ?? []).find((s) => s['.id'] === selectedId.value);
        if (item) toggleServer(item);
    }
}

function refreshData() {
    inertia.reload({ preserveScroll: true });
}
</script>

<template>
    <Head title="PPP Management" />
    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="space-y-4">
            <!-- ── Top KPI Statistics Summary Grid ────────────────────────── -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <!-- Total Secrets -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">Total User (Secrets)</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.totalSecrets }}</span>
                        <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">Akun</span>
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
                        <span class="text-[10px] text-zinc-400">Belum konek</span>
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

                <!-- Isolated -->
                <div class="rounded-xl border border-rose-200 bg-rose-50/40 p-3.5 shadow-xs dark:border-rose-900/40 dark:bg-rose-950/20">
                    <div class="flex items-center gap-1.5 text-[11px] font-medium text-rose-700 dark:text-rose-400">
                        <span>🔒</span>
                        <span>Ter-Isolir</span>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-rose-800 dark:text-rose-200">{{ stats.isolirCount }}</span>
                        <span class="text-[10px] text-rose-600 dark:text-rose-400">Tunggakan</span>
                    </div>
                </div>

                <!-- PPPoE Servers -->
                <div class="rounded-xl border border-zinc-200 bg-white p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="text-[11px] font-medium text-zinc-500">PPPoE Servers</div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ stats.serversCount }}</span>
                        <span class="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-950 dark:text-sky-300">Server</span>
                    </div>
                </div>
            </div>

            <!-- ── WinBox-style Window Container ────────────────────────── -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Window Title & Tabs Bar -->
                <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="mb-2 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-base font-bold text-indigo-600 dark:text-indigo-400">⇋</span>
                            <h2 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">PPP Management</h2>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                                <StatusDot color="emerald" :pulse="true" />
                                {{ active.length }} Online
                            </span>
                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ secrets.length }} Total User
                            </span>
                        </div>
                    </div>

                    <!-- Tabs (Winbox style) -->
                    <nav class="-mb-px flex space-x-1 overflow-x-auto text-xs font-medium">
                        <!-- Tab: Secrets -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors whitespace-nowrap"
                            :class="activeTab === 'secrets'
                                ? 'border-indigo-600 font-semibold text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'secrets'; selectedId = null"
                        >
                            <span>Secrets (Pelanggan)</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ secrets.length }}</span>
                        </button>

                        <!-- Tab: Active -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors whitespace-nowrap"
                            :class="activeTab === 'active'
                                ? 'border-indigo-600 font-semibold text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'active'; selectedId = null"
                        >
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            <span>Active Connections</span>
                            <span class="rounded-full bg-emerald-100 px-1.5 py-0.2 text-[10px] text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">{{ active.length }}</span>
                        </button>

                        <!-- Tab: Non-Aktif -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors whitespace-nowrap"
                            :class="activeTab === 'non-aktif'
                                ? 'border-indigo-600 font-semibold text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'non-aktif'; selectedId = null"
                        >
                            <span>Non-Aktif</span>
                            <span class="rounded-full bg-amber-100 px-1.5 py-0.2 text-[10px] text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">{{ nonActiveSecrets.length }}</span>
                        </button>

                        <!-- Tab: Profiles -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors whitespace-nowrap"
                            :class="activeTab === 'profiles'
                                ? 'border-indigo-600 font-semibold text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'profiles'; selectedId = null"
                        >
                            <span>Profiles (Paket)</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ profiles.length }}</span>
                        </button>

                        <!-- Tab: PPPoE Servers -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors whitespace-nowrap"
                            :class="activeTab === 'servers'
                                ? 'border-indigo-600 font-semibold text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'servers'; selectedId = null"
                        >
                            <span>PPPoE Servers</span>
                            <span class="rounded-full bg-zinc-100 px-1.5 py-0.2 text-[10px] dark:bg-zinc-800">{{ (servers ?? []).length }}</span>
                        </button>

                        <!-- Tab: AAA / RADIUS -->
                        <button
                            type="button"
                            class="flex items-center gap-2 border-b-2 px-3.5 py-2.5 transition-colors whitespace-nowrap"
                            :class="activeTab === 'aaa'
                                ? 'border-indigo-600 font-semibold text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-600 dark:hover:text-zinc-200'"
                            @click="activeTab = 'aaa'; selectedId = null"
                        >
                            <span>AAA / RADIUS</span>
                            <span
                                class="rounded-full px-1.5 py-0.2 text-[10px]"
                                :class="aaa?.['use-radius'] === 'yes' || aaa?.['use-radius'] === 'true' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800'"
                            >
                                {{ aaa?.['use-radius'] === 'yes' || aaa?.['use-radius'] === 'true' ? 'Active' : 'Off' }}
                            </span>
                        </button>
                    </nav>
                </div>

                <!-- WinBox Action Toolbar -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
                    <!-- Action Buttons -->
                    <div class="flex flex-wrap items-center gap-1.5">
                        <Btn
                            v-if="can.manage && (activeTab === 'secrets' || activeTab === 'non-aktif' || activeTab === 'profiles' || activeTab === 'servers')"
                            size="sm"
                            variant="primary"
                            @click="handleAdd"
                        >
                            + Add
                        </Btn>

                        <!-- Generate Massal Button for Secrets -->
                        <Btn
                            v-if="can.manage && (activeTab === 'secrets' || activeTab === 'non-aktif')"
                            size="sm"
                            variant="secondary"
                            title="Generate akun PPP secret secara massal"
                            class="bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/50 dark:text-indigo-300"
                            @click="openGenerateModal"
                        >
                            ⚡ Generate Massal
                        </Btn>

                        <!-- Disconnect All Button for Active Tab -->
                        <Btn
                            v-if="can.manage && activeTab === 'active' && active.length > 0"
                            size="sm"
                            variant="danger"
                            title="Disconnect seluruh koneksi aktif"
                            @click="disconnectAllActive"
                        >
                            ⚡ Disconnect All
                        </Btn>

                        <Btn
                            v-if="can.manage && (activeTab === 'secrets' || activeTab === 'non-aktif' || activeTab === 'servers')"
                            size="sm"
                            :disabled="!selectedId"
                            title="Enable selected"
                            @click="handleSelectedToggle(false)"
                        >
                            ✓ Enable
                        </Btn>

                        <Btn
                            v-if="can.manage && (activeTab === 'secrets' || activeTab === 'non-aktif' || activeTab === 'servers')"
                            size="sm"
                            :disabled="!selectedId"
                            title="Disable selected"
                            @click="handleSelectedToggle(true)"
                        >
                            ✕ Disable
                        </Btn>

                        <Btn
                            v-if="can.manage && (activeTab === 'secrets' || activeTab === 'non-aktif') && selectedSecret && selectedSecret.profile !== 'isolir'"
                            size="sm"
                            variant="secondary"
                            :disabled="!selectedId"
                            title="Isolir pelanggan terpilih (limit kecepatan ke profil isolir & disconnect sesi)"
                            class="text-amber-700 hover:text-amber-800 dark:text-amber-400"
                            @click="isolateSelectedSecret()"
                        >
                            🔒 Isolir
                        </Btn>

                        <Btn
                            v-if="can.manage && (activeTab === 'secrets' || activeTab === 'non-aktif') && selectedSecret?.profile === 'isolir'"
                            size="sm"
                            variant="secondary"
                            :disabled="!selectedId"
                            title="Buka isolir pelanggan terpilih"
                            class="text-emerald-700 hover:text-emerald-800 dark:text-emerald-400"
                            @click="restoreSelectedSecret()"
                        >
                            🔓 Buka Isolir
                        </Btn>

                        <Btn
                            v-if="can.manage && (activeTab === 'secrets' || activeTab === 'non-aktif' || activeTab === 'profiles' || activeTab === 'servers')"
                            size="sm"
                            :disabled="!selectedId"
                            title="Edit selected"
                            @click="handleSelectedEdit"
                        >
                            ✏ Edit
                        </Btn>

                        <Btn
                            v-if="can.manage && (activeTab === 'secrets' || activeTab === 'non-aktif' || activeTab === 'profiles' || activeTab === 'servers')"
                            size="sm"
                            variant="danger"
                            :disabled="!selectedId"
                            title="Remove selected"
                            @click="handleSelectedRemove"
                        >
                            🗑 Remove
                        </Btn>

                        <!-- Export CSV Button -->
                        <Btn
                            v-if="activeTab === 'secrets' || activeTab === 'non-aktif'"
                            size="sm"
                            variant="ghost"
                            title="Export daftar pelanggan ke file CSV"
                            @click="exportSecretsCsv"
                        >
                            ⬇ CSV
                        </Btn>
                        <Btn
                            v-if="activeTab === 'active'"
                            size="sm"
                            variant="ghost"
                            title="Export sesi aktif ke file CSV"
                            @click="exportActiveCsv"
                        >
                            ⬇ CSV
                        </Btn>

                        <Btn size="sm" variant="ghost" title="Refresh data dari MikroTik" @click="refreshData">
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
                                Disabled
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2 py-1 transition-all"
                                :class="nonActiveFilter === 'offline' ? 'bg-white font-medium text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-500 hover:text-zinc-900'"
                                @click="nonActiveFilter = 'offline'"
                            >
                                Offline
                            </button>
                        </div>

                        <!-- Service filter dropdown -->
                        <select
                            v-if="activeTab === 'secrets' || activeTab === 'active' || activeTab === 'non-aktif'"
                            v-model="serviceFilter"
                            class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        >
                            <option value="all">Semua Service</option>
                            <option value="pppoe">PPPoE</option>
                            <option value="pptp">PPTP</option>
                            <option value="l2tp">L2TP</option>
                            <option value="ovpn">OVPN</option>
                            <option value="sstp">SSTP</option>
                            <option value="any">Any</option>
                        </select>

                        <!-- Profile filter dropdown -->
                        <select
                            v-if="activeTab === 'secrets' || activeTab === 'non-aktif'"
                            v-model="profileFilter"
                            class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        >
                            <option value="all">Semua Profile</option>
                            <option v-for="p in profileNames" :key="p" :value="p">{{ p }}</option>
                        </select>

                        <!-- Search Input -->
                        <div v-if="activeTab !== 'aaa'" class="relative">
                            <input
                                v-model="searchQuery"
                                type="search"
                                placeholder="Cari user / IP / komentar..."
                                class="w-44 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 pl-8 text-xs placeholder-zinc-400 transition-all focus:w-60 focus:outline-hidden focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                            <span class="pointer-events-none absolute left-2.5 top-2 text-xs text-zinc-400">⌕</span>
                        </div>
                    </div>
                </div>

                <!-- ── Multi-select Batch Actions Bar (Secrets / Non-Aktif) ─── -->
                <div
                    v-if="(activeTab === 'secrets' || activeTab === 'non-aktif') && selectedSecretIds.length > 0"
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200 bg-indigo-50/90 px-4 py-2 text-xs dark:border-indigo-900/50 dark:bg-indigo-950/60"
                >
                    <div class="flex items-center gap-2">
                        <span class="font-semibold text-indigo-900 dark:text-indigo-200">
                            {{ selectedSecretIds.length }} user dipilih
                        </span>
                        <button
                            type="button"
                            class="text-indigo-600 underline hover:text-indigo-800 dark:text-indigo-400"
                            @click="clearSecretSelection"
                        >
                            Batal Pilihan
                        </button>
                    </div>

                    <div v-if="can.manage" class="flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            class="rounded-md bg-white px-2.5 py-1 font-medium text-emerald-700 shadow-xs hover:bg-emerald-50 dark:bg-zinc-800 dark:text-emerald-400"
                            @click="submitBatchAction('enable')"
                        >
                            ✓ Aktifkan
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-white px-2.5 py-1 font-medium text-zinc-700 shadow-xs hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-300"
                            @click="submitBatchAction('disable')"
                        >
                            ✕ Nonaktifkan
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-white px-2.5 py-1 font-medium text-amber-700 shadow-xs hover:bg-amber-50 dark:bg-zinc-800 dark:text-amber-400"
                            @click="submitBatchAction('isolate')"
                        >
                            🔒 Isolir Massal
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-white px-2.5 py-1 font-medium text-emerald-700 shadow-xs hover:bg-emerald-50 dark:bg-zinc-800 dark:text-emerald-400"
                            @click="submitBatchAction('restore')"
                        >
                            🔓 Buka Isolir
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-white px-2.5 py-1 font-medium text-indigo-700 shadow-xs hover:bg-indigo-50 dark:bg-zinc-800 dark:text-indigo-400"
                            @click="showBatchProfileModal = true"
                        >
                            🏷 Ganti Profile...
                        </button>
                        <button
                            type="button"
                            class="rounded-md bg-white px-2.5 py-1 font-medium text-rose-700 shadow-xs hover:bg-rose-50 dark:bg-zinc-800 dark:text-rose-400"
                            @click="submitBatchAction('destroy')"
                        >
                            🗑 Hapus Terpilih
                        </button>
                    </div>
                </div>

                <!-- ── Tab 1: Secrets (Semua User) ───────────────────────── -->
                <div v-if="activeTab === 'secrets'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">
                                    <input
                                        type="checkbox"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                                        :checked="paginatedSecrets.length > 0 && paginatedSecrets.every((s) => selectedSecretIds.includes(s['.id']))"
                                        @change="toggleSelectAllVisible(paginatedSecrets)"
                                    />
                                </th>
                                <th class="w-8 px-2 py-2.5 text-center">#</th>
                                <th class="cursor-pointer px-4 py-2.5 hover:text-zinc-900" @click="setSort('status')">
                                    Status
                                    <span v-if="sortKey === 'status'">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 hover:text-zinc-900" @click="setSort('name')">
                                    Username (Name)
                                    <span v-if="sortKey === 'name'">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 hover:text-zinc-900" @click="setSort('service')">
                                    Service
                                    <span v-if="sortKey === 'service'">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 hover:text-zinc-900" @click="setSort('profile')">
                                    Profile
                                    <span v-if="sortKey === 'profile'">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="px-4 py-2.5">Local Address</th>
                                <th class="cursor-pointer px-4 py-2.5 hover:text-zinc-900" @click="setSort('remote-address')">
                                    Remote Address
                                    <span v-if="sortKey === 'remote-address'">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="cursor-pointer px-4 py-2.5 hover:text-zinc-900" @click="setSort('comment')">
                                    Comment
                                    <span v-if="sortKey === 'comment'">{{ sortOrder === 'asc' ? '↑' : '↓' }}</span>
                                </th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="paginatedSecrets.length === 0">
                                <td colspan="10" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada data PPP secret yang cocok.
                                </td>
                            </tr>
                            <tr
                                v-for="(s, idx) in paginatedSecrets"
                                :key="s['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="[
                                    selectedId === s['.id'] && 'bg-indigo-50/70 dark:bg-indigo-950/40',
                                    selectedSecretIds.includes(s['.id']) && 'bg-indigo-50/40 dark:bg-indigo-950/20',
                                    isDisabled(s) && 'opacity-60',
                                ]"
                                @click="selectedId = s['.id']"
                                @dblclick="openEditSecret(s)"
                            >
                                <td class="px-3 py-2.5 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                                        :checked="selectedSecretIds.includes(s['.id'])"
                                        @change="toggleSecretSelection(s['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2.5 text-center font-mono text-zinc-400">
                                    {{ (currentPage - 1) * perPage + idx + 1 }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <span v-if="isOnline(s.name)" class="inline-flex items-center gap-1.5 font-medium text-emerald-600 dark:text-emerald-400">
                                        <StatusDot color="emerald" :pulse="true" /> Online
                                    </span>
                                    <span v-else-if="isDisabled(s)" class="inline-flex items-center gap-1.5 text-zinc-400">
                                        <StatusDot color="zinc" /> Disabled
                                    </span>
                                    <span v-else class="inline-flex items-center gap-1.5 text-zinc-500">
                                        <StatusDot color="amber" /> Offline
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ s.name }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ s.service || 'any' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span v-if="s.profile === 'isolir'" class="inline-flex items-center gap-1 rounded bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-700 dark:bg-rose-950/60 dark:text-rose-300">
                                        🔒 ISOLIR
                                    </span>
                                    <span v-else class="text-zinc-600 dark:text-zinc-400">
                                        {{ s.profile || 'default' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    <span v-if="getPool(s['local-address'])" class="inline-flex items-center gap-1.5 rounded bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-950/50 dark:text-sky-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                                        {{ formatAddressOrPool(s['local-address']) }}
                                    </span>
                                    <span v-else>{{ s['local-address'] || '—' }}</span>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    <span v-if="getPool(s['remote-address'])" class="inline-flex items-center gap-1.5 rounded bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-950/50 dark:text-sky-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                                        {{ formatAddressOrPool(s['remote-address']) }}
                                    </span>
                                    <span v-else>{{ s['remote-address'] || '—' }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500 italic">
                                    {{ s.comment || '' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end gap-1">
                                        <!-- Quick Password Button -->
                                        <Btn
                                            size="sm"
                                            variant="ghost"
                                            title="Ubah password secret ini secara cepat"
                                            @click="openChangePassword(s)"
                                        >
                                            🔑
                                        </Btn>

                                        <Btn
                                            v-if="s.profile === 'isolir'"
                                            size="sm"
                                            variant="ghost"
                                            class="text-emerald-600 hover:text-emerald-700 dark:text-emerald-400"
                                            title="Buka isolir pelanggan"
                                            @click="restoreSelectedSecret(s)"
                                        >
                                            Buka
                                        </Btn>
                                        <Btn
                                            v-else
                                            size="sm"
                                            variant="ghost"
                                            class="text-amber-600 hover:text-amber-700 dark:text-amber-400"
                                            title="Isolir pelanggan"
                                            @click="isolateSelectedSecret(s)"
                                        >
                                            Isolir
                                        </Btn>

                                        <Btn size="sm" @click="toggleSecret(s)">
                                            {{ isDisabled(s) ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" @click="openEditSecret(s)">Edit</Btn>
                                        <Btn size="sm" variant="danger" @click="removeSecret(s)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Pagination Controls -->
                    <div class="flex flex-wrap items-center justify-between border-t border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                            <span>Baris per halaman:</span>
                            <select
                                v-model.number="perPage"
                                class="rounded border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option :value="15">15</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="9999">Semua</option>
                            </select>
                            <span>(Total {{ sortedSecrets.length }} user)</span>
                        </div>

                        <div v-if="totalPages > 1" class="flex items-center gap-1 text-xs">
                            <button
                                type="button"
                                :disabled="currentPage <= 1"
                                class="rounded border border-zinc-200 px-2.5 py-1 text-zinc-600 disabled:opacity-40 dark:border-zinc-700 dark:text-zinc-400"
                                @click="currentPage--"
                            >
                                « Prev
                            </button>
                            <span class="px-2 text-zinc-600 dark:text-zinc-400">
                                Halaman {{ currentPage }} dari {{ totalPages }}
                            </span>
                            <button
                                type="button"
                                :disabled="currentPage >= totalPages"
                                class="rounded border border-zinc-200 px-2.5 py-1 text-zinc-600 disabled:opacity-40 dark:border-zinc-700 dark:text-zinc-400"
                                @click="currentPage++"
                            >
                                Next »
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Tab 2: Active Connections ─────────────────────────── -->
                <div v-if="activeTab === 'active'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">Status</th>
                                <th class="px-4 py-2.5">User</th>
                                <th class="px-4 py-2.5">Service</th>
                                <th class="px-4 py-2.5">IP Address</th>
                                <th class="px-4 py-2.5">Caller ID (MAC)</th>
                                <th class="px-4 py-2.5">Uptime</th>
                                <th class="px-4 py-2.5 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredActive.length === 0">
                                <td colspan="8" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada session aktif saat ini.
                                </td>
                            </tr>
                            <tr
                                v-for="(a, idx) in filteredActive"
                                :key="a['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="selectedId === a['.id'] && 'bg-indigo-50/70 dark:bg-indigo-950/40'"
                                @click="selectedId = a['.id']"
                            >
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex items-center gap-1.5 font-medium text-emerald-600 dark:text-emerald-400">
                                        <StatusDot color="emerald" :pulse="true" /> Online
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ a.name }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-[11px] text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                        {{ a.service || 'pppoe' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-mono font-medium text-zinc-800 dark:text-zinc-200">
                                    {{ a.address || '—' }}
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    {{ a['caller-id'] || '—' }}
                                </td>
                                <td class="px-4 py-2.5 tabular-nums text-zinc-600 dark:text-zinc-400">
                                    {{ a.uptime || '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end gap-1.5">
                                        <Btn size="sm" variant="danger" @click="disconnectSession(a)">
                                            Disconnect
                                        </Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── Tab 3: Non-Aktif (Offline / Disabled) ──────────────── -->
                <div v-if="activeTab === 'non-aktif'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">
                                    <input
                                        type="checkbox"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                                        :checked="nonActiveSecrets.length > 0 && nonActiveSecrets.every((s) => selectedSecretIds.includes(s['.id']))"
                                        @change="toggleSelectAllVisible(nonActiveSecrets)"
                                    />
                                </th>
                                <th class="w-8 px-2 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">Status</th>
                                <th class="px-4 py-2.5">User (Name)</th>
                                <th class="px-4 py-2.5">Service</th>
                                <th class="px-4 py-2.5">Profile</th>
                                <th class="px-4 py-2.5">Remote Address</th>
                                <th class="px-4 py-2.5">Comment</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="nonActiveSecrets.length === 0">
                                <td colspan="9" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada user non-aktif / offline pada filter ini.
                                </td>
                            </tr>
                            <tr
                                v-for="(s, idx) in nonActiveSecrets"
                                :key="s['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="[
                                    selectedId === s['.id'] && 'bg-indigo-50/70 dark:bg-indigo-950/40',
                                    selectedSecretIds.includes(s['.id']) && 'bg-indigo-50/40 dark:bg-indigo-950/20',
                                    isDisabled(s) && 'opacity-60',
                                ]"
                                @click="selectedId = s['.id']"
                                @dblclick="openEditSecret(s)"
                            >
                                <td class="px-3 py-2.5 text-center" @click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                                        :checked="selectedSecretIds.includes(s['.id'])"
                                        @change="toggleSecretSelection(s['.id'])"
                                    />
                                </td>
                                <td class="px-2 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5">
                                    <span v-if="isDisabled(s)" class="inline-flex items-center gap-1.5 rounded bg-zinc-100 px-2 py-0.5 font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        <StatusDot color="zinc" /> Disabled
                                    </span>
                                    <span v-else class="inline-flex items-center gap-1.5 rounded bg-amber-50 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">
                                        <StatusDot color="amber" /> Offline
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ s.name }}
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ s.service || 'any' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span v-if="s.profile === 'isolir'" class="inline-flex items-center gap-1 rounded bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-700 dark:bg-rose-950/60 dark:text-rose-300">
                                        🔒 ISOLIR
                                    </span>
                                    <span v-else class="text-zinc-600 dark:text-zinc-400">
                                        {{ s.profile || 'default' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    <span v-if="getPool(s['remote-address'])" class="inline-flex items-center gap-1.5 rounded bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-950/50 dark:text-sky-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                                        {{ formatAddressOrPool(s['remote-address']) }}
                                    </span>
                                    <span v-else>{{ s['remote-address'] || '—' }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500 italic">
                                    {{ s.comment || '' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end gap-1">
                                        <Btn
                                            v-if="s.profile === 'isolir'"
                                            size="sm"
                                            variant="ghost"
                                            class="text-emerald-600 hover:text-emerald-700 dark:text-emerald-400"
                                            title="Buka isolir pelanggan"
                                            @click="restoreSelectedSecret(s)"
                                        >
                                            Buka
                                        </Btn>
                                        <Btn
                                            v-else
                                            size="sm"
                                            variant="ghost"
                                            class="text-amber-600 hover:text-amber-700 dark:text-amber-400"
                                            title="Isolir pelanggan"
                                            @click="isolateSelectedSecret(s)"
                                        >
                                            Isolir
                                        </Btn>
                                        <Btn size="sm" @click="toggleSecret(s)">
                                            {{ isDisabled(s) ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" @click="openEditSecret(s)">Edit</Btn>
                                        <Btn size="sm" variant="danger" @click="removeSecret(s)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── Tab 4: Profiles ───────────────────────────────────── -->
                <div v-if="activeTab === 'profiles'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">Profile Name</th>
                                <th class="px-4 py-2.5">Local Address</th>
                                <th class="px-4 py-2.5">Remote Address (Pool)</th>
                                <th class="px-4 py-2.5">Rate Limit (rx/tx)</th>
                                <th class="px-4 py-2.5">Address List</th>
                                <th class="px-4 py-2.5">Parent Queue</th>
                                <th class="px-4 py-2.5">DNS Server</th>
                                <th class="px-4 py-2.5">Only One</th>
                                <th class="px-4 py-2.5">Comment</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredProfiles.length === 0">
                                <td colspan="11" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada PPP profile.
                                </td>
                            </tr>
                            <tr
                                v-for="(p, idx) in filteredProfiles"
                                :key="p['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="selectedId === p['.id'] && 'bg-indigo-50/70 dark:bg-indigo-950/40'"
                                @click="selectedId = p['.id']"
                                @dblclick="openEditProfile(p)"
                            >
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ p.name }}
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">
                                    <span v-if="getPool(p['local-address'])" class="inline-flex items-center gap-1.5 rounded bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-950/50 dark:text-sky-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                                        {{ formatAddressOrPool(p['local-address']) }}
                                    </span>
                                    <span v-else>{{ p['local-address'] || '—' }}</span>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">
                                    <span v-if="getPool(p['remote-address'])" class="inline-flex items-center gap-1.5 rounded bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:bg-sky-950/50 dark:text-sky-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                                        {{ formatAddressOrPool(p['remote-address']) }}
                                    </span>
                                    <span v-else>{{ p['remote-address'] || '—' }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span v-if="p['rate-limit']" class="rounded bg-indigo-50 px-2 py-0.5 font-mono text-[11px] font-semibold text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300">
                                        {{ p['rate-limit'] }}
                                    </span>
                                    <span v-else class="text-zinc-400">unlimited</span>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">
                                    <span v-if="p['address-list']" class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                                        {{ p['address-list'] }}
                                    </span>
                                    <span v-else class="text-zinc-400">—</span>
                                </td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">
                                    {{ p['parent-queue'] || '—' }}
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    {{ p['dns-server'] || '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500">
                                    {{ p['only-one'] || 'default' }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500 italic">
                                    {{ p.comment || '' }}
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

                <!-- ── Tab 5: PPPoE Servers ──────────────────────────────── -->
                <div v-if="activeTab === 'servers'" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-200 bg-zinc-50 font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <tr>
                                <th class="w-8 px-3 py-2.5 text-center">#</th>
                                <th class="px-4 py-2.5">State</th>
                                <th class="px-4 py-2.5">Service Name</th>
                                <th class="px-4 py-2.5">Interface</th>
                                <th class="px-4 py-2.5">Default Profile</th>
                                <th class="px-4 py-2.5">Max MTU / MRU</th>
                                <th class="px-4 py-2.5">One Session/Host</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="filteredServers.length === 0">
                                <td colspan="8" class="px-4 py-10 text-center text-zinc-500">
                                    Tidak ada PPPoE server configured.
                                </td>
                            </tr>
                            <tr
                                v-for="(srv, idx) in filteredServers"
                                :key="srv['.id']"
                                class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                :class="[
                                    selectedId === srv['.id'] && 'bg-indigo-50/70 dark:bg-indigo-950/40',
                                    srv.disabled === 'true' && 'opacity-60',
                                ]"
                                @click="selectedId = srv['.id']"
                                @dblclick="openEditServer(srv)"
                            >
                                <td class="px-3 py-2.5 text-center font-mono text-zinc-400">{{ idx + 1 }}</td>
                                <td class="px-4 py-2.5">
                                    <StatusDot
                                        :color="srv.disabled === 'true' ? 'zinc' : 'emerald'"
                                        :label="srv.disabled === 'true' ? 'disabled' : 'running'"
                                    />
                                </td>
                                <td class="px-4 py-2.5 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ srv['service-name'] }}
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-600 dark:text-zinc-400">
                                    {{ srv.interface }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">
                                    {{ srv['default-profile'] || 'default' }}
                                </td>
                                <td class="px-4 py-2.5 font-mono text-zinc-500">
                                    {{ srv['max-mtu'] || 1480 }} / {{ srv['max-mru'] || 1480 }}
                                </td>
                                <td class="px-4 py-2.5 text-zinc-500">
                                    {{ srv['one-session-per-host'] === 'true' ? 'yes' : 'no' }}
                                </td>
                                <td class="px-4 py-2.5 text-right" @click.stop>
                                    <div v-if="can.manage" class="flex justify-end gap-1">
                                        <Btn size="sm" @click="toggleServer(srv)">
                                            {{ srv.disabled === 'true' ? 'Enable' : 'Disable' }}
                                        </Btn>
                                        <Btn size="sm" variant="ghost" @click="openEditServer(srv)">Edit</Btn>
                                        <Btn size="sm" variant="danger" @click="removeServer(srv)">Del</Btn>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── Tab 6: AAA / RADIUS ───────────────────────────────── -->
                <div v-if="activeTab === 'aaa'" class="p-6">
                    <div class="mx-auto max-w-2xl">
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-800 dark:bg-zinc-800/30">
                            <div class="mb-4 flex items-center justify-between border-b border-zinc-200 pb-3 dark:border-zinc-800">
                                <div>
                                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                        PPP Authentication & Accounting (AAA)
                                    </h3>
                                    <p class="text-xs text-zinc-500">
                                        Konfigurasi autentikasi dan pencatatan penggunaan kuota/durasi user PPP via RADIUS Server.
                                    </p>
                                </div>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="aaaForm.use_radius === 'yes' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-zinc-200 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300'"
                                >
                                    <StatusDot :color="aaaForm.use_radius === 'yes' ? 'emerald' : 'zinc'" />
                                    RADIUS {{ aaaForm.use_radius === 'yes' ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </div>

                            <form class="space-y-4 text-xs" @submit.prevent="submitAaa">
                                <!-- Use RADIUS -->
                                <div class="grid grid-cols-1 gap-1 sm:grid-cols-3 sm:items-center">
                                    <label class="font-medium text-zinc-700 dark:text-zinc-300">
                                        Use RADIUS
                                    </label>
                                    <div class="sm:col-span-2">
                                        <select
                                            v-model="aaaForm.use_radius"
                                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                                            :disabled="!can.manage"
                                        >
                                            <option value="yes">yes (Gunakan RADIUS untuk autentikasi user PPP)</option>
                                            <option value="no">no (Hanya database Secret lokal MikroTik)</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Accounting -->
                                <div class="grid grid-cols-1 gap-1 sm:grid-cols-3 sm:items-center">
                                    <label class="font-medium text-zinc-700 dark:text-zinc-300">
                                        Accounting
                                    </label>
                                    <div class="sm:col-span-2">
                                        <select
                                            v-model="aaaForm.accounting"
                                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                                            :disabled="!can.manage"
                                        >
                                            <option value="yes">yes (Kirim data sesi & trafik ke RADIUS)</option>
                                            <option value="no">no (Nonaktifkan RADIUS accounting)</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Interim Update -->
                                <div class="grid grid-cols-1 gap-1 sm:grid-cols-3 sm:items-center">
                                    <div>
                                        <label class="font-medium text-zinc-700 dark:text-zinc-300">
                                            Interim Update
                                        </label>
                                        <p class="text-[11px] text-zinc-400">
                                            Interval update periodik trafik ke RADIUS (e.g. 5m, 10m, atau 0s)
                                        </p>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <input
                                            v-model="aaaForm.interim_update"
                                            placeholder="contoh: 5m atau 0s"
                                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800"
                                            :disabled="!can.manage"
                                        />
                                    </div>
                                </div>

                                <div v-if="can.manage" class="flex justify-end pt-3 border-t border-zinc-200 dark:border-zinc-700">
                                    <Btn type="submit" variant="primary" :disabled="aaaForm.processing">
                                        Simpan Pengaturan AAA
                                    </Btn>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Window Status Bar (Winbox-style footer) -->
                <div class="flex items-center justify-between border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-[11px] text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="flex items-center gap-3">
                        <span>
                            <strong class="text-zinc-700 dark:text-zinc-300">
                                {{ activeTab === 'secrets' ? filteredSecrets.length : activeTab === 'active' ? filteredActive.length : activeTab === 'non-aktif' ? nonActiveSecrets.length : activeTab === 'profiles' ? filteredProfiles.length : activeTab === 'servers' ? filteredServers.length : 1 }}
                            </strong> items
                        </span>
                        <span v-if="selectedId" class="text-indigo-600 dark:text-indigo-400">
                            • 1 item selected ({{ selectedId }})
                        </span>
                        <span v-if="selectedSecretIds.length > 0" class="text-emerald-600 dark:text-emerald-400">
                            • {{ selectedSecretIds.length }} items checklist
                        </span>
                    </div>
                    <div class="text-zinc-400">
                        Double-click baris untuk edit • Klik checkbox untuk aksi massal
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Modal: Add / Edit Secret ───────────────────────────────── -->
        <Modal
            :open="showSecretModal"
            :title="editingSecret ? `Edit PPP Secret: ${editingSecret.name}` : 'Add PPP Secret'"
            @close="showSecretModal = false"
        >
            <form class="grid grid-cols-2 gap-4 text-xs" @submit.prevent="submitSecret">
                <div class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Username (Name)</label>
                    <input
                        v-model="secretForm.name"
                        placeholder="e.g. client1"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                    <p v-if="secretForm.errors.name" class="mt-1 text-red-500">{{ secretForm.errors.name }}</p>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">
                        Password
                        <span v-if="editingSecret" class="text-zinc-400">(leave blank to keep)</span>
                    </label>
                    <input
                        v-model="secretForm.password"
                        type="password"
                        autocomplete="new-password"
                        placeholder="••••••••"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        :required="!editingSecret"
                    />
                    <p v-if="secretForm.errors.password" class="mt-1 text-red-500">{{ secretForm.errors.password }}</p>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Service</label>
                    <select
                        v-model="secretForm.service"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option v-for="s in ['any', 'pppoe', 'pptp', 'l2tp', 'ovpn', 'sstp']" :key="s" :value="s">
                            {{ s }}
                        </option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Profile</label>
                    <select
                        v-model="secretForm.profile"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="">(default)</option>
                        <option v-for="p in profileNames" :key="p" :value="p">{{ p }}</option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">
                        Local Address (Gateway / Pool)
                    </label>
                    <div class="space-y-1.5">
                        <select
                            :value="secretLocalMode"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800"
                            @change="onSecretLocalModeChange"
                        >
                            <option value="">(None / Kosong)</option>
                            <optgroup v-if="(pools ?? []).length > 0" label="Pilihan IP Pool (MikroTik)">
                                <option v-for="p in (pools ?? [])" :key="p['.id']" :value="p.name">
                                    Pool: {{ p.name }} ({{ p.ranges || 'range' }})
                                </option>
                            </optgroup>
                            <option value="__custom__">-- Ketik IP Manual --</option>
                        </select>
                        <input
                            v-if="secretLocalMode === '__custom__'"
                            v-model="secretForm.local_address"
                            placeholder="e.g. 10.0.0.1"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                        <div v-if="secretForm.local_address" class="text-[11px] text-zinc-500">
                            Aktif: <span class="font-mono font-medium text-zinc-800 dark:text-zinc-200">{{ formatAddressOrPool(secretForm.local_address) }}</span>
                        </div>
                    </div>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">
                        Remote Address (Client IP / Pool)
                    </label>
                    <div class="space-y-1.5">
                        <select
                            :value="secretRemoteMode"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800"
                            @change="onSecretRemoteModeChange"
                        >
                            <option value="">(None / Kosong)</option>
                            <optgroup v-if="(pools ?? []).length > 0" label="Pilihan IP Pool (MikroTik)">
                                <option v-for="p in (pools ?? [])" :key="p['.id']" :value="p.name">
                                    Pool: {{ p.name }} ({{ p.ranges || 'range' }})
                                </option>
                            </optgroup>
                            <option value="__custom__">-- Ketik IP Manual --</option>
                        </select>
                        <input
                            v-if="secretRemoteMode === '__custom__'"
                            v-model="secretForm.remote_address"
                            placeholder="e.g. 10.0.0.50"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                        <div v-if="secretForm.remote_address" class="text-[11px] text-zinc-500">
                            Aktif: <span class="font-mono font-medium text-zinc-800 dark:text-zinc-200">{{ formatAddressOrPool(secretForm.remote_address) }}</span>
                        </div>
                    </div>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Caller ID (MAC/IP Restriction)</label>
                    <input
                        v-model="secretForm.caller_id"
                        placeholder="e.g. DC:2C:6E:AA:BB:CC (optional)"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Routes (Static Route)</label>
                    <input
                        v-model="secretForm.routes"
                        placeholder="e.g. 192.168.100.0/24 10.0.0.50 1 (optional)"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="secretForm.comment"
                        placeholder="e.g. Pelanggan A - Paket 20M"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 mt-2 flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showSecretModal = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="secretForm.processing">
                        {{ editingSecret ? 'Save Changes' : 'Create Secret' }}
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Quick Change Password ───────────────────────────── -->
        <Modal
            :open="showPasswordModal"
            :title="`Ubah Password: ${targetSecretForPassword?.name}`"
            @close="showPasswordModal = false"
        >
            <form class="space-y-4 text-xs" @submit.prevent="submitChangePassword">
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">
                        Password Baru untuk {{ targetSecretForPassword?.name }}
                    </label>
                    <input
                        v-model="passwordForm.password"
                        type="text"
                        placeholder="Ketik password baru..."
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                    <p v-if="passwordForm.errors.password" class="mt-1 text-red-500">{{ passwordForm.errors.password }}</p>
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showPasswordModal = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="passwordForm.processing">
                        Simpan Password
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Batch Change Profile ────────────────────────────── -->
        <Modal
            :open="showBatchProfileModal"
            title="Ganti Profile Massal"
            @close="showBatchProfileModal = false"
        >
            <div class="space-y-4 text-xs">
                <p class="text-zinc-600 dark:text-zinc-400">
                    Pilih profile baru yang akan diterapkan ke <strong>{{ selectedSecretIds.length }} user terpilih</strong>:
                </p>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Target Profile</label>
                    <select
                        v-model="batchTargetProfile"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option v-for="p in profileNames" :key="p" :value="p">{{ p }}</option>
                    </select>
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showBatchProfileModal = false">Cancel</Btn>
                    <Btn variant="primary" @click="submitBatchAction('change_profile')">
                        Terapkan Profile
                    </Btn>
                </div>
            </div>
        </Modal>

        <!-- ── Modal: Batch Generate Secrets ──────────────────────────── -->
        <Modal
            :open="showGenerateModal"
            title="Generate Akun PPP Secret Massal"
            @close="showGenerateModal = false"
        >
            <form class="grid grid-cols-2 gap-4 text-xs" @submit.prevent="submitGenerate">
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Jumlah Akun (Qty)</label>
                    <input
                        v-model.number="generateForm.count"
                        type="number"
                        min="1"
                        max="100"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Prefix Username</label>
                    <input
                        v-model="generateForm.prefix"
                        placeholder="e.g. cust-"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Format Username</label>
                    <select
                        v-model="generateForm.user_format"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="sequential">Angka Urut (e.g. cust-001, cust-002)</option>
                        <option value="random">Karakter Acak (e.g. cust-7f8a)</option>
                    </select>
                </div>

                <div v-if="generateForm.user_format === 'sequential'">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Mulai dari Nomor</label>
                    <input
                        v-model.number="generateForm.start_index"
                        type="number"
                        min="1"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div :class="generateForm.user_format === 'random' ? 'col-span-2' : ''">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Mode Password</label>
                    <select
                        v-model="generateForm.password_mode"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="numeric">Angka Acak 6-Digit (PIN)</option>
                        <option value="alphanumeric">Karakter Acak 8-Digit</option>
                        <option value="same">Sama dengan Username</option>
                        <option value="custom">Password Statis Tertentu</option>
                    </select>
                </div>

                <div v-if="generateForm.password_mode === 'custom'" class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Password Statis</label>
                    <input
                        v-model="generateForm.custom_password"
                        placeholder="Password yang sama untuk semua user..."
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Service</label>
                    <select
                        v-model="generateForm.service"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option v-for="s in ['pppoe', 'any', 'pptp', 'l2tp', 'ovpn', 'sstp']" :key="s" :value="s">
                            {{ s }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Profile</label>
                    <select
                        v-model="generateForm.profile"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="">(default)</option>
                        <option v-for="p in profileNames" :key="p" :value="p">{{ p }}</option>
                    </select>
                </div>

                <div class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Komentar</label>
                    <input
                        v-model="generateForm.comment"
                        placeholder="e.g. Cluster Mawar - Batch 1"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 mt-2 flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showGenerateModal = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="generateForm.processing">
                        Generate {{ generateForm.count }} Akun
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Add / Edit Profile ──────────────────────────────── -->
        <Modal
            :open="showProfileModal"
            :title="editingProfile ? `Edit PPP Profile: ${editingProfile.name}` : 'Add PPP Profile'"
            @close="showProfileModal = false"
        >
            <form class="grid grid-cols-2 gap-4 text-xs" @submit.prevent="submitProfile">
                <div class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Profile Name</label>
                    <input
                        v-model="profileForm.name"
                        placeholder="e.g. Paket-20Mbps"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                    <p v-if="profileForm.errors.name" class="mt-1 text-red-500">{{ profileForm.errors.name }}</p>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">
                        Local Address (Gateway / Pool)
                    </label>
                    <div class="space-y-1.5">
                        <select
                            :value="profileLocalMode"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800"
                            @change="onProfileLocalModeChange"
                        >
                            <option value="">(None / Kosong)</option>
                            <optgroup v-if="(pools ?? []).length > 0" label="Pilihan IP Pool (MikroTik)">
                                <option v-for="p in (pools ?? [])" :key="p['.id']" :value="p.name">
                                    Pool: {{ p.name }} ({{ p.ranges || 'range' }})
                                </option>
                            </optgroup>
                            <option value="__custom__">-- Ketik IP Manual --</option>
                        </select>
                        <input
                            v-if="profileLocalMode === '__custom__'"
                            v-model="profileForm.local_address"
                            placeholder="e.g. 10.0.0.1"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                        <div v-if="profileForm.local_address" class="text-[11px] text-zinc-500">
                            Aktif: <span class="font-mono font-medium text-zinc-800 dark:text-zinc-200">{{ formatAddressOrPool(profileForm.local_address) }}</span>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">
                        Remote Address (IP Pool / Client IP)
                    </label>
                    <div class="space-y-1.5">
                        <select
                            :value="profileRemoteMode"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800"
                            @change="onProfileRemoteModeChange"
                        >
                            <option value="">(None / Kosong)</option>
                            <optgroup v-if="(pools ?? []).length > 0" label="Pilihan IP Pool (MikroTik)">
                                <option v-for="p in (pools ?? [])" :key="p['.id']" :value="p.name">
                                    Pool: {{ p.name }} ({{ p.ranges || 'range' }})
                                </option>
                            </optgroup>
                            <option value="__custom__">-- Ketik IP Manual --</option>
                        </select>
                        <input
                            v-if="profileRemoteMode === '__custom__'"
                            v-model="profileForm.remote_address"
                            placeholder="e.g. 10.0.0.50 atau pool kustom"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800"
                        />
                        <div v-if="profileForm.remote_address" class="text-[11px] text-zinc-500">
                            Aktif: <span class="font-mono font-medium text-zinc-800 dark:text-zinc-200">{{ formatAddressOrPool(profileForm.remote_address) }}</span>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Bridge Interface</label>
                    <select
                        v-model="profileForm.bridge"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="">(None / Default)</option>
                        <option v-for="i in (interfaces ?? [])" :key="i" :value="i">{{ i }}</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Rate Limit (rx/tx)</label>
                    <input
                        v-model="profileForm.rate_limit"
                        placeholder="e.g. 10M/20M or 2M/5M"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Address List (Firewall)</label>
                    <input
                        v-model="profileForm.address_list"
                        placeholder="e.g. isolir-list / pelanggan-aktif"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Parent Queue</label>
                    <input
                        v-model="profileForm.parent_queue"
                        placeholder="e.g. total-bandwidth"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">DNS Server</label>
                    <input
                        v-model="profileForm.dns_server"
                        placeholder="8.8.8.8,1.1.1.1"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Only One Session</label>
                    <select
                        v-model="profileForm.only_one"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="default">default</option>
                        <option value="yes">yes</option>
                        <option value="no">no</option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Change TCP MSS</label>
                    <select
                        v-model="profileForm.change_tcp_mss"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="default">default</option>
                        <option value="yes">yes</option>
                        <option value="no">no</option>
                    </select>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Use Encryption</label>
                    <select
                        v-model="profileForm.use_encryption"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="default">default</option>
                        <option value="yes">yes</option>
                        <option value="no">no</option>
                        <option value="required">required</option>
                    </select>
                </div>

                <div class="col-span-2">
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="profileForm.comment"
                        placeholder="Deskripsi profile..."
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div class="col-span-2 mt-2 flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="ghost" @click="showProfileModal = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="profileForm.processing">
                        {{ editingProfile ? 'Save Profile' : 'Create Profile' }}
                    </Btn>
                </div>
            </form>
        </Modal>

        <!-- ── Modal: Add / Edit PPPoE Server ────────────────────────── -->
        <Modal
            :open="showServerModal"
            :title="editingServer ? `Edit PPPoE Server: ${editingServer['service-name']}` : 'Add PPPoE Server'"
            @close="showServerModal = false"
        >
            <form class="grid grid-cols-2 gap-4 text-xs" @submit.prevent="submitServer">
                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Service Name</label>
                    <input
                        v-model="serverForm.service_name"
                        placeholder="pppoe-in1"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    />
                    <p v-if="serverForm.errors.service_name" class="mt-1 text-red-500">{{ serverForm.errors.service_name }}</p>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Interface</label>
                    <select
                        v-model="serverForm.interface"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        required
                    >
                        <option value="">Pilih Interface</option>
                        <option v-for="i in (interfaces ?? [])" :key="i" :value="i">{{ i }}</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Default Profile</label>
                    <select
                        v-model="serverForm.default_profile"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option v-for="p in profileNames" :key="p" :value="p">{{ p }}</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">One Session Per Host</label>
                    <select
                        v-model="serverForm.one_session_per_host"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="true">yes</option>
                        <option value="false">no</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Max MTU</label>
                    <input
                        v-model="serverForm.max_mtu"
                        placeholder="1480"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>

                <div>
                    <label class="mb-1 block font-medium text-zinc-700 dark:text-zinc-300">Max MRU</label>
                    <input
                        v-model="serverForm.max_mru"
                        placeholder="1480"
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
    </RouterWorkspace>
</template>
