<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router as inertia } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';
import StatusDot from '@/components/ui/StatusDot.vue';
import LineChart from '@/components/LineChart.vue';

interface Row {
    id: number;
    name: string;
    host: string;
    status: string;
    status_color: string;
    cpu_load: number | null;
    mem_percent: number | null;
    latency_ms: number | null;
    recorded_at: string | null;
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
    selectedRouterName: string;
    range: string;
    kpis: Kpis;
    chart: {
        labels: (string | null)[];
        cpu: (number | null)[];
        mem: (number | null)[];
        latency: (number | null)[];
    };
}>();

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

function select(id: number) {
    inertia.get(
        route('monitoring.index'),
        { router: id, range: props.range },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function setRange(range: string) {
    inertia.get(
        route('monitoring.index'),
        { router: props.selectedRouter, range },
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
    <Head title="Monitoring" />
    <AppLayout title="Telemetry & Monitoring">
        <div class="w-full space-y-6">
            <!-- Header with actions and auto-refresh -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">Fleet Telemetry &amp; Health</h2>
                    <p class="text-xs text-zinc-500">Live CPU, memory, and ping latency metrics across your MikroTik infrastructure.</p>
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
                            Telemetri: <span class="text-indigo-600 dark:text-indigo-400">{{ selectedRouterName }}</span>
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
                                @click="select(r.id)"
                            >
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <StatusDot :color="r.status_color" :pulse="r.status === 'online'" />
                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ r.name }}</span>
                                        <span v-if="r.id === selectedRouter" class="rounded bg-indigo-100 px-1.5 py-0.2 text-[10px] text-indigo-700 dark:bg-indigo-900 dark:text-indigo-200">
                                            Aktif di Grafik
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
                                            class="rounded px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950/30"
                                            title="Pilih dan Tampilkan di Grafik"
                                            @click="select(r.id)"
                                        >
                                            Grafik
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
    </AppLayout>
</template>
