<script setup lang="ts">
import { Head, Link, router as inertia } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';
import TrafficChart from '@/components/TrafficChart.vue';

interface Counts {
    total: number;
    online: number;
    offline: number;
    unreachable: number;
    unknown: number;
}

interface RouterSummary {
    id: number;
    name: string;
    host: string;
    status: string;
    status_label: string;
    status_color: string;
    routeros_version: string | null;
    last_seen_at: string | null;
}

interface InterfaceSummary {
    name: string;
    type: string;
    running: boolean;
    disabled: boolean;
    comment: string | null;
}

interface LiveStats {
    device_error: string | null;
    ppp: {
        active: number;
        secrets: number;
        disabled_secrets: number;
        profiles: number;
        error?: string;
    } | null;
    hotspot: {
        active: number;
        users: number;
        disabled_users: number;
        hosts: number;
        error?: string;
    } | null;
    dhcp: {
        total: number;
        bound: number;
        static: number;
        error?: string;
    } | null;
    system: {
        cpu_load: number;
        cpu_count: number;
        uptime: string;
        version: string;
        board_name: string;
        memory_used_pct: number;
        memory_free_human: string;
        memory_total_human: string;
        hdd_used_pct: number;
        error?: string;
    } | null;
    queues: {
        total: number;
        active: number;
        error?: string;
    } | null;
}

interface WidgetLayoutItem {
    id: string;
    span: number; // 4 (1/3), 6 (1/2), 12 (full)
}

const props = defineProps<{
    counts: Counts;
    recentRouters: RouterSummary[];
    routers: RouterSummary[];
    selectedRouterId: number | null;
    selectedRouterName: string | null;
    enabledWidgets: string[];
    liveStats: LiveStats;
    interfaces: InterfaceSummary[];
}>();

const COOKIE_KEY = 'mikweb_dashboard_widgets';
const LAYOUT_STORAGE_KEY = 'mikweb_dashboard_layout_v2';

const DEFAULT_WIDGETS = ['fleet', 'traffic', 'ppp', 'hotspot', 'dhcp', 'system', 'recent_routers'];

const DEFAULT_LAYOUT: WidgetLayoutItem[] = [
    { id: 'traffic', span: 12 },
    { id: 'fleet', span: 12 },
    { id: 'ppp', span: 6 },
    { id: 'hotspot', span: 6 },
    { id: 'dhcp', span: 6 },
    { id: 'system', span: 6 },
    { id: 'queues', span: 6 },
    { id: 'recent_routers', span: 12 },
];

const activeRouterId = ref<number | null>(props.selectedRouterId);
const isCustomizing = ref(false);
const isEditMode = ref(false);
const activeWidgets = ref<string[]>(props.enabledWidgets?.length ? [...props.enabledWidgets] : [...DEFAULT_WIDGETS]);
const layout = ref<WidgetLayoutItem[]>([...DEFAULT_LAYOUT]);

const draggedWidgetIndex = ref<number | null>(null);
const dragOverIndex = ref<number | null>(null);

const availableWidgets = [
    {
        key: 'fleet',
        name: 'Ringkasan Fleet Router',
        desc: 'Statistik total router, online, offline & unreachable (beban 0 ke MikroTik).',
        category: 'Umum',
    },
    {
        key: 'traffic',
        name: 'Monitor Trafik Real-time',
        desc: 'Grafik bandwidth RX/TX live per interface dengan ECharts.',
        category: 'Jaringan',
    },
    {
        key: 'ppp',
        name: 'PPPoE & PPP Clients',
        desc: 'Total sesi PPPoE aktif, database secrets, dan profil bandwidth.',
        category: 'ISP / Akses',
    },
    {
        key: 'hotspot',
        name: 'Hotspot & Voucher',
        desc: 'Sesi hotspot aktif, voucher user terdaftar, dan host tersambung.',
        category: 'ISP / Akses',
    },
    {
        key: 'dhcp',
        name: 'DHCP Server Leases',
        desc: 'Total leases terikat (bound), static binding, dan pool alokasi.',
        category: 'IP & Jaringan',
    },
    {
        key: 'system',
        name: 'Kesehatan Sistem Router',
        desc: 'CPU load, penggunaan RAM & Storage, tipe board, dan uptime.',
        category: 'Perangkat',
    },
    {
        key: 'queues',
        name: 'Simple Queues Bandwidth',
        desc: 'Total dan status limitasi Simple Queue pada router.',
        category: 'Manajemen Trafik',
    },
    {
        key: 'recent_routers',
        name: 'Tabel Router Terakhir Dilihat',
        desc: 'Daftar perangkat router yang baru-baru ini diakses beserta statusnya.',
        category: 'Umum',
    },
];

function isWidgetEnabled(key: string): boolean {
    return activeWidgets.value.includes(key);
}

function toggleWidget(key: string): void {
    if (activeWidgets.value.includes(key)) {
        activeWidgets.value = activeWidgets.value.filter((w) => w !== key);
    } else {
        activeWidgets.value.push(key);
    }
}

function applyPreset(preset: 'all' | 'ppp-only' | 'hotspot-only' | 'isp-combo'): void {
    if (preset === 'all') {
        activeWidgets.value = availableWidgets.map((w) => w.key);
    } else if (preset === 'ppp-only') {
        activeWidgets.value = ['fleet', 'traffic', 'ppp', 'system', 'recent_routers'];
    } else if (preset === 'hotspot-only') {
        activeWidgets.value = ['fleet', 'traffic', 'hotspot', 'system', 'recent_routers'];
    } else if (preset === 'isp-combo') {
        activeWidgets.value = ['fleet', 'traffic', 'ppp', 'hotspot', 'dhcp', 'system', 'recent_routers'];
    }
}

function saveWidgetPreferences(): void {
    try {
        localStorage.setItem(COOKIE_KEY, JSON.stringify(activeWidgets.value));
        document.cookie = `${COOKIE_KEY}=${activeWidgets.value.join(',')}; path=/; max-age=31536000; SameSite=Lax`;
    } catch (_) {}

    isCustomizing.value = false;
    reloadWithFilters();
}

function changeRouter(): void {
    reloadWithFilters();
}

function reloadWithFilters(): void {
    inertia.get(
        route('dashboard'),
        {
            router_id: activeRouterId.value ?? undefined,
            widgets: activeWidgets.value.join(','),
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }
    );
}

// ── Drag and Drop Handlers ──────────────────────────────────────────
function onDragStart(index: number, event: DragEvent): void {
    draggedWidgetIndex.value = index;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(index));
    }
}

function onDragOver(index: number, event: DragEvent): void {
    event.preventDefault();
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }
    dragOverIndex.value = index;
}

function onDrop(targetIndex: number, event: DragEvent): void {
    event.preventDefault();
    if (draggedWidgetIndex.value !== null && draggedWidgetIndex.value !== targetIndex) {
        const item = layout.value.splice(draggedWidgetIndex.value, 1)[0];
        layout.value.splice(targetIndex, 0, item);
        saveLayout();
    }
    draggedWidgetIndex.value = null;
    dragOverIndex.value = null;
}

function onDragEnd(): void {
    draggedWidgetIndex.value = null;
    dragOverIndex.value = null;
}

function moveWidget(index: number, direction: 'up' | 'down'): void {
    const target = direction === 'up' ? index - 1 : index + 1;
    if (target >= 0 && target < layout.value.length) {
        const item = layout.value.splice(index, 1)[0];
        layout.value.splice(target, 0, item);
        saveLayout();
    }
}

function setWidgetSpan(index: number, span: number): void {
    layout.value[index].span = span;
    saveLayout();
}

function saveLayout(): void {
    try {
        localStorage.setItem(LAYOUT_STORAGE_KEY, JSON.stringify(layout.value));
    } catch (_) {}
}

function resetLayout(): void {
    layout.value = JSON.parse(JSON.stringify(DEFAULT_LAYOUT));
    saveLayout();
}

function getSpanClass(span: number): string {
    switch (span) {
        case 4:
            return 'col-span-12 md:col-span-6 xl:col-span-4';
        case 6:
            return 'col-span-12 lg:col-span-6';
        case 12:
        default:
            return 'col-span-12';
    }
}

onMounted(() => {
    // 1. Load Custom Layout
    try {
        const savedLayoutStr = localStorage.getItem(LAYOUT_STORAGE_KEY);
        if (savedLayoutStr) {
            const parsed = JSON.parse(savedLayoutStr);
            if (Array.isArray(parsed) && parsed.length > 0) {
                const existingIds = new Set(parsed.map((p: WidgetLayoutItem) => p.id));
                const missing = DEFAULT_LAYOUT.filter((d) => !existingIds.has(d.id));
                layout.value = [...parsed, ...missing];
            }
        }
    } catch (_) {}

    // 2. Load Enabled Widgets
    try {
        const saved = localStorage.getItem(COOKIE_KEY);
        if (saved) {
            const parsed = JSON.parse(saved);
            if (Array.isArray(parsed) && parsed.length > 0) {
                const currentStr = (props.enabledWidgets ?? []).slice().sort().join(',');
                const savedStr = parsed.slice().sort().join(',');
                if (currentStr !== savedStr && !new URLSearchParams(window.location.search).has('widgets')) {
                    activeWidgets.value = parsed;
                    document.cookie = `${COOKIE_KEY}=${parsed.join(',')}; path=/; max-age=31536000; SameSite=Lax`;
                    reloadWithFilters();
                }
            }
        }
    } catch (_) {}
});

const stats = (c: Counts) => [
    { label: 'Total router', value: c.total, color: 'indigo' },
    { label: 'Online', value: c.online, color: 'emerald' },
    { label: 'Unreachable', value: c.unreachable, color: 'red' },
    { label: 'Unknown', value: c.unknown, color: 'amber' },
];
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout title="Dashboard">
        <!-- Fluid Full Width Dashboard Container -->
        <div class="w-full space-y-6">
            <!-- Top Action Bar: Router Selector & Layout Controls -->
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center gap-3">
                    <div>
                        <div class="text-[11px] font-semibold uppercase tracking-wider text-zinc-400">Router Aktif</div>
                        <div class="mt-0.5 flex items-center gap-2">
                            <select
                                v-if="routers.length > 0"
                                v-model="activeRouterId"
                                @change="changeRouter"
                                class="rounded-lg border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-sm font-semibold text-zinc-900 focus:border-indigo-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            >
                                <option v-for="r in routers" :key="r.id" :value="r.id">
                                    {{ r.name }} ({{ r.host }}) — {{ r.status_label }}
                                </option>
                            </select>
                            <span v-else class="text-sm font-medium text-zinc-500">
                                Belum ada router
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Right Actions: Layout Toggle & Widget Customizer -->
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Layout Mode Button -->
                    <button
                        type="button"
                        @click="isEditMode = !isEditMode"
                        class="flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-medium shadow-sm transition"
                        :class="
                            isEditMode
                                ? 'border-indigo-500 bg-indigo-50 font-semibold text-indigo-700 dark:border-indigo-500 dark:bg-indigo-950/60 dark:text-indigo-300'
                                : 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700'
                        "
                    >
                        <span>{{ isEditMode ? '✓ Selesai Mengatur' : '✏️ Atur Tata Letak' }}</span>
                    </button>

                    <!-- Widget Customizer Button -->
                    <button
                        type="button"
                        @click="isCustomizing = true"
                        class="flex items-center gap-2 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700"
                    >
                        <span>⚙️ Sesuaikan Widget</span>
                        <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400">
                            {{ activeWidgets.length }}/{{ availableWidgets.length }}
                        </span>
                    </button>

                    <Link
                        v-if="activeRouterId"
                        :href="route('routers.show', activeRouterId)"
                        class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    >
                        Detail Router →
                    </Link>
                </div>
            </div>

            <!-- Mode Edit Layout Info Banner -->
            <div
                v-if="isEditMode"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50/80 p-3.5 text-xs text-indigo-900 shadow-sm dark:border-indigo-900/60 dark:bg-indigo-950/40 dark:text-indigo-200"
            >
                <div class="flex items-center gap-2">
                    <span class="text-base">✋</span>
                    <div>
                        <strong class="font-semibold">Mode Kustomisasi Tata Letak Aktif:</strong>
                        <span class="ml-1 opacity-90">
                            Tarik (drag) pegangan <span class="font-mono font-bold">⋮⋮</span> atau gunakan tombol panah <span class="font-mono font-bold">▲ ▼</span> untuk menggeser posisi widget. Ubah lebar widget dengan tombol <span class="font-mono font-bold">⅓, ½, Penuh</span>.
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="resetLayout"
                        class="rounded-lg border border-indigo-300 bg-white px-2.5 py-1 text-xs font-medium text-indigo-700 shadow-sm transition hover:bg-indigo-50 dark:border-indigo-800 dark:bg-zinc-800 dark:text-indigo-300 dark:hover:bg-zinc-700"
                    >
                        ↺ Reset ke Default
                    </button>
                    <button
                        type="button"
                        @click="isEditMode = false"
                        class="rounded-lg bg-indigo-600 px-3 py-1 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                    >
                        ✓ Selesai
                    </button>
                </div>
            </div>

            <!-- Device Connection Warning (if selected router is unreachable) -->
            <div
                v-if="liveStats.device_error"
                class="rounded-xl border border-amber-300/60 bg-amber-50 p-4 text-xs text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300"
            >
                <div class="font-semibold">⚠️ MikroTik tidak dapat dihubungi secara langsung:</div>
                <div class="mt-0.5 font-mono">{{ liveStats.device_error }}</div>
                <div class="mt-1 text-[11px] text-amber-600 dark:text-amber-400">
                    Data modul MikroTik yang memerlukan koneksi langsung disembunyikan atau menampilkan nilai cache terakhir.
                </div>
            </div>

            <!-- DYNAMIC DRAGGABLE 12-COLUMN GRID (Expands to Full Width on Wide Screens) -->
            <div class="grid grid-cols-12 gap-6 items-start">
                <template v-for="(item, index) in layout" :key="item.id">
                    <div
                        v-if="isWidgetEnabled(item.id)"
                        :class="[
                            getSpanClass(item.span),
                            isEditMode ? 'ring-2 ring-indigo-400/40 rounded-xl overflow-hidden' : '',
                            dragOverIndex === index ? 'ring-2 ring-indigo-500 scale-[1.01] shadow-lg' : '',
                            draggedWidgetIndex === index ? 'opacity-40 scale-[0.98]' : '',
                            'transition-all duration-150',
                        ]"
                        @dragover="onDragOver(index, $event)"
                        @drop="onDrop(index, $event)"
                    >
                        <!-- EDIT TOOLBAR: Shown above each widget when in Edit Mode -->
                        <div
                            v-if="isEditMode"
                            class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-100 bg-indigo-50/80 px-3 py-1.5 text-xs dark:border-indigo-900/40 dark:bg-indigo-950/50"
                        >
                            <!-- Drag handle -->
                            <div
                                class="flex items-center gap-1.5 cursor-grab active:cursor-grabbing select-none text-indigo-700 dark:text-indigo-300"
                                draggable="true"
                                @dragstart="onDragStart(index, $event)"
                                @dragend="onDragEnd"
                                title="Klik dan tahan untuk menggeser posisi"
                            >
                                <span class="text-base font-bold">⋮⋮</span>
                                <span class="font-mono text-[11px] font-semibold">Posisi #{{ index + 1 }}</span>
                            </div>

                            <!-- Reorder arrows & Span pills -->
                            <div class="flex items-center gap-2">
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        :disabled="index === 0"
                                        @click="moveWidget(index, 'up')"
                                        class="rounded px-1.5 py-0.5 text-xs font-bold hover:bg-indigo-100 disabled:opacity-30 dark:hover:bg-indigo-900"
                                        title="Pindah ke Atas / Kiri"
                                    >
                                        ▲
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="index === layout.length - 1"
                                        @click="moveWidget(index, 'down')"
                                        class="rounded px-1.5 py-0.5 text-xs font-bold hover:bg-indigo-100 disabled:opacity-30 dark:hover:bg-indigo-900"
                                        title="Pindah ke Bawah / Kanan"
                                    >
                                        ▼
                                    </button>
                                </div>

                                <span class="text-zinc-300 dark:text-zinc-700">|</span>

                                <span class="text-[10px] text-zinc-500">Lebar:</span>
                                <div class="flex items-center rounded border border-indigo-200 bg-white p-0.5 dark:border-zinc-700 dark:bg-zinc-800">
                                    <button
                                        type="button"
                                        @click="setWidgetSpan(index, 4)"
                                        class="rounded px-1.5 py-0.5 text-[10px] font-semibold transition"
                                        :class="item.span === 4 ? 'bg-indigo-600 text-white' : 'text-zinc-600 hover:bg-indigo-50 dark:text-zinc-300'"
                                    >
                                        ⅓
                                    </button>
                                    <button
                                        type="button"
                                        @click="setWidgetSpan(index, 6)"
                                        class="rounded px-1.5 py-0.5 text-[10px] font-semibold transition"
                                        :class="item.span === 6 ? 'bg-indigo-600 text-white' : 'text-zinc-600 hover:bg-indigo-50 dark:text-zinc-300'"
                                    >
                                        ½
                                    </button>
                                    <button
                                        type="button"
                                        @click="setWidgetSpan(index, 12)"
                                        class="rounded px-1.5 py-0.5 text-[10px] font-semibold transition"
                                        :class="item.span === 12 ? 'bg-indigo-600 text-white' : 'text-zinc-600 hover:bg-indigo-50 dark:text-zinc-300'"
                                    >
                                        Penuh
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- ── 1. WIDGET: LIVE TRAFFIC MONITOR ── -->
                        <div v-if="item.id === 'traffic' && activeRouterId">
                            <TrafficChart
                                :router-id="activeRouterId"
                                :interfaces="interfaces"
                                height-class="h-72"
                            />
                        </div>

                        <!-- ── 2. WIDGET: FLEET ROUTERS STAT CARDS ── -->
                        <div v-else-if="item.id === 'fleet'" class="space-y-2">
                            <div class="flex items-center justify-between px-1">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="cursor-grab active:cursor-grabbing text-zinc-400 hover:text-indigo-600 text-sm select-none"
                                        draggable="true"
                                        @dragstart="onDragStart(index, $event)"
                                        @dragend="onDragEnd"
                                        title="Geser posisi widget"
                                    >
                                        ⋮⋮
                                    </span>
                                    <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Fleet Routers</span>
                                </div>
                                <Link :href="route('routers.index')" class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                    Semua Router →
                                </Link>
                            </div>
                            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                                <div
                                    v-for="s in stats(counts)"
                                    :key="s.label"
                                    class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                                >
                                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ s.label }}</div>
                                    <div class="mt-2 text-3xl font-semibold tabular-nums">{{ s.value }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- ── 3. WIDGET: PPPoE & PPP ── -->
                        <div
                            v-else-if="item.id === 'ppp'"
                            class="flex flex-col rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="cursor-grab active:cursor-grabbing text-zinc-400 hover:text-indigo-600 text-sm select-none"
                                        draggable="true"
                                        @dragstart="onDragStart(index, $event)"
                                        @dragend="onDragEnd"
                                        title="Geser posisi widget"
                                    >
                                        ⋮⋮
                                    </span>
                                    <span class="text-base">👥</span>
                                    <h3 class="text-sm font-semibold">PPPoE & PPP</h3>
                                </div>
                                <Link
                                    v-if="activeRouterId"
                                    :href="route('routers.ppp.index', activeRouterId)"
                                    class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                >
                                    Buka PPP →
                                </Link>
                            </div>

                            <div v-if="liveStats.ppp" class="mt-4 grid grid-cols-2 gap-4">
                                <div class="rounded-lg bg-emerald-50/60 p-3 dark:bg-emerald-950/20">
                                    <div class="text-[11px] font-medium text-emerald-700 dark:text-emerald-400">Sesi Online</div>
                                    <div class="mt-1 text-2xl font-bold font-mono text-emerald-800 dark:text-emerald-300">
                                        {{ liveStats.ppp.active }}
                                    </div>
                                </div>
                                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/60">
                                    <div class="text-[11px] font-medium text-zinc-500">Total Secrets</div>
                                    <div class="mt-1 text-2xl font-bold font-mono text-zinc-800 dark:text-zinc-200">
                                        {{ liveStats.ppp.secrets }}
                                    </div>
                                </div>
                                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/60">
                                    <div class="text-[11px] font-medium text-zinc-500">Offline Clients</div>
                                    <div class="mt-1 text-lg font-semibold font-mono text-zinc-600 dark:text-zinc-400">
                                        {{ Math.max(0, liveStats.ppp.secrets - liveStats.ppp.active) }}
                                    </div>
                                </div>
                                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/60">
                                    <div class="text-[11px] font-medium text-zinc-500">PPP Profiles</div>
                                    <div class="mt-1 text-lg font-semibold font-mono text-zinc-600 dark:text-zinc-400">
                                        {{ liveStats.ppp.profiles }}
                                    </div>
                                </div>
                            </div>
                            <div v-else class="mt-4 flex flex-1 items-center justify-center text-xs text-zinc-400">
                                Tidak ada data PPP / router offline.
                            </div>
                        </div>

                        <!-- ── 4. WIDGET: HOTSPOT & VOUCHER ── -->
                        <div
                            v-else-if="item.id === 'hotspot'"
                            class="flex flex-col rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="cursor-grab active:cursor-grabbing text-zinc-400 hover:text-indigo-600 text-sm select-none"
                                        draggable="true"
                                        @dragstart="onDragStart(index, $event)"
                                        @dragend="onDragEnd"
                                        title="Geser posisi widget"
                                    >
                                        ⋮⋮
                                    </span>
                                    <span class="text-base">🎟️</span>
                                    <h3 class="text-sm font-semibold">Hotspot & Voucher</h3>
                                </div>
                                <Link
                                    v-if="activeRouterId"
                                    :href="route('routers.hotspot.index', activeRouterId)"
                                    class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                >
                                    Buka Hotspot →
                                </Link>
                            </div>

                            <div v-if="liveStats.hotspot" class="mt-4 grid grid-cols-2 gap-4">
                                <div class="rounded-lg bg-emerald-50/60 p-3 dark:bg-emerald-950/20">
                                    <div class="text-[11px] font-medium text-emerald-700 dark:text-emerald-400">User Aktif</div>
                                    <div class="mt-1 text-2xl font-bold font-mono text-emerald-800 dark:text-emerald-300">
                                        {{ liveStats.hotspot.active }}
                                    </div>
                                </div>
                                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/60">
                                    <div class="text-[11px] font-medium text-zinc-500">Database User</div>
                                    <div class="mt-1 text-2xl font-bold font-mono text-zinc-800 dark:text-zinc-200">
                                        {{ liveStats.hotspot.users }}
                                    </div>
                                </div>
                                <div class="col-span-2 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/60 flex items-center justify-between">
                                    <div>
                                        <div class="text-[11px] font-medium text-zinc-500">Host Terhubung</div>
                                        <div class="text-xs text-zinc-400">Perangkat di jaringan hotspot</div>
                                    </div>
                                    <div class="text-xl font-bold font-mono text-zinc-700 dark:text-zinc-300">
                                        {{ liveStats.hotspot.hosts }}
                                    </div>
                                </div>
                            </div>
                            <div v-else class="mt-4 flex flex-1 items-center justify-center text-xs text-zinc-400">
                                Tidak ada data Hotspot / router offline.
                            </div>
                        </div>

                        <!-- ── 5. WIDGET: DHCP SERVER ── -->
                        <div
                            v-else-if="item.id === 'dhcp'"
                            class="flex flex-col rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="cursor-grab active:cursor-grabbing text-zinc-400 hover:text-indigo-600 text-sm select-none"
                                        draggable="true"
                                        @dragstart="onDragStart(index, $event)"
                                        @dragend="onDragEnd"
                                        title="Geser posisi widget"
                                    >
                                        ⋮⋮
                                    </span>
                                    <span class="text-base">🌐</span>
                                    <h3 class="text-sm font-semibold">DHCP Server</h3>
                                </div>
                                <Link
                                    v-if="activeRouterId"
                                    :href="route('routers.ip.dhcp.index', activeRouterId)"
                                    class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                >
                                    Buka DHCP →
                                </Link>
                            </div>

                            <div v-if="liveStats.dhcp" class="mt-4 grid grid-cols-2 gap-4">
                                <div class="rounded-lg bg-indigo-50/60 p-3 dark:bg-indigo-950/20">
                                    <div class="text-[11px] font-medium text-indigo-700 dark:text-indigo-400">Bound Leases</div>
                                    <div class="mt-1 text-2xl font-bold font-mono text-indigo-800 dark:text-indigo-300">
                                        {{ liveStats.dhcp.bound }}
                                    </div>
                                </div>
                                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/60">
                                    <div class="text-[11px] font-medium text-zinc-500">Static Leases</div>
                                    <div class="mt-1 text-2xl font-bold font-mono text-zinc-800 dark:text-zinc-200">
                                        {{ liveStats.dhcp.static }}
                                    </div>
                                </div>
                                <div class="col-span-2 rounded-lg bg-zinc-50 p-2.5 px-3 dark:bg-zinc-800/60 flex items-center justify-between text-xs">
                                    <span class="text-zinc-500">Total Leases Terdaftar</span>
                                    <span class="font-mono font-semibold">{{ liveStats.dhcp.total }}</span>
                                </div>
                            </div>
                            <div v-else class="mt-4 flex flex-1 items-center justify-center text-xs text-zinc-400">
                                Tidak ada data DHCP / router offline.
                            </div>
                        </div>

                        <!-- ── 6. WIDGET: SYSTEM HEALTH ── -->
                        <div
                            v-else-if="item.id === 'system'"
                            class="flex flex-col rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="cursor-grab active:cursor-grabbing text-zinc-400 hover:text-indigo-600 text-sm select-none"
                                        draggable="true"
                                        @dragstart="onDragStart(index, $event)"
                                        @dragend="onDragEnd"
                                        title="Geser posisi widget"
                                    >
                                        ⋮⋮
                                    </span>
                                    <span class="text-base">💻</span>
                                    <h3 class="text-sm font-semibold">Kesehatan Sistem</h3>
                                </div>
                                <Link
                                    v-if="activeRouterId"
                                    :href="route('routers.system.index', activeRouterId)"
                                    class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                >
                                    Buka System →
                                </Link>
                            </div>

                            <div v-if="liveStats.system" class="mt-4 space-y-3 text-xs">
                                <!-- CPU -->
                                <div>
                                    <div class="flex justify-between font-medium">
                                        <span class="text-zinc-500">CPU Load ({{ liveStats.system.cpu_count }} Core)</span>
                                        <span class="font-mono font-bold">{{ liveStats.system.cpu_load }}%</span>
                                    </div>
                                    <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div
                                            class="h-full transition-all duration-300"
                                            :class="{
                                                'bg-emerald-500': liveStats.system.cpu_load < 50,
                                                'bg-amber-500': liveStats.system.cpu_load >= 50 && liveStats.system.cpu_load < 80,
                                                'bg-red-500': liveStats.system.cpu_load >= 80,
                                            }"
                                            :style="{ width: `${Math.min(100, liveStats.system.cpu_load)}%` }"
                                        />
                                    </div>
                                </div>

                                <!-- RAM -->
                                <div>
                                    <div class="flex justify-between font-medium">
                                        <span class="text-zinc-500">RAM Terpakai</span>
                                        <span class="font-mono">{{ liveStats.system.memory_used_pct }}% (Free: {{ liveStats.system.memory_free_human }})</span>
                                    </div>
                                    <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div
                                            class="h-full bg-indigo-500 transition-all duration-300"
                                            :style="{ width: `${Math.min(100, liveStats.system.memory_used_pct)}%` }"
                                        />
                                    </div>
                                </div>

                                <!-- Uptime & Board -->
                                <div class="border-t border-zinc-100 pt-2 dark:border-zinc-800 flex justify-between text-zinc-500">
                                    <span>Uptime: <strong class="font-mono text-zinc-800 dark:text-zinc-200">{{ liveStats.system.uptime }}</strong></span>
                                    <span class="font-mono truncate max-w-[140px]">{{ liveStats.system.board_name }}</span>
                                </div>
                            </div>
                            <div v-else class="mt-4 flex flex-1 items-center justify-center text-xs text-zinc-400">
                                Tidak ada data System / router offline.
                            </div>
                        </div>

                        <!-- ── 7. WIDGET: SIMPLE QUEUES ── -->
                        <div
                            v-else-if="item.id === 'queues'"
                            class="flex flex-col rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="cursor-grab active:cursor-grabbing text-zinc-400 hover:text-indigo-600 text-sm select-none"
                                        draggable="true"
                                        @dragstart="onDragStart(index, $event)"
                                        @dragend="onDragEnd"
                                        title="Geser posisi widget"
                                    >
                                        ⋮⋮
                                    </span>
                                    <span class="text-base">⚡</span>
                                    <h3 class="text-sm font-semibold">Simple Queues</h3>
                                </div>
                                <Link
                                    v-if="activeRouterId"
                                    :href="route('routers.queues.index', activeRouterId)"
                                    class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                >
                                    Buka Queues →
                                </Link>
                            </div>

                            <div v-if="liveStats.queues" class="mt-4 grid grid-cols-2 gap-4">
                                <div class="rounded-lg bg-emerald-50/60 p-3 dark:bg-emerald-950/20">
                                    <div class="text-[11px] font-medium text-emerald-700 dark:text-emerald-400">Antrian Aktif</div>
                                    <div class="mt-1 text-2xl font-bold font-mono text-emerald-800 dark:text-emerald-300">
                                        {{ liveStats.queues.active }}
                                    </div>
                                </div>
                                <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/60">
                                    <div class="text-[11px] font-medium text-zinc-500">Total Queues</div>
                                    <div class="mt-1 text-2xl font-bold font-mono text-zinc-800 dark:text-zinc-200">
                                        {{ liveStats.queues.total }}
                                    </div>
                                </div>
                            </div>
                            <div v-else class="mt-4 flex flex-1 items-center justify-center text-xs text-zinc-400">
                                Tidak ada data Queues / router offline.
                            </div>
                        </div>

                        <!-- ── 8. WIDGET: RECENTLY SEEN ROUTERS ── -->
                        <div
                            v-else-if="item.id === 'recent_routers'"
                            class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="cursor-grab active:cursor-grabbing text-zinc-400 hover:text-indigo-600 text-sm select-none"
                                        draggable="true"
                                        @dragstart="onDragStart(index, $event)"
                                        @dragend="onDragEnd"
                                        title="Geser posisi widget"
                                    >
                                        ⋮⋮
                                    </span>
                                    <h2 class="text-sm font-semibold">Recently Seen Routers</h2>
                                </div>
                                <Link :href="route('routers.index')" class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                    View all →
                                </Link>
                            </div>

                            <div v-if="recentRouters.length === 0" class="px-5 py-10 text-center text-sm text-zinc-500">
                                No routers yet.
                                <Link :href="route('routers.create')" class="text-indigo-600 hover:underline dark:text-indigo-400">Add your first router</Link>.
                            </div>

                            <ul v-else class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <li v-for="r in recentRouters" :key="r.id">
                                    <Link
                                        :href="route('routers.show', r.id)"
                                        class="flex items-center gap-4 px-5 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                    >
                                        <StatusDot :color="r.status_color" :pulse="r.status === 'online'" />
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-sm font-medium">{{ r.name }}</div>
                                            <div class="truncate text-xs text-zinc-500">{{ r.host }}</div>
                                        </div>
                                        <div class="hidden text-xs text-zinc-500 sm:block">{{ r.routeros_version ?? '—' }}</div>
                                        <div class="w-28 text-right text-xs text-zinc-400">{{ r.last_seen_at ?? 'never' }}</div>
                                    </Link>
                                </li>
                            </ul>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- MODAL: Customize Widgets ("Sesuaikan Widget") -->
        <Modal :open="isCustomizing" title="Sesuaikan Widget Dashboard" @close="isCustomizing = false">
            <div class="space-y-4">
                <p class="text-xs text-zinc-500">
                    Pilih modul yang ingin ditampilkan di dashboard. Modul yang dinonaktifkan
                    <strong>tidak akan dibaca ke MikroTik</strong>, menjaga CPU router tetap ringan.
                </p>

                <!-- Quick Presets -->
                <div class="flex flex-wrap gap-1.5 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <button
                        type="button"
                        @click="applyPreset('all')"
                        class="rounded bg-zinc-100 px-2 py-1 text-[11px] font-medium text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                    >
                        Tampilkan Semua
                    </button>
                    <button
                        type="button"
                        @click="applyPreset('ppp-only')"
                        class="rounded bg-zinc-100 px-2 py-1 text-[11px] font-medium text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                    >
                        Khusus PPPoE (ISP)
                    </button>
                    <button
                        type="button"
                        @click="applyPreset('hotspot-only')"
                        class="rounded bg-zinc-100 px-2 py-1 text-[11px] font-medium text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                    >
                        Khusus Hotspot (RT/RW)
                    </button>
                    <button
                        type="button"
                        @click="applyPreset('isp-combo')"
                        class="rounded bg-zinc-100 px-2 py-1 text-[11px] font-medium text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                    >
                        PPP + Hotspot
                    </button>
                </div>

                <!-- Widget Checkbox List -->
                <div class="space-y-2.5 max-h-96 overflow-y-auto pr-1">
                    <label
                        v-for="w in availableWidgets"
                        :key="w.key"
                        class="flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition"
                        :class="
                            isWidgetEnabled(w.key)
                                ? 'border-indigo-500/50 bg-indigo-50/20 dark:border-indigo-500/30 dark:bg-indigo-950/20'
                                : 'border-zinc-200 bg-white hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800/50'
                        "
                    >
                        <input
                            type="checkbox"
                            :checked="isWidgetEnabled(w.key)"
                            @change="toggleWidget(w.key)"
                            class="mt-0.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">{{ w.name }}</span>
                                <span class="text-[10px] text-zinc-400">{{ w.category }}</span>
                            </div>
                            <p class="mt-0.5 text-[11px] text-zinc-500">{{ w.desc }}</p>
                        </div>
                    </label>
                </div>

                <!-- Footer Buttons -->
                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <Btn variant="secondary" @click="isCustomizing = false">Batal</Btn>
                    <Btn variant="primary" @click="saveWidgetPreferences">Simpan & Terapkan</Btn>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
