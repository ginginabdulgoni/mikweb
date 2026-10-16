<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router as inertia } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';
import StatusDot from '@/components/ui/StatusDot.vue';
import LineChart from '@/components/LineChart.vue';
import MultiRouterTrafficCard, { type LiveTrafficPayload } from '@/components/MultiRouterTrafficCard.vue';
import MultiRouterTrafficCombined, { type RouterTrafficStream } from '@/components/MultiRouterTrafficCombined.vue';

interface Row {
    id: number;
    name: string;
    host: string;
    status: string;
    status_color: string;
    cpu_load: number | null;
    mem_percent: number | null;
    mem_used?: number | null;
    mem_total?: number | null;
    disk_used?: number | null;
    disk_total?: number | null;
    uptime?: string | null;
    latency_ms: number | null;
    recorded_at: string | null;
    hardware_model?: string;
    routeros_version?: string;
    architecture?: string;
}

interface Kpis {
    total: number;
    online: number;
    unreachable: number;
    avg_cpu: number | null;
    avg_mem: number | null;
    avg_latency: number | null;
}

const props = defineProps<{
    routers: Row[];
    selectedRouter: number;
    selectedRouters?: number[];
    selectedRouterName: string;
    range: string;
    initialTab?: string;
    kpis: Kpis;
    chart: {
        labels: (string | null)[];
        cpu: (number | null)[];
        mem: (number | null)[];
        latency: (number | null)[];
    };
}>();

// Main Tab Navigation: 'traffic' (Realtime Multi-Router) | 'telemetry' (Fleet Telemetry & History)
const activeTab = ref<'traffic' | 'telemetry'>('traffic');

// ── Comparison Category Mode ─────────────────────────────────────────
type ComparisonMode = 'traffic' | 'pppoe_hotspot' | 'resources' | 'latency' | 'hardware';
const comparisonMode = ref<ComparisonMode>('traffic');

// ── Realtime Multi-Router Traffic State ──────────────────────────────
const trafficViewMode = ref<'grid' | 'combined'>('grid');
const trafficPollInterval = ref<number>(10000); // Default 10s to prevent spamming MikroTik logs
const selectedIntervalOption = ref<string>('10000');
const customIntervalSeconds = ref<number>(10);
const isGlobalTrafficPaused = ref<boolean>(false);
const selectedTrafficRouterIds = ref<number[]>([]);
const streams = ref<Record<number, RouterTrafficStream>>({});

// ── Live Direct-Router Fleet Stats (PPPoE, Hotspot, Hardware, Uptime) ─
interface LiveRouterStats {
    id: number;
    status: string;
    status_color: string;
    uptime?: string | null;
    cpu_load: number | null;
    mem_percent: number | null;
    mem_used?: number | null;
    mem_total?: number | null;
    disk_used?: number | null;
    disk_total?: number | null;
    latency_ms: number | null;
    recorded_at: string | null;
    ppp?: {
        active: number;
        secrets: number;
    };
    hotspot?: {
        active: number;
        users: number;
    };
    error?: string | null;
}

const liveFleetStats = ref<Record<number, LiveRouterStats>>({});
const isFleetPolling = ref<boolean>(false);
const lastFleetSyncTime = ref<string>('');
let fleetPollTimer: ReturnType<typeof setInterval> | null = null;
let fleetAbortController: AbortController | null = null;

// Polling should pause if user pauses, leaves the tab, or switches to another comparison category
const shouldPauseTraffic = computed(() => {
    return isGlobalTrafficPaused.value || activeTab.value !== 'traffic' || comparisonMode.value !== 'traffic';
});

// Selected router objects helper - strictly preserves custom order and merges live fleet stats
const selectedRoutersList = computed(() => {
    return selectedTrafficRouterIds.value
        .map((id) => {
            const base = props.routers.find((r) => r.id === id);
            if (!base) return null;
            const live = liveFleetStats.value[id];
            if (!live) return base;

            return {
                ...base,
                status: live.status ?? base.status,
                status_color: live.status_color ?? base.status_color,
                cpu_load: live.cpu_load !== null && live.cpu_load !== undefined ? live.cpu_load : base.cpu_load,
                mem_percent: live.mem_percent !== null && live.mem_percent !== undefined ? live.mem_percent : base.mem_percent,
                mem_used: live.mem_used ?? base.mem_used,
                mem_total: live.mem_total ?? base.mem_total,
                disk_used: live.disk_used ?? base.disk_used,
                disk_total: live.disk_total ?? base.disk_total,
                uptime: live.uptime ?? base.uptime,
                latency_ms: live.latency_ms !== null && live.latency_ms !== undefined ? live.latency_ms : base.latency_ms,
                recorded_at: live.recorded_at ?? base.recorded_at,
                ppp: live.ppp,
                hotspot: live.hotspot,
            };
        })
        .filter((r): r is (Row & { ppp?: { active: number; secrets: number }; hotspot?: { active: number; users: number } }) => !!r);
});

// Comparison Resource summary
const resourcesSummary = computed(() => {
    const list = selectedRoutersList.value.filter((r) => r.cpu_load !== null);
    const avgCpu = list.length > 0 ? Math.round(list.reduce((acc, r) => acc + (r.cpu_load ?? 0), 0) / list.length) : null;
    const avgMem = list.length > 0 ? Math.round(list.reduce((acc, r) => acc + (r.mem_percent ?? 0), 0) / list.length) : null;
    const highestCpuRouter = list.length > 0 ? [...list].sort((a, b) => (b.cpu_load ?? 0) - (a.cpu_load ?? 0))[0] : null;
    return { avgCpu, avgMem, highestCpuRouter };
});

// Comparison Latency list sorted best to worst
const latencyRanking = computed(() => {
    return [...selectedRoutersList.value].sort((a, b) => {
        if (a.latency_ms === null) return 1;
        if (b.latency_ms === null) return -1;
        return a.latency_ms - b.latency_ms;
    });
});

// PPPoE & Hotspot Fleet Summary KPI
const fleetPppoeHotspotSummary = computed(() => {
    const list = selectedRoutersList.value;
    let totalPppActive = 0;
    let totalPppSecrets = 0;
    let totalHotspotActive = 0;
    let totalHotspotUsers = 0;

    for (const r of list) {
        totalPppActive += r.ppp?.active ?? 0;
        totalPppSecrets += r.ppp?.secrets ?? 0;
        totalHotspotActive += r.hotspot?.active ?? 0;
        totalHotspotUsers += r.hotspot?.users ?? 0;
    }

    return {
        totalPppActive,
        totalPppSecrets,
        totalHotspotActive,
        totalHotspotUsers,
        totalActiveUsers: totalPppActive + totalHotspotActive,
    };
});

// Drag & drop and card reordering state
const draggedIndex = ref<number | null>(null);
const dragOverIndex = ref<number | null>(null);

function handleDragStart(index: number, event: DragEvent) {
    draggedIndex.value = index;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(index));
    }
}

function handleDragOver(index: number, event: DragEvent) {
    event.preventDefault();
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }
    dragOverIndex.value = index;
}

function handleDrop(targetIndex: number) {
    if (draggedIndex.value === null || draggedIndex.value === targetIndex) {
        draggedIndex.value = null;
        dragOverIndex.value = null;
        return;
    }
    const list = [...selectedTrafficRouterIds.value];
    const [moved] = list.splice(draggedIndex.value, 1);
    list.splice(targetIndex, 0, moved);
    selectedTrafficRouterIds.value = list;
    draggedIndex.value = null;
    dragOverIndex.value = null;
}

function handleDragEnd() {
    draggedIndex.value = null;
    dragOverIndex.value = null;
}

function moveRouterPosition(index: number, direction: 'left' | 'right') {
    const target = direction === 'left' ? index - 1 : index + 1;
    if (target < 0 || target >= selectedTrafficRouterIds.value.length) return;
    const list = [...selectedTrafficRouterIds.value];
    const [moved] = list.splice(index, 1);
    list.splice(target, 0, moved);
    selectedTrafficRouterIds.value = list;
}

function fmtBytes(bytes?: number | null): string {
    const n = Number(bytes ?? 0);
    if (!n) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(n) / Math.log(1024));
    return `${(n / 1024 ** i).toFixed(1)} ${u[i]}`;
}

function onIntervalOptionChange(): void {
    if (selectedIntervalOption.value !== 'custom') {
        trafficPollInterval.value = Number(selectedIntervalOption.value);
        localStorage.setItem('mikweb:traffic:interval', selectedIntervalOption.value);
    } else {
        trafficPollInterval.value = customIntervalSeconds.value * 1000;
        localStorage.setItem('mikweb:traffic:interval', `custom:${customIntervalSeconds.value}`);
    }
    startFleetPolling();
}

function applyCustomInterval(): void {
    let s = Number(customIntervalSeconds.value);
    if (!s || s < 3) s = 3;
    if (s > 600) s = 600;
    customIntervalSeconds.value = s;
    trafficPollInterval.value = s * 1000;
    localStorage.setItem('mikweb:traffic:interval', `custom:${s}`);
    startFleetPolling();
}

// ── Direct-Router Polling for non-traffic modes ──────────────────────
async function pollFleetStats(force = false) {
    if (!force) {
        if (isGlobalTrafficPaused.value || activeTab.value !== 'traffic' || comparisonMode.value === 'traffic') {
            return;
        }
    }
    if (selectedTrafficRouterIds.value.length === 0) {
        return;
    }

    if (fleetAbortController) {
        fleetAbortController.abort();
    }
    fleetAbortController = new AbortController();

    isFleetPolling.value = true;

    try {
        const ids = selectedTrafficRouterIds.value.join(',');
        const response = await fetch(`${route('monitoring.fleet-stats')}?routers=${ids}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: fleetAbortController.signal,
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();
        if (data && data.success && data.routers) {
            liveFleetStats.value = {
                ...liveFleetStats.value,
                ...data.routers,
            };
            const now = new Date();
            lastFleetSyncTime.value = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
    } catch (err: any) {
        if (err.name !== 'AbortError') {
            console.warn('[FleetStats] poll error:', err);
        }
    } finally {
        isFleetPolling.value = false;
    }
}

function startFleetPolling() {
    stopFleetPolling();
    if (comparisonMode.value !== 'traffic' && activeTab.value === 'traffic' && !isGlobalTrafficPaused.value) {
        pollFleetStats();
    }
    fleetPollTimer = setInterval(() => {
        pollFleetStats();
    }, trafficPollInterval.value);
}

function stopFleetPolling() {
    if (fleetPollTimer) {
        clearInterval(fleetPollTimer);
        fleetPollTimer = null;
    }
    if (fleetAbortController) {
        fleetAbortController.abort();
        fleetAbortController = null;
    }
}

function triggerManualRefresh() {
    pollFleetStats(true);
}

// Initialize selected routers for realtime traffic
onMounted(() => {
    // Read saved tab from URL or localStorage
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab');
    if (tabParam === 'telemetry' || tabParam === 'traffic') {
        activeTab.value = tabParam;
    } else {
        const savedTab = localStorage.getItem('mikweb:monitoring:tab');
        if (savedTab === 'telemetry' || savedTab === 'traffic') {
            activeTab.value = savedTab;
        }
    }

    // Read saved comparison mode
    const savedMode = localStorage.getItem('mikweb:monitoring:comparison_mode');
    if (savedMode && ['traffic', 'pppoe_hotspot', 'resources', 'latency', 'hardware'].includes(savedMode)) {
        comparisonMode.value = savedMode as ComparisonMode;
    }

    // Read saved router selection for traffic
    const savedTrafficRouters = localStorage.getItem('mikweb:monitoring:traffic_routers');
    if (savedTrafficRouters) {
        try {
            const parsed = JSON.parse(savedTrafficRouters);
            if (Array.isArray(parsed) && parsed.length > 0) {
                // Keep only valid router IDs
                const valid = parsed.filter((id: number) => props.routers.some((r) => r.id === id));
                if (valid.length > 0) {
                    selectedTrafficRouterIds.value = valid;
                    return;
                }
            }
        } catch {
            // ignore
        }
    }

    // Default: Select all online routers, or first 4 routers if available
    const online = props.routers.filter((r) => r.status === 'online').map((r) => r.id);
    if (online.length > 0) {
        selectedTrafficRouterIds.value = online;
    } else if (props.routers.length > 0) {
        selectedTrafficRouterIds.value = props.routers.slice(0, 4).map((r) => r.id);
    }

    // Read saved interval
    const savedInterval = localStorage.getItem('mikweb:traffic:interval');
    if (savedInterval) {
        if (savedInterval.startsWith('custom:')) {
            const sec = Number(savedInterval.split(':')[1]) || 10;
            customIntervalSeconds.value = sec;
            selectedIntervalOption.value = 'custom';
            trafficPollInterval.value = sec * 1000;
        } else {
            selectedIntervalOption.value = savedInterval;
            trafficPollInterval.value = Number(savedInterval);
        }
    } else {
        selectedIntervalOption.value = '10000';
        trafficPollInterval.value = 10000;
    }

    startFleetPolling();
});

onBeforeUnmount(() => {
    stopFleetPolling();
});

watch(comparisonMode, (newMode) => {
    localStorage.setItem('mikweb:monitoring:comparison_mode', newMode);
    if (newMode !== 'traffic') {
        pollFleetStats(true);
    }
});

watch(isGlobalTrafficPaused, (paused) => {
    if (!paused && comparisonMode.value !== 'traffic') {
        pollFleetStats(true);
    }
});

watch(selectedTrafficRouterIds, (newVal) => {
    localStorage.setItem('mikweb:monitoring:traffic_routers', JSON.stringify(newVal));
}, { deep: true });

function setTab(tab: 'traffic' | 'telemetry') {
    activeTab.value = tab;
    localStorage.setItem('mikweb:monitoring:tab', tab);
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url.toString());
}

function isTrafficSelected(id: number): boolean {
    return selectedTrafficRouterIds.value.includes(id);
}

function toggleTrafficRouter(id: number) {
    if (isTrafficSelected(id)) {
        selectedTrafficRouterIds.value = selectedTrafficRouterIds.value.filter((rId) => rId !== id);
    } else {
        selectedTrafficRouterIds.value = [...selectedTrafficRouterIds.value, id];
    }
}

function selectAllRoutersForTraffic() {
    selectedTrafficRouterIds.value = props.routers.map((r) => r.id);
}

function selectAllOnlineRoutersForTraffic() {
    selectedTrafficRouterIds.value = props.routers.filter((r) => r.status === 'online').map((r) => r.id);
}

function clearTrafficSelection() {
    selectedTrafficRouterIds.value = [];
}

function removeTrafficRouter(id: number) {
    selectedTrafficRouterIds.value = selectedTrafficRouterIds.value.filter((rId) => rId !== id);
}

function switchToTrafficWithRouter(id: number) {
    if (!selectedTrafficRouterIds.value.includes(id)) {
        selectedTrafficRouterIds.value = [...selectedTrafficRouterIds.value, id];
    }
    setTab('traffic');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Handler for traffic updates from MultiRouterTrafficCard
function onTrafficUpdate(payload: LiveTrafficPayload) {
    const existing = streams.value[payload.routerId];
    const MAX_COMBINED_POINTS = 30;

    const rxHist = existing ? [...existing.rxHistory, payload.rxBps] : [payload.rxBps];
    const txHist = existing ? [...existing.txHistory, payload.txBps] : [payload.txBps];
    const timeHist = existing ? [...existing.timestamps, payload.timestamp] : [payload.timestamp];

    if (rxHist.length > MAX_COMBINED_POINTS) {
        rxHist.shift();
        txHist.shift();
        timeHist.shift();
    }

    streams.value[payload.routerId] = {
        routerId: payload.routerId,
        routerName: payload.routerName,
        interfaceName: payload.interfaceName,
        rxBps: payload.rxBps,
        txBps: payload.txBps,
        rxHistory: rxHist,
        txHistory: txHist,
        timestamps: timeHist,
        isError: payload.isError,
    };
}

// ── Telemetry & Fleet State ──────────────────────────────────────────
const searchQuery = ref('');
const statusFilter = ref<'all' | 'online' | 'unreachable'>('all');
const activeChartTab = ref<'system' | 'latency'>('system');
const autoRefreshInterval = ref<number>(0);
const autoRefreshTimer = ref<any>(null);
const isCollecting = ref(false);

const filteredRouters = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    return props.routers.filter((r) => {
        if (statusFilter.value === 'online' && r.status !== 'online') return false;
        if (statusFilter.value === 'unreachable' && r.status !== 'unreachable') return false;
        if (!q) return true;
        return r.name.toLowerCase().includes(q) || r.host.toLowerCase().includes(q);
    });
});

function selectTelemetryRouter(id: number) {
    inertia.get(
        route('monitoring.index'),
        { router: id, range: props.range, tab: activeTab.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function setRange(range: string) {
    inertia.get(
        route('monitoring.index'),
        { router: props.selectedRouter, range, tab: activeTab.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function collectAll() {
    isCollecting.value = true;
    inertia.post(
        route('monitoring.collect'),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isCollecting.value = false;
            },
        },
    );
}

function collectSelected() {
    if (!props.selectedRouter) return;
    isCollecting.value = true;
    inertia.post(
        route('monitoring.routers.collect', props.selectedRouter),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isCollecting.value = false;
            },
        },
    );
}

function setAutoRefresh(seconds: number) {
    autoRefreshInterval.value = seconds;
    if (autoRefreshTimer.value) {
        clearInterval(autoRefreshTimer.value);
        autoRefreshTimer.value = null;
    }

    if (seconds > 0) {
        autoRefreshTimer.value = setInterval(() => {
            inertia.reload({ preserveScroll: true, preserveState: true });
        }, seconds * 1000);
    }
}

watch(autoRefreshInterval, (val) => {
    setAutoRefresh(val);
});

onBeforeUnmount(() => {
    if (autoRefreshTimer.value) {
        clearInterval(autoRefreshTimer.value);
    }
});
</script>

<template>
    <Head title="Monitoring Multi-Router" />
    <AppLayout title="Telemetry & Monitoring">
        <div class="w-full space-y-6">
            <!-- Header & Primary Mode Switcher Tabs -->
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-200 pb-4 dark:border-zinc-800">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <span>Monitoring &amp; Telemetri Multi-Router</span>
                        <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                            Realtime
                        </span>
                    </h2>
                    <p class="text-xs text-zinc-500">
                        Pantau traffic bandwidth realtime seluruh router bersamaan atau tinjau metrik histori CPU, RAM &amp; Latensi fleet.
                    </p>
                </div>

                <!-- Primary Mode Toggle Tabs -->
                <div class="flex items-center rounded-xl border border-zinc-200 bg-zinc-100/80 p-1 text-xs shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 font-semibold transition"
                        :class="activeTab === 'traffic'
                            ? 'bg-indigo-600 text-white shadow-xs'
                            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                        @click="setTab('traffic')"
                    >
                        <span>⚡ Realtime Multi-Router Traffic</span>
                        <span
                            class="rounded-full px-1.5 py-0.2 text-[10px] font-bold"
                            :class="activeTab === 'traffic' ? 'bg-white/20 text-white' : 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'"
                        >
                            {{ selectedTrafficRouterIds.length }}
                        </span>
                    </button>

                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 font-semibold transition"
                        :class="activeTab === 'telemetry'
                            ? 'bg-indigo-600 text-white shadow-xs'
                            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                        @click="setTab('telemetry')"
                    >
                        <span>📊 Telemetri &amp; Kesehatan Fleet</span>
                    </button>
                </div>
            </div>

            <!-- ============================================================== -->
            <!-- TAB 1: REALTIME MULTI-ROUTER TRAFFIC                           -->
            <!-- ============================================================== -->
            <div v-show="activeTab === 'traffic'" class="space-y-6">
                <!-- Router Multi-Selection & Controls Bar -->
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <!-- Top row: Selection instructions & Global controls -->
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">
                                Pilih Router untuk Dipantau Bersamaan:
                            </span>
                            <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                {{ selectedTrafficRouterIds.length }} dari {{ routers.length }} Terpilih
                            </span>
                        </div>

                        <!-- Global controls & View mode -->
                        <div class="flex flex-wrap items-center gap-2.5 text-xs">
                            <!-- View mode: Grid vs Combined (Only when comparisonMode === 'traffic') -->
                            <div
                                v-if="comparisonMode === 'traffic'"
                                class="flex items-center rounded-lg border border-zinc-200 bg-zinc-50 p-0.5 dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <button
                                    type="button"
                                    class="flex items-center gap-1 rounded-md px-2.5 py-1 font-medium transition"
                                    :class="trafficViewMode === 'grid'
                                        ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                                    @click="trafficViewMode = 'grid'"
                                >
                                    <span>⊞ Grid Kartu</span>
                                </button>
                                <button
                                    type="button"
                                    class="flex items-center gap-1 rounded-md px-2.5 py-1 font-medium transition"
                                    :class="trafficViewMode === 'combined'
                                        ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                                    @click="trafficViewMode = 'combined'"
                                >
                                    <span>📊 Grafik Gabungan</span>
                                </button>
                            </div>

                            <!-- Polling Interval Dropdown & Custom Input (Available across ALL modes) -->
                            <div
                                class="flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1 dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <span class="text-[11px] text-zinc-400">Interval:</span>
                                <select
                                    v-model="selectedIntervalOption"
                                    class="bg-transparent font-medium text-zinc-700 focus:outline-hidden dark:text-zinc-200"
                                    @change="onIntervalOptionChange"
                                >
                                    <option value="5000">5 detik (Cepat)</option>
                                    <option value="10000">10 detik (Standar - Hemat)</option>
                                    <option value="30000">30 detik (Santai)</option>
                                    <option value="60000">60 detik (Ringan)</option>
                                    <option value="custom">⚙️ Custom...</option>
                                </select>

                                <!-- Custom Seconds Input -->
                                <div
                                    v-if="selectedIntervalOption === 'custom'"
                                    class="flex items-center gap-1 border-l border-zinc-200 pl-1.5 dark:border-zinc-700"
                                >
                                    <input
                                        v-model.number="customIntervalSeconds"
                                        type="number"
                                        min="3"
                                        max="600"
                                        step="1"
                                        class="w-14 rounded border border-zinc-300 bg-white px-1.5 py-0.5 text-xs text-zinc-800 focus:border-indigo-500 focus:outline-hidden dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                                        placeholder="10"
                                        @change="applyCustomInterval"
                                    />
                                    <span class="text-[10px] text-zinc-400">dtk</span>
                                </div>
                            </div>

                            <!-- Manual Refresh Button (Direct Router Query) -->
                            <button
                                type="button"
                                :disabled="isFleetPolling"
                                class="flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 font-medium text-zinc-700 transition hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                title="Ambil data baru langsung dari router sekarang"
                                @click="triggerManualRefresh"
                            >
                                <span :class="{ 'animate-spin': isFleetPolling }">↻</span>
                                <span class="hidden sm:inline">Refresh</span>
                            </button>

                            <!-- Global Pause/Resume Button -->
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-lg border px-3 py-1 font-medium transition"
                                :class="isGlobalTrafficPaused
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                    : 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'"
                                @click="isGlobalTrafficPaused = !isGlobalTrafficPaused"
                            >
                                <span>{{ isGlobalTrafficPaused ? '▶ Resume' : '⏸ Pause' }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Comparison Sub-Tabs Switcher -->
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="mr-1 text-xs font-bold uppercase tracking-wider text-zinc-400">Mode:</span>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                                :class="comparisonMode === 'traffic'
                                    ? 'bg-indigo-600 text-white shadow-xs'
                                    : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                                @click="comparisonMode = 'traffic'"
                            >
                                <span>⚡ Live Traffic</span>
                            </button>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                                :class="comparisonMode === 'pppoe_hotspot'
                                    ? 'bg-indigo-600 text-white shadow-xs'
                                    : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                                @click="comparisonMode = 'pppoe_hotspot'"
                            >
                                <span>👥 PPPoE &amp; Hotspot</span>
                                <span
                                    v-if="fleetPppoeHotspotSummary.totalActiveUsers > 0"
                                    class="rounded-full px-1.5 py-0.2 text-[10px] font-bold"
                                    :class="comparisonMode === 'pppoe_hotspot' ? 'bg-white/20 text-white' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'"
                                >
                                    {{ fleetPppoeHotspotSummary.totalActiveUsers }} Sesi
                                </span>
                            </button>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                                :class="comparisonMode === 'resources'
                                    ? 'bg-indigo-600 text-white shadow-xs'
                                    : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                                @click="comparisonMode = 'resources'"
                            >
                                <span>💻 CPU &amp; RAM</span>
                            </button>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                                :class="comparisonMode === 'latency'
                                    ? 'bg-indigo-600 text-white shadow-xs'
                                    : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                                @click="comparisonMode = 'latency'"
                            >
                                <span>📶 Latensi Link</span>
                            </button>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                                :class="comparisonMode === 'hardware'
                                    ? 'bg-indigo-600 text-white shadow-xs'
                                    : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                                @click="comparisonMode = 'hardware'"
                            >
                                <span>🌡️ Hardware &amp; Uptime</span>
                            </button>
                        </div>

                        <!-- Sync Status Indicator -->
                        <div v-if="comparisonMode !== 'traffic'" class="flex items-center gap-2 text-[11px] text-zinc-400">
                            <span v-if="isFleetPolling" class="flex items-center gap-1 text-indigo-600 dark:text-indigo-400 font-medium">
                                <span class="inline-block animate-spin">↻</span>
                                Mengambil data langsung dari router...
                            </span>
                            <span v-else-if="lastFleetSyncTime" class="flex items-center gap-1">
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                Update: {{ lastFleetSyncTime }}
                            </span>
                        </div>
                    </div>

                    <!-- Bottom row: Quick action selection buttons & Router toggle pills -->
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <!-- Router Multi-select pills -->
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                v-for="r in routers"
                                :key="r.id"
                                type="button"
                                class="flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-medium transition-all"
                                :class="isTrafficSelected(r.id)
                                    ? 'border-indigo-500 bg-indigo-50 text-indigo-900 shadow-xs dark:border-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-200 font-semibold'
                                    : 'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800/60 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                                @click="toggleTrafficRouter(r.id)"
                            >
                                <input
                                    type="checkbox"
                                    :checked="isTrafficSelected(r.id)"
                                    class="h-3.5 w-3.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
                                    @click.stop="toggleTrafficRouter(r.id)"
                                />
                                <StatusDot :color="r.status_color" :pulse="r.status === 'online'" />
                                <span>{{ r.name }}</span>
                                <span class="font-mono text-[10px] opacity-60">({{ r.host }})</span>
                            </button>
                        </div>

                        <!-- Quick Selection Helpers -->
                        <div class="flex items-center gap-1.5 text-xs">
                            <button
                                type="button"
                                class="rounded-md border border-zinc-200 bg-zinc-50 px-2.5 py-1 font-medium text-zinc-600 transition hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                @click="selectAllRoutersForTraffic"
                            >
                                ✓ Seluruh Router
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1 font-medium text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300"
                                @click="selectAllOnlineRoutersForTraffic"
                            >
                                ✓ Seluruh Online
                            </button>
                            <button
                                type="button"
                                class="rounded-md border border-zinc-200 px-2.5 py-1 font-medium text-zinc-500 transition hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800"
                                @click="clearTrafficSelection"
                            >
                                ✕ Bersihkan
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Empty state if no router selected -->
                <div
                    v-if="selectedTrafficRouterIds.length === 0"
                    class="rounded-xl border border-dashed border-zinc-300 bg-white p-12 text-center dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-indigo-50 text-xl text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-400">
                        ⚡
                    </div>
                    <h3 class="mt-3 text-sm font-semibold text-zinc-900 dark:text-zinc-100">Belum Ada Router yang Dipilih</h3>
                    <p class="mt-1 text-xs text-zinc-500">
                        Klik tombol <strong>"Seluruh Router"</strong> atau centang beberapa router di atas untuk mulai memantau dan membandingkan router terpilih.
                    </p>
                    <div class="mt-4 flex items-center justify-center gap-2">
                        <Btn variant="primary" size="sm" @click="selectAllOnlineRoutersForTraffic">
                            Pantau Seluruh Router Online
                        </Btn>
                        <Btn variant="secondary" size="sm" @click="selectAllRoutersForTraffic">
                            Pilih Semua Router
                        </Btn>
                    </div>
                </div>

                <!-- ============================================================== -->
                <!-- VIEW 1: REALTIME TRAFFIC                                        -->
                <!-- ============================================================== -->
                <div v-show="comparisonMode === 'traffic' && selectedTrafficRouterIds.length > 0" class="space-y-4">
                    <!-- COMBINED VIEW (Single Shared ECharts Graph) -->
                    <div v-show="trafficViewMode === 'combined'">
                        <MultiRouterTrafficCombined
                            :streams="streams"
                            :selected-router-ids="selectedTrafficRouterIds"
                        />
                    </div>

                    <!-- Helper Hint for Reordering -->
                    <div v-show="trafficViewMode === 'grid'" class="flex items-center justify-between text-xs text-zinc-500">
                        <span class="flex items-center gap-1.5">
                            <span class="font-semibold text-indigo-600 dark:text-indigo-400">💡 Tip Tata Letak:</span>
                            <span>Tahan &amp; geser ikon ⠿ atau klik tombol ◀ ▶ pada kartu untuk mengatur urutan dan membandingkan router berdampingan.</span>
                        </span>
                    </div>

                    <!-- GRID VIEW (Side-by-side Multi-Cards with Drag & Drop Reordering) -->
                    <!-- Kept mounted with v-show so background polling continues to feed streams for combined view -->
                    <div
                        v-show="trafficViewMode === 'grid'"
                        class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3"
                    >
                        <div
                            v-for="(id, index) in selectedTrafficRouterIds"
                            :key="id"
                            class="transition-all duration-200"
                            :class="{
                                'opacity-40 scale-95 border-2 border-dashed border-indigo-400 rounded-xl': draggedIndex === index,
                                'ring-2 ring-indigo-500 ring-offset-2 rounded-xl scale-[1.01] shadow-md': dragOverIndex === index && draggedIndex !== index,
                            }"
                            draggable="true"
                            @dragstart="handleDragStart(index, $event)"
                            @dragover="handleDragOver(index, $event)"
                            @dragenter="dragOverIndex = index"
                            @drop="handleDrop(index)"
                            @dragend="handleDragEnd"
                        >
                            <MultiRouterTrafficCard
                                v-if="routers.find((r) => r.id === id)"
                                :router-id="id"
                                :router-name="routers.find((r) => r.id === id)!.name"
                                :router-host="routers.find((r) => r.id === id)!.host"
                                :poll-interval-ms="trafficPollInterval"
                                :is-global-paused="shouldPauseTraffic"
                                :can-move-left="index > 0"
                                :can-move-right="index < selectedTrafficRouterIds.length - 1"
                                @move-left="moveRouterPosition(index, 'left')"
                                @move-right="moveRouterPosition(index, 'right')"
                                @remove="removeTrafficRouter"
                                @traffic-update="onTrafficUpdate"
                            />
                        </div>
                    </div>
                </div>

                <!-- ============================================================== -->
                <!-- VIEW 2: PPPOE & HOTSPOT LIVE COMPARISON (DIRECT ROUTEROS API)   -->
                <!-- ============================================================== -->
                <div v-if="comparisonMode === 'pppoe_hotspot' && selectedTrafficRouterIds.length > 0" class="space-y-4">
                    <!-- Fleet PPPoE & Hotspot KPI Summary -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <!-- PPPoE KPI -->
                        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-medium text-zinc-500">Total PPPoE Aktif Fleet</span>
                                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                                    RouterOS /ppp/active
                                </span>
                            </div>
                            <div class="mt-2 flex items-baseline gap-2">
                                <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                    {{ fleetPppoeHotspotSummary.totalPppActive }}
                                </span>
                                <span class="text-xs text-zinc-400">
                                    dari {{ fleetPppoeHotspotSummary.totalPppSecrets }} secret akun
                                </span>
                            </div>
                            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div
                                    class="h-full rounded-full bg-blue-500 transition-all duration-500"
                                    :style="{
                                        width: `${fleetPppoeHotspotSummary.totalPppSecrets > 0
                                            ? Math.min(100, Math.round((fleetPppoeHotspotSummary.totalPppActive / fleetPppoeHotspotSummary.totalPppSecrets) * 100))
                                            : 0}%`
                                    }"
                                />
                            </div>
                        </div>

                        <!-- Hotspot KPI -->
                        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-medium text-zinc-500">Total Hotspot Aktif Fleet</span>
                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                                    RouterOS /ip/hotspot/active
                                </span>
                            </div>
                            <div class="mt-2 flex items-baseline gap-2">
                                <span class="text-2xl font-bold text-amber-600 dark:text-amber-400">
                                    {{ fleetPppoeHotspotSummary.totalHotspotActive }}
                                </span>
                                <span class="text-xs text-zinc-400">
                                    dari {{ fleetPppoeHotspotSummary.totalHotspotUsers }} voucher/user
                                </span>
                            </div>
                            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div
                                    class="h-full rounded-full bg-amber-500 transition-all duration-500"
                                    :style="{
                                        width: `${fleetPppoeHotspotSummary.totalHotspotUsers > 0
                                            ? Math.min(100, Math.round((fleetPppoeHotspotSummary.totalHotspotActive / fleetPppoeHotspotSummary.totalHotspotUsers) * 100))
                                            : 0}%`
                                    }"
                                />
                            </div>
                        </div>

                        <!-- Combined Active KPI -->
                        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-medium text-zinc-500">Total Sesi Pelanggan Aktif</span>
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                    Live Terkoneksi
                                </span>
                            </div>
                            <div class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                                {{ fleetPppoeHotspotSummary.totalActiveUsers }} Sesi
                            </div>
                            <div class="mt-1 flex items-center justify-between text-[11px] text-zinc-400">
                                <span>PPPoE: {{ fleetPppoeHotspotSummary.totalPppActive }}</span>
                                <span>•</span>
                                <span>Hotspot: {{ fleetPppoeHotspotSummary.totalHotspotActive }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Cards per Router with Drag & Drop Reordering -->
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
                        <div
                            v-for="(r, index) in selectedRoutersList"
                            :key="`pppoe-hotspot-${r.id}`"
                            class="flex flex-col justify-between rounded-xl border border-zinc-200 bg-white p-5 shadow-xs transition hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700"
                            :class="{
                                'opacity-40 scale-95 border-2 border-dashed border-indigo-400': draggedIndex === index,
                                'ring-2 ring-indigo-500 ring-offset-2 scale-[1.01] shadow-md': dragOverIndex === index && draggedIndex !== index,
                            }"
                            draggable="true"
                            @dragstart="handleDragStart(index, $event)"
                            @dragover="handleDragOver(index, $event)"
                            @dragenter="dragOverIndex = index"
                            @drop="handleDrop(index)"
                            @dragend="handleDragEnd"
                        >
                            <div>
                                <!-- Router Header -->
                                <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                                    <div class="flex items-center gap-2">
                                        <span class="cursor-grab text-zinc-300 hover:text-zinc-600 dark:text-zinc-600 dark:hover:text-zinc-300" title="Geser posisi kartu">⠿</span>
                                        <StatusDot :color="r.status_color" :pulse="r.status === 'online'" />
                                        <div>
                                            <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ r.name }}</h4>
                                            <span class="font-mono text-xs text-zinc-400">{{ r.host }}</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <button
                                            v-if="index > 0"
                                            type="button"
                                            class="rounded p-1 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                            title="Geser posisi kartu ke kiri"
                                            @click="moveRouterPosition(index, 'left')"
                                        >
                                            ◀
                                        </button>
                                        <button
                                            v-if="index < selectedRoutersList.length - 1"
                                            type="button"
                                            class="rounded p-1 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                            title="Geser posisi kartu ke kanan"
                                            @click="moveRouterPosition(index, 'right')"
                                        >
                                            ▶
                                        </button>
                                        <span class="rounded bg-indigo-50 px-2 py-0.5 font-mono text-[11px] font-semibold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                            {{ (r.ppp?.active ?? 0) + (r.hotspot?.active ?? 0) }} Aktif
                                        </span>
                                    </div>
                                </div>

                                <!-- Proportion Bar: PPPoE vs Hotspot -->
                                <div class="mt-4">
                                    <div class="flex items-center justify-between text-xs font-medium">
                                        <span class="flex items-center gap-1 text-blue-600 dark:text-blue-400">
                                            <span class="inline-block h-2 w-2 rounded-full bg-blue-500"></span>
                                            PPPoE ({{ r.ppp?.active ?? 0 }})
                                        </span>
                                        <span class="flex items-center gap-1 text-amber-600 dark:text-amber-400">
                                            <span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>
                                            Hotspot ({{ r.hotspot?.active ?? 0 }})
                                        </span>
                                    </div>
                                    <div class="mt-1.5 flex h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div
                                            class="h-full bg-blue-500 transition-all duration-500"
                                            :style="{
                                                width: `${((r.ppp?.active ?? 0) + (r.hotspot?.active ?? 0)) > 0
                                                    ? ((r.ppp?.active ?? 0) / ((r.ppp?.active ?? 0) + (r.hotspot?.active ?? 0))) * 100
                                                    : 50}%`
                                            }"
                                        />
                                        <div
                                            class="h-full bg-amber-500 transition-all duration-500"
                                            :style="{
                                                width: `${((r.ppp?.active ?? 0) + (r.hotspot?.active ?? 0)) > 0
                                                    ? ((r.hotspot?.active ?? 0) / ((r.ppp?.active ?? 0) + (r.hotspot?.active ?? 0))) * 100
                                                    : 50}%`
                                            }"
                                        />
                                    </div>
                                </div>

                                <!-- Two-Column Stats Box: PPPoE vs Hotspot -->
                                <div class="mt-4 grid grid-cols-2 gap-3 text-xs">
                                    <!-- PPPoE Box -->
                                    <div class="rounded-lg border border-blue-100 bg-blue-50/50 p-3 dark:border-blue-900/50 dark:bg-blue-950/20">
                                        <div class="flex items-center justify-between">
                                            <span class="font-bold text-blue-800 dark:text-blue-300">PPPoE</span>
                                            <span class="text-[10px] text-blue-500 font-medium">Rumahan</span>
                                        </div>
                                        <div class="mt-2 text-xl font-bold text-blue-700 dark:text-blue-400">
                                            {{ r.ppp?.active ?? 0 }}
                                        </div>
                                        <div class="mt-0.5 text-[11px] text-blue-600/80 dark:text-blue-400/80">
                                            dari {{ r.ppp?.secrets ?? 0 }} secret akun
                                        </div>
                                        <div class="mt-2 pt-2 border-t border-blue-100/60 dark:border-blue-900/40">
                                            <Link
                                                :href="route('routers.ppp.index', r.id)"
                                                class="inline-flex items-center gap-1 font-semibold text-[11px] text-blue-700 hover:underline dark:text-blue-300"
                                            >
                                                Kelola PPP →
                                            </Link>
                                        </div>
                                    </div>

                                    <!-- Hotspot Box -->
                                    <div class="rounded-lg border border-amber-100 bg-amber-50/50 p-3 dark:border-amber-900/50 dark:bg-amber-950/20">
                                        <div class="flex items-center justify-between">
                                            <span class="font-bold text-amber-800 dark:text-amber-300">Hotspot</span>
                                            <span class="text-[10px] text-amber-500 font-medium">Voucher</span>
                                        </div>
                                        <div class="mt-2 text-xl font-bold text-amber-700 dark:text-amber-400">
                                            {{ r.hotspot?.active ?? 0 }}
                                        </div>
                                        <div class="mt-0.5 text-[11px] text-amber-600/80 dark:text-amber-400/80">
                                            dari {{ r.hotspot?.users ?? 0 }} voucher/user
                                        </div>
                                        <div class="mt-2 pt-2 border-t border-amber-100/60 dark:border-amber-900/40">
                                            <Link
                                                :href="route('routers.hotspot.index', r.id)"
                                                class="inline-flex items-center gap-1 font-semibold text-[11px] text-amber-700 hover:underline dark:text-amber-300"
                                            >
                                                Kelola Hotspot →
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Footer -->
                            <div class="mt-4 flex items-center justify-between border-t border-zinc-100 pt-3 text-[11px] text-zinc-400 dark:border-zinc-800">
                                <span>Model: {{ r.hardware_model ?? 'MikroTik' }}</span>
                                <span>Sync: {{ r.recorded_at ?? 'Baru saja' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================== -->
                <!-- VIEW 3: HARDWARE RESOURCES (CPU & RAM)                          -->
                <!-- ============================================================== -->
                <div v-if="comparisonMode === 'resources' && selectedTrafficRouterIds.length > 0" class="space-y-4">
                    <!-- Fleet Resources KPI Cards -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <span class="text-xs font-medium text-zinc-500">Rata-rata CPU Fleet</span>
                            <div class="mt-1 text-2xl font-bold text-indigo-600 dark:text-indigo-400">
                                {{ resourcesSummary.avgCpu !== null ? `${resourcesSummary.avgCpu}%` : '—' }}
                            </div>
                            <span class="text-[11px] text-zinc-400">Rerata pemakaian prosesor router terpilih</span>
                        </div>
                        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <span class="text-xs font-medium text-zinc-500">Rata-rata RAM Fleet</span>
                            <div class="mt-1 text-2xl font-bold text-teal-600 dark:text-teal-400">
                                {{ resourcesSummary.avgMem !== null ? `${resourcesSummary.avgMem}%` : '—' }}
                            </div>
                            <span class="text-[11px] text-zinc-400">Rerata pemakaian memori</span>
                        </div>
                        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <span class="text-xs font-medium text-zinc-500">Router CPU Tertinggi</span>
                            <div class="mt-1 truncate text-lg font-bold text-amber-600 dark:text-amber-400">
                                {{ resourcesSummary.highestCpuRouter ? `${resourcesSummary.highestCpuRouter.name} (${resourcesSummary.highestCpuRouter.cpu_load}%)` : '—' }}
                            </div>
                            <span class="text-[11px] text-zinc-400">Potensi bottleneck atau proses berat</span>
                        </div>
                    </div>

                    <!-- Cards per Router with Drag & Drop Reordering -->
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
                        <div
                            v-for="(r, index) in selectedRoutersList"
                            :key="`res-${r.id}`"
                            class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs transition hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700"
                            :class="{
                                'opacity-40 scale-95 border-2 border-dashed border-indigo-400': draggedIndex === index,
                                'ring-2 ring-indigo-500 ring-offset-2 scale-[1.01] shadow-md': dragOverIndex === index && draggedIndex !== index,
                            }"
                            draggable="true"
                            @dragstart="handleDragStart(index, $event)"
                            @dragover="handleDragOver(index, $event)"
                            @dragenter="dragOverIndex = index"
                            @drop="handleDrop(index)"
                            @dragend="handleDragEnd"
                        >
                            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2">
                                    <span class="cursor-grab text-zinc-300 hover:text-zinc-600 dark:text-zinc-600 dark:hover:text-zinc-300" title="Geser kartu">⠿</span>
                                    <StatusDot :color="r.status_color" :pulse="r.status === 'online'" />
                                    <div>
                                        <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ r.name }}</h4>
                                        <span class="font-mono text-xs text-zinc-400">{{ r.host }}</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <button
                                        v-if="index > 0"
                                        type="button"
                                        class="rounded p-1 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                        title="Geser posisi kartu ke kiri"
                                        @click="moveRouterPosition(index, 'left')"
                                    >
                                        ◀
                                    </button>
                                    <button
                                        v-if="index < selectedRoutersList.length - 1"
                                        type="button"
                                        class="rounded p-1 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                        title="Geser posisi kartu ke kanan"
                                        @click="moveRouterPosition(index, 'right')"
                                    >
                                        ▶
                                    </button>
                                    <span class="rounded bg-zinc-100 px-2 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ r.architecture ?? 'mips/arm' }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-4 space-y-4 text-xs">
                                <!-- CPU Load Meter -->
                                <div>
                                    <div class="flex items-center justify-between font-medium">
                                        <span class="text-zinc-500">CPU Load</span>
                                        <div class="flex items-center gap-1.5">
                                            <span
                                                v-if="(r.cpu_load ?? 0) >= 80"
                                                class="rounded bg-rose-100 px-1.5 py-0.2 text-[10px] font-bold text-rose-700 dark:bg-rose-950 dark:text-rose-300"
                                            >
                                                ⚠️ Beban Tinggi
                                            </span>
                                            <span
                                                class="font-bold text-sm"
                                                :class="(r.cpu_load ?? 0) >= 80
                                                    ? 'text-rose-600 dark:text-rose-400'
                                                    : (r.cpu_load ?? 0) >= 50
                                                        ? 'text-amber-600 dark:text-amber-400'
                                                        : 'text-emerald-600 dark:text-emerald-400'"
                                            >
                                                {{ r.cpu_load !== null ? `${r.cpu_load}%` : '—' }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div
                                            class="h-full rounded-full transition-all duration-500"
                                            :class="(r.cpu_load ?? 0) >= 80 ? 'bg-rose-500' : (r.cpu_load ?? 0) >= 50 ? 'bg-amber-500' : 'bg-emerald-500'"
                                            :style="{ width: `${Math.min(100, Math.max(0, r.cpu_load ?? 0))}%` }"
                                        />
                                    </div>
                                </div>

                                <!-- RAM Usage Meter -->
                                <div>
                                    <div class="flex items-center justify-between font-medium">
                                        <span class="text-zinc-500">Memori (RAM)</span>
                                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">
                                            {{ fmtBytes(r.mem_used) }} / {{ fmtBytes(r.mem_total) }}
                                            <span class="text-zinc-400 font-normal">({{ r.mem_percent ?? 0 }}%)</span>
                                        </span>
                                    </div>
                                    <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div
                                            class="h-full rounded-full bg-teal-500 transition-all duration-500"
                                            :style="{ width: `${Math.min(100, Math.max(0, r.mem_percent ?? 0))}%` }"
                                        />
                                    </div>
                                </div>

                                <!-- Disk Usage Meter -->
                                <div v-if="r.disk_total">
                                    <div class="flex items-center justify-between font-medium">
                                        <span class="text-zinc-500">Storage / Disk</span>
                                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">
                                            {{ fmtBytes(r.disk_used) }} / {{ fmtBytes(r.disk_total) }}
                                        </span>
                                    </div>
                                    <div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <div
                                            class="h-full rounded-full bg-indigo-500 transition-all duration-500"
                                            :style="{ width: `${Math.min(100, Math.max(0, ((r.disk_used ?? 0) / (r.disk_total || 1)) * 100))}%` }"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 flex items-center justify-between border-t border-zinc-100 pt-3 text-[11px] text-zinc-400 dark:border-zinc-800">
                                <span>Model: {{ r.hardware_model ?? 'MikroTik' }}</span>
                                <span>Update: {{ r.recorded_at ?? 'Baru saja' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================== -->
                <!-- VIEW 4: LATENCY & LINK QUALITY MATRIX                           -->
                <!-- ============================================================== -->
                <div v-if="comparisonMode === 'latency' && selectedTrafficRouterIds.length > 0" class="space-y-4">
                    <!-- Fleet Latency KPI Cards -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <span class="text-xs font-medium text-zinc-500">Router Latensi Tercepat</span>
                            <div class="mt-1 truncate text-lg font-bold text-emerald-600 dark:text-emerald-400">
                                {{ latencyRanking[0]?.latency_ms !== null ? `${latencyRanking[0]?.name} (${latencyRanking[0]?.latency_ms}ms)` : '—' }}
                            </div>
                            <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Respons tautan link paling prima</span>
                        </div>
                        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <span class="text-xs font-medium text-zinc-500">Router Latensi Terlambat</span>
                            <div class="mt-1 truncate text-lg font-bold text-rose-600 dark:text-rose-400">
                                {{ latencyRanking[latencyRanking.length - 1]?.latency_ms !== null ? `${latencyRanking[latencyRanking.length - 1]?.name} (${latencyRanking[latencyRanking.length - 1]?.latency_ms}ms)` : '—' }}
                            </div>
                            <span class="text-[11px] text-zinc-400">Potensi gangguan sinyal atau kongesti radio</span>
                        </div>
                        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <span class="text-xs font-medium text-zinc-500">Rata-rata Latensi Fleet</span>
                            <div class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">
                                {{ kpis.avg_latency !== null ? `${kpis.avg_latency}ms` : '—' }}
                            </div>
                            <span class="text-[11px] text-zinc-400">API round-trip ping rata-rata</span>
                        </div>
                    </div>

                    <!-- Ranked Table / Cards -->
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="border-b border-zinc-100 px-5 py-3 font-semibold text-xs text-zinc-700 dark:border-zinc-800 dark:text-zinc-300">
                            Peringkat Kualitas Link &amp; Latensi (Tercepat ke Terlambat)
                        </div>
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <div
                                v-for="(r, idx) in latencyRanking"
                                :key="`lat-${r.id}`"
                                class="flex flex-wrap items-center justify-between gap-4 px-5 py-3 text-xs transition hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40"
                            >
                                <div class="flex items-center gap-3">
                                    <span
                                        class="flex h-6 w-6 items-center justify-center rounded-full font-bold text-xs"
                                        :class="idx === 0
                                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                                            : idx === 1
                                                ? 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                                                : idx === 2
                                                    ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400'
                                                    : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400'"
                                    >
                                        #{{ idx + 1 }}
                                    </span>
                                    <StatusDot :color="r.status_color" :pulse="r.status === 'online'" />
                                    <div>
                                        <span class="font-bold text-zinc-900 dark:text-zinc-100">{{ r.name }}</span>
                                        <span class="ml-2 font-mono text-[11px] text-zinc-400">{{ r.host }}</span>
                                    </div>
                                </div>

                                <div class="flex items-center gap-4">
                                    <!-- Latency Bar Gauge -->
                                    <div class="hidden w-32 sm:block">
                                        <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                            <div
                                                class="h-full rounded-full transition-all duration-300"
                                                :class="(r.latency_ms ?? 999) < 25
                                                    ? 'bg-emerald-500'
                                                    : (r.latency_ms ?? 999) < 60
                                                        ? 'bg-teal-500'
                                                        : (r.latency_ms ?? 999) < 120
                                                            ? 'bg-amber-500'
                                                            : 'bg-rose-500'"
                                                :style="{ width: `${Math.min(100, Math.max(5, ((r.latency_ms ?? 150) / 150) * 100))}%` }"
                                            />
                                        </div>
                                    </div>

                                    <!-- Latency badge -->
                                    <div class="text-right">
                                        <span
                                            v-if="r.latency_ms !== null"
                                            class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold"
                                            :class="r.latency_ms < 25
                                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                                : r.latency_ms < 60
                                                    ? 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-300'
                                                    : r.latency_ms < 120
                                                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                                                        : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'"
                                        >
                                            {{ r.latency_ms }} ms
                                            <span class="text-[10px] font-normal opacity-75">
                                                {{ r.latency_ms < 25 ? 'Sangat Cepat' : r.latency_ms < 60 ? 'Stabil' : r.latency_ms < 120 ? 'Sedang' : 'Tinggi' }}
                                            </span>
                                        </span>
                                        <span
                                            v-else
                                            class="inline-block rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400"
                                        >
                                            RTO / Offline
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================== -->
                <!-- VIEW 5: HARDWARE, ROUTEROS & UPTIME                             -->
                <!-- ============================================================== -->
                <div v-if="comparisonMode === 'hardware' && selectedTrafficRouterIds.length > 0" class="space-y-4">
                    <!-- Cards per Router with Drag & Drop Reordering -->
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
                        <div
                            v-for="(r, index) in selectedRoutersList"
                            :key="`hw-${r.id}`"
                            class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs transition hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700"
                            :class="{
                                'opacity-40 scale-95 border-2 border-dashed border-indigo-400': draggedIndex === index,
                                'ring-2 ring-indigo-500 ring-offset-2 scale-[1.01] shadow-md': dragOverIndex === index && draggedIndex !== index,
                            }"
                            draggable="true"
                            @dragstart="handleDragStart(index, $event)"
                            @dragover="handleDragOver(index, $event)"
                            @dragenter="dragOverIndex = index"
                            @drop="handleDrop(index)"
                            @dragend="handleDragEnd"
                        >
                            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2">
                                    <span class="cursor-grab text-zinc-300 hover:text-zinc-600 dark:text-zinc-600 dark:hover:text-zinc-300" title="Geser kartu">⠿</span>
                                    <StatusDot :color="r.status_color" :pulse="r.status === 'online'" />
                                    <div>
                                        <h4 class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ r.name }}</h4>
                                        <span class="font-mono text-xs text-zinc-400">{{ r.host }}</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <button
                                        v-if="index > 0"
                                        type="button"
                                        class="rounded p-1 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                        title="Geser posisi kartu ke kiri"
                                        @click="moveRouterPosition(index, 'left')"
                                    >
                                        ◀
                                    </button>
                                    <button
                                        v-if="index < selectedRoutersList.length - 1"
                                        type="button"
                                        class="rounded p-1 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                        title="Geser posisi kartu ke kanan"
                                        @click="moveRouterPosition(index, 'right')"
                                    >
                                        ▶
                                    </button>
                                    <span class="rounded bg-indigo-50 px-2 py-0.5 text-xs font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                        {{ r.status }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-4 space-y-2.5 text-xs">
                                <div class="flex items-center justify-between">
                                    <span class="text-zinc-500">Model Perangkat:</span>
                                    <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ r.hardware_model ?? 'MikroTik Router' }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-zinc-500">Versi RouterOS:</span>
                                    <span class="rounded bg-zinc-100 px-2 py-0.5 font-mono font-medium text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                        {{ r.routeros_version ?? 'RouterOS' }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-zinc-500">Arsitektur CPU:</span>
                                    <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ r.architecture ?? 'mips/arm' }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-zinc-500">Uptime Sistem:</span>
                                    <div class="flex items-center gap-1.5">
                                        <span
                                            v-if="r.uptime && (r.uptime.includes('h') || r.uptime.includes('m')) && !r.uptime.includes('d') && !r.uptime.includes('w')"
                                            class="rounded bg-amber-100 px-1.5 py-0.2 text-[10px] font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                                        >
                                            Baru Reboot
                                        </span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ r.uptime ?? '—' }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5 flex items-center justify-between border-t border-zinc-100 pt-3 text-xs dark:border-zinc-800">
                                <span class="text-[11px] text-zinc-400">ID #{{ r.id }}</span>
                                <Link
                                    :href="route('routers.show', r.id)"
                                    class="font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                >
                                    Detail Router →
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================================== -->
            <!-- TAB 2: FLEET TELEMETRY & HEALTH                                -->
            <!-- ============================================================== -->
            <div v-show="activeTab === 'telemetry'" class="space-y-6">
                <!-- Header with actions and auto-refresh -->
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">Metrik Histori &amp; Kesehatan Fleet</h3>
                        <p class="text-xs text-zinc-500">Data agregat utilisasi CPU, konsumsi memori, dan latency ping MikroTik dari waktu ke waktu.</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Auto refresh picker -->
                        <div class="flex items-center rounded-lg border border-zinc-200 bg-white p-1 text-xs shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <span class="px-2 text-zinc-400 font-medium">Auto-refresh:</span>
                            <button
                                v-for="sec in [0, 15, 30, 60]"
                                :key="sec"
                                type="button"
                                class="rounded px-2 py-1 font-medium transition-colors"
                                :class="autoRefreshInterval === sec
                                    ? 'bg-indigo-600 text-white'
                                    : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                                @click="setAutoRefresh(sec)"
                            >
                                {{ sec === 0 ? 'Off' : `${sec}s` }}
                            </button>
                        </div>

                        <Btn
                            v-if="selectedRouter"
                            variant="secondary"
                            size="sm"
                            :disabled="isCollecting"
                            @click="collectSelected"
                        >
                            ⚡ Collect Selected
                        </Btn>

                        <Btn
                            variant="primary"
                            size="sm"
                            :disabled="isCollecting"
                            @click="collectAll"
                        >
                            ↻ Collect Fleet
                        </Btn>
                    </div>
                </div>

                <!-- KPI Cards Grid -->
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                        <span class="text-xs font-medium text-zinc-500">Total Routers</span>
                        <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ kpis.total }}</div>
                        <span class="text-[11px] text-zinc-400">Inventory size</span>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                        <span class="text-xs font-medium text-zinc-500">Online Fleet</span>
                        <div class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                            {{ kpis.online }} <span class="text-xs font-normal text-zinc-400">/ {{ kpis.total }}</span>
                        </div>
                        <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Reachable devices</span>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                        <span class="text-xs font-medium text-zinc-500">Unreachable</span>
                        <div class="mt-1 text-2xl font-bold" :class="kpis.unreachable > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-700 dark:text-zinc-300'">
                            {{ kpis.unreachable }}
                        </div>
                        <span class="text-[11px]" :class="kpis.unreachable > 0 ? 'text-rose-500' : 'text-zinc-400'">Needs attention</span>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                        <span class="text-xs font-medium text-zinc-500">Avg Fleet CPU</span>
                        <div class="mt-1 text-2xl font-bold text-indigo-600 dark:text-indigo-400">
                            {{ kpis.avg_cpu !== null ? `${kpis.avg_cpu}%` : '—' }}
                        </div>
                        <span class="text-[11px] text-zinc-400">Core utilization</span>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                        <span class="text-xs font-medium text-zinc-500">Avg Fleet RAM</span>
                        <div class="mt-1 text-2xl font-bold text-teal-600 dark:text-teal-400">
                            {{ kpis.avg_mem !== null ? `${kpis.avg_mem}%` : '—' }}
                        </div>
                        <span class="text-[11px] text-zinc-400">Memory usage</span>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                        <span class="text-xs font-medium text-zinc-500">Avg Latency</span>
                        <div class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">
                            {{ kpis.avg_latency !== null ? `${kpis.avg_latency}ms` : '—' }}
                        </div>
                        <span class="text-[11px] text-zinc-400">API round-trip</span>
                    </div>
                </div>

                <!-- Telemetry Chart Card -->
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                Telemetri Histori: <span class="text-indigo-600 dark:text-indigo-400">{{ selectedRouterName }}</span>
                            </span>
                            <!-- Sub-tabs for chart type -->
                            <div class="flex rounded-lg border border-zinc-200 bg-zinc-50 p-0.5 text-xs dark:border-zinc-800 dark:bg-zinc-800/60">
                                <button
                                    type="button"
                                    class="rounded-md px-2.5 py-1 font-medium transition-colors"
                                    :class="activeChartTab === 'system'
                                        ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                                    @click="activeChartTab = 'system'"
                                >
                                    CPU &amp; Memory
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md px-2.5 py-1 font-medium transition-colors"
                                    :class="activeChartTab === 'latency'
                                        ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white'
                                        : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                                    @click="activeChartTab = 'latency'"
                                >
                                    Latency (Ping)
                                </button>
                            </div>
                        </div>

                        <!-- Range selector pills -->
                        <div class="flex items-center rounded-lg border border-zinc-200 bg-zinc-50 p-0.5 text-xs dark:border-zinc-800 dark:bg-zinc-800/60">
                            <button
                                v-for="r in ['1h', '6h', '24h', '7d']"
                                :key="r"
                                type="button"
                                class="rounded-md px-2.5 py-1 font-medium transition-colors"
                                :class="range === r
                                    ? 'bg-indigo-600 text-white shadow-xs'
                                    : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                                @click="setRange(r)"
                            >
                                {{ r === '1h' ? '1 Jam' : r === '6h' ? '6 Jam' : r === '24h' ? '24 Jam' : '7 Hari' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="chart.labels.length === 0" class="py-16 text-center text-sm text-zinc-400">
                        Belum ada data metrik untuk router ini. Klik <strong>Collect Selected</strong> untuk mulai merekam data.
                    </div>

                    <div v-else>
                        <!-- System Utilization Chart -->
                        <LineChart
                            v-if="activeChartTab === 'system'"
                            :labels="chart.labels"
                            unit="%"
                            :max="100"
                            :series="[
                                { name: 'CPU %', data: chart.cpu, color: '#6366f1' },
                                { name: 'Memory %', data: chart.mem, color: '#10b981' },
                            ]"
                        />

                        <!-- Latency Chart -->
                        <LineChart
                            v-else
                            :labels="chart.labels"
                            unit="ms"
                            :series="[
                                { name: 'Latency (ms)', data: chart.latency, color: '#f59e0b' },
                            ]"
                        />
                    </div>
                </div>

                <!-- Fleet Live Table -->
                <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50/75 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Router Fleet Telemetry</span>
                            <span class="rounded-full bg-zinc-200/80 px-2 py-0.5 text-[11px] font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                {{ filteredRouters.length }}
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <!-- Status filter tabs -->
                            <div class="flex rounded-lg border border-zinc-200 bg-white p-0.5 text-xs dark:border-zinc-700 dark:bg-zinc-800">
                                <button
                                    type="button"
                                    class="rounded px-2.5 py-1 font-medium transition-colors"
                                    :class="statusFilter === 'all' ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-white' : 'text-zinc-500'"
                                    @click="statusFilter = 'all'"
                                >
                                    All
                                </button>
                                <button
                                    type="button"
                                    class="rounded px-2.5 py-1 font-medium transition-colors"
                                    :class="statusFilter === 'online' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'text-zinc-500'"
                                    @click="statusFilter = 'online'"
                                >
                                    Online
                                </button>
                                <button
                                    type="button"
                                    class="rounded px-2.5 py-1 font-medium transition-colors"
                                    :class="statusFilter === 'unreachable' ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' : 'text-zinc-500'"
                                    @click="statusFilter = 'unreachable'"
                                >
                                    Unreachable
                                </button>
                            </div>

                            <!-- Search box -->
                            <input
                                v-model="searchQuery"
                                type="search"
                                placeholder="Cari router..."
                                class="h-7 w-44 rounded-lg border border-zinc-300 bg-white px-2.5 text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-zinc-200 bg-zinc-50/50 text-xs font-medium text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/40 dark:text-zinc-400">
                                <tr>
                                    <th class="px-4 py-2.5">Router</th>
                                    <th class="px-4 py-2.5">Host / IP</th>
                                    <th class="px-4 py-2.5">Status</th>
                                    <th class="px-4 py-2.5">CPU Load</th>
                                    <th class="px-4 py-2.5">Memory Usage</th>
                                    <th class="px-4 py-2.5">Latency</th>
                                    <th class="px-4 py-2.5">Terakhir Update</th>
                                    <th class="px-4 py-2.5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                <tr v-if="filteredRouters.length === 0">
                                    <td colspan="8" class="px-4 py-8 text-center text-xs text-zinc-500">
                                        Tidak ada router yang sesuai dengan kriteria filter.
                                    </td>
                                </tr>
                                <tr
                                    v-for="r in filteredRouters"
                                    :key="r.id"
                                    class="cursor-pointer transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40"
                                    :class="r.id === selectedRouter ? 'bg-indigo-50/70 font-semibold dark:bg-indigo-950/40' : ''"
                                    @click="selectTelemetryRouter(r.id)"
                                >
                                    <td class="px-4 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <StatusDot :color="r.status_color" :pulse="r.status === 'online'" />
                                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ r.name }}</span>
                                            <span v-if="r.id === selectedRouter" class="rounded bg-indigo-100 px-1.5 py-0.2 text-[10px] text-indigo-700 dark:bg-indigo-900 dark:text-indigo-200">
                                                Aktif di Histori
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ r.host }}</td>
                                    <td class="px-4 py-2.5">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                            :class="r.status === 'online'
                                                ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                : 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'"
                                        >
                                            {{ r.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 tabular-nums">
                                        <span v-if="r.cpu_load !== null" :class="(r.cpu_load ?? 0) > 80 ? 'font-bold text-rose-600' : 'text-zinc-700 dark:text-zinc-300'">
                                            {{ r.cpu_load }}%
                                        </span>
                                        <span v-else class="text-zinc-400">—</span>
                                    </td>
                                    <td class="px-4 py-2.5 tabular-nums">
                                        <span v-if="r.mem_percent !== null" :class="(r.mem_percent ?? 0) > 85 ? 'font-bold text-rose-600' : 'text-zinc-700 dark:text-zinc-300'">
                                            {{ r.mem_percent }}%
                                        </span>
                                        <span v-else class="text-zinc-400">—</span>
                                    </td>
                                    <td class="px-4 py-2.5 tabular-nums">
                                        <span v-if="r.latency_ms !== null" :class="(r.latency_ms ?? 0) > 100 ? 'font-semibold text-amber-600' : 'text-zinc-700 dark:text-zinc-300'">
                                            {{ r.latency_ms }}ms
                                        </span>
                                        <span v-else class="text-zinc-400">—</span>
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-zinc-400">{{ r.recorded_at ?? 'belum ada' }}</td>
                                    <td class="px-4 py-2.5 text-right font-sans">
                                        <div class="flex items-center justify-end gap-2" @click.stop>
                                            <button
                                                type="button"
                                                class="rounded border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-300 dark:hover:bg-indigo-900"
                                                title="Buka Live Traffic untuk router ini"
                                                @click="switchToTrafficWithRouter(r.id)"
                                            >
                                                ⚡ Live Traffic
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950/30"
                                                title="Pilih dan Tampilkan di Grafik Histori"
                                                @click="selectTelemetryRouter(r.id)"
                                            >
                                                Histori
                                            </button>
                                            <Link
                                                :href="route('routers.show', r.id)"
                                                class="rounded px-2 py-1 text-xs text-zinc-600 hover:bg-zinc-100 hover:underline dark:text-zinc-300 dark:hover:bg-zinc-800"
                                            >
                                                Detail →
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
