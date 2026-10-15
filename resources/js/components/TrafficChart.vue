<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { use } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import VChart from 'vue-echarts';

use([LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

interface InterfaceItem {
    name: string;
    type?: string;
    running?: boolean;
    disabled?: boolean;
    comment?: string | null;
}

const props = withDefaults(
    defineProps<{
        routerId: number;
        interfaces?: InterfaceItem[];
        initialInterface?: string;
        heightClass?: string;
    }>(),
    {
        interfaces: () => [],
        initialInterface: 'ether1',
        heightClass: 'h-64',
    }
);

const emit = defineEmits<{
    (e: 'interfaceChange', iface: string): void;
}>();

const selectedInterface = ref<string>(props.initialInterface);
const isPaused = ref(false);
const pollIntervalMs = ref(2000);
const lastError = ref<string | null>(null);

const currentRxBps = ref<number>(0);
const currentTxBps = ref<number>(0);
const currentRxPackets = ref<number>(0);
const currentTxPackets = ref<number>(0);

// Multi-Interface Comparison State
const isComparing = ref(false);
const compareInterface = ref<string>('');
const compareRxBps = ref<number>(0);
const compareTxBps = ref<number>(0);
const compareRxPackets = ref<number>(0);
const compareTxPackets = ref<number>(0);
const compareRxData = ref<number[]>([]);
const compareTxData = ref<number[]>([]);

const MAX_POINTS = 30;
const timestamps = ref<string[]>([]);
const rxData = ref<number[]>([]);
const txData = ref<number[]>([]);

let timer: number | null = null;
let abortController: AbortController | null = null;

function formatSpeed(bps: number): string {
    if (bps >= 1_000_000_000) {
        return (bps / 1_000_000_000).toFixed(2) + ' Gbps';
    }
    if (bps >= 1_000_000) {
        return (bps / 1_000_000).toFixed(2) + ' Mbps';
    }
    if (bps >= 1_000) {
        return (bps / 1_000).toFixed(1) + ' Kbps';
    }
    return Math.round(bps) + ' bps';
}

function formatPackets(pps: number): string {
    if (pps >= 1000) {
        return (pps / 1000).toFixed(1) + 'k p/s';
    }
    return pps + ' p/s';
}

async function fetchTraffic(): Promise<void> {
    if (isPaused.value || !props.routerId || !selectedInterface.value) {
        return;
    }

    abortController?.abort();
    abortController = new AbortController();

    try {
        const primaryUrl = route('routers.interfaces.traffic', {
            router: props.routerId,
            interface: selectedInterface.value,
        });

        const fetchPrimary = fetch(primaryUrl, {
            headers: { Accept: 'application/json' },
            signal: abortController.signal,
        });

        let fetchCompare: Promise<Response> | null = null;
        if (isComparing.value && compareInterface.value && compareInterface.value !== selectedInterface.value) {
            const compareUrl = route('routers.interfaces.traffic', {
                router: props.routerId,
                interface: compareInterface.value,
            });
            fetchCompare = fetch(compareUrl, {
                headers: { Accept: 'application/json' },
                signal: abortController.signal,
            });
        }

        const res = await fetchPrimary;
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const data = await res.json();
        if (data.success) {
            lastError.value = null;
            currentRxBps.value = Number(data.rx_bps ?? 0);
            currentTxBps.value = Number(data.tx_bps ?? 0);
            currentRxPackets.value = Number(data.rx_packets ?? 0);
            currentTxPackets.value = Number(data.tx_packets ?? 0);

            const now = new Date();
            const timeStr = now.toTimeString().split(' ')[0];

            timestamps.value.push(timeStr);
            rxData.value.push(currentRxBps.value);
            txData.value.push(currentTxBps.value);

            if (timestamps.value.length > MAX_POINTS) {
                timestamps.value.shift();
                rxData.value.shift();
                txData.value.shift();
            }
        } else {
            lastError.value = data.error ?? 'Failed to read traffic';
        }

        if (fetchCompare) {
            const compRes = await fetchCompare;
            if (compRes.ok) {
                const compData = await compRes.json();
                if (compData.success) {
                    compareRxBps.value = Number(compData.rx_bps ?? 0);
                    compareTxBps.value = Number(compData.tx_bps ?? 0);
                    compareRxPackets.value = Number(compData.rx_packets ?? 0);
                    compareTxPackets.value = Number(compData.tx_packets ?? 0);

                    compareRxData.value.push(compareRxBps.value);
                    compareTxData.value.push(compareTxBps.value);

                    if (compareRxData.value.length > MAX_POINTS) {
                        compareRxData.value.shift();
                        compareTxData.value.shift();
                    }
                }
            }
        }
    } catch (e: any) {
        if (e.name !== 'AbortError') {
            lastError.value = e.message ?? 'Connection error';
        }
    }
}

function startPolling(): void {
    stopPolling();
    fetchTraffic();
    timer = window.setInterval(fetchTraffic, pollIntervalMs.value);
}

function stopPolling(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
    abortController?.abort();
}

function togglePause(): void {
    isPaused.value = !isPaused.value;
    if (!isPaused.value) {
        fetchTraffic();
    }
}

function toggleCompare(): void {
    isComparing.value = !isComparing.value;
    if (isComparing.value && !compareInterface.value) {
        const candidate = props.interfaces.find((i) => i.name !== selectedInterface.value);
        if (candidate) {
            compareInterface.value = candidate.name;
        }
    }
    compareRxData.value = [];
    compareTxData.value = [];
    if (isComparing.value) {
        fetchTraffic();
    }
}

function onCompareInterfaceSelect(): void {
    compareRxData.value = [];
    compareTxData.value = [];
    fetchTraffic();
}

function onInterfaceSelect(): void {
    emit('interfaceChange', selectedInterface.value);
    timestamps.value = [];
    rxData.value = [];
    txData.value = [];
    compareRxData.value = [];
    compareTxData.value = [];
    currentRxBps.value = 0;
    currentTxBps.value = 0;
    fetchTraffic();
}

function handleVisibility(): void {
    if (document.hidden) {
        stopPolling();
    } else if (!isPaused.value) {
        startPolling();
    }
}

watch(
    () => props.interfaces,
    (newList) => {
        if (newList && newList.length > 0) {
            const exists = newList.some((i) => i.name === selectedInterface.value);
            if (!exists) {
                selectedInterface.value = newList[0].name;
                onInterfaceSelect();
            }
        }
    },
    { immediate: true }
);

watch(
    () => props.routerId,
    () => {
        timestamps.value = [];
        rxData.value = [];
        txData.value = [];
        startPolling();
    }
);

watch(pollIntervalMs, () => {
    if (!isPaused.value) {
        startPolling();
    }
});

onMounted(() => {
    document.addEventListener('visibilitychange', handleVisibility);
    startPolling();
});

onUnmounted(() => {
    document.removeEventListener('visibilitychange', handleVisibility);
    stopPolling();
});

const chartOption = computed(() => {
    const seriesList: any[] = [
        {
            name: isComparing.value ? `${selectedInterface.value} (Rx)` : 'Rx (Download)',
            type: 'line',
            smooth: 0.3,
            showSymbol: false,
            data: rxData.value,
            lineStyle: { color: '#10b981', width: 2 },
            areaStyle: { color: 'rgba(16, 185, 129, 0.15)' },
        },
        {
            name: isComparing.value ? `${selectedInterface.value} (Tx)` : 'Tx (Upload)',
            type: 'line',
            smooth: 0.3,
            showSymbol: false,
            data: txData.value,
            lineStyle: { color: '#6366f1', width: 2 },
            areaStyle: { color: 'rgba(99, 102, 241, 0.15)' },
        },
    ];

    const legendData = isComparing.value
        ? [
              `${selectedInterface.value} (Rx)`,
              `${selectedInterface.value} (Tx)`,
              `${compareInterface.value || 'Compare'} (Rx)`,
              `${compareInterface.value || 'Compare'} (Tx)`,
          ]
        : ['Rx (Download)', 'Tx (Upload)'];

    if (isComparing.value && compareInterface.value) {
        seriesList.push({
            name: `${compareInterface.value} (Rx)`,
            type: 'line',
            smooth: 0.3,
            showSymbol: false,
            data: compareRxData.value,
            lineStyle: { color: '#0ea5e9', width: 2, type: 'dashed' },
            areaStyle: { color: 'rgba(14, 165, 233, 0.12)' },
        });
        seriesList.push({
            name: `${compareInterface.value} (Tx)`,
            type: 'line',
            smooth: 0.3,
            showSymbol: false,
            data: compareTxData.value,
            lineStyle: { color: '#f59e0b', width: 2, type: 'dashed' },
            areaStyle: { color: 'rgba(245, 158, 11, 0.12)' },
        });
    }

    return {
        animation: false,
        tooltip: {
            trigger: 'axis',
            formatter: (params: any[]) => {
                if (!params || !params.length) return '';
                let html = `<div class="font-mono text-xs"><div class="text-zinc-400 mb-1">${params[0].axisValue}</div>`;
                for (const p of params) {
                    html += `<div class="flex items-center justify-between gap-4">
                        <span style="color:${p.color}">● ${p.seriesName}</span>
                        <span class="font-semibold">${formatSpeed(p.value)}</span>
                    </div>`;
                }
                html += '</div>';
                return html;
            },
        },
        legend: {
            data: legendData,
            textStyle: { color: '#a1a1aa', fontSize: 11 },
            top: 0,
            right: 10,
        },
        grid: {
            left: 55,
            right: 16,
            top: 28,
            bottom: 22,
        },
        xAxis: {
            type: 'category',
            data: timestamps.value,
            axisLine: { lineStyle: { color: '#3f3f46' } },
            axisLabel: { color: '#a1a1aa', fontSize: 10 },
        },
        yAxis: {
            type: 'value',
            min: 0,
            splitLine: { lineStyle: { color: 'rgba(161,161,170,0.12)' } },
            axisLabel: {
                color: '#a1a1aa',
                fontSize: 10,
                formatter: (v: number) => formatSpeed(v),
            },
        },
        series: seriesList,
    };
});
</script>

<template>
    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
        <!-- Controls & Status Bar -->
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 pb-3 dark:border-zinc-800">
            <!-- Left: Interface Picker & Live indicator -->
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="h-2.5 w-2.5 rounded-full transition-colors"
                    :class="{
                        'bg-emerald-500 animate-pulse': !isPaused && !lastError,
                        'bg-amber-500': isPaused && !lastError,
                        'bg-red-500': !!lastError,
                    }"
                    :title="lastError ? `Error: ${lastError}` : isPaused ? 'Paused' : 'Live'"
                />
                <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                    Live Traffic
                </span>

                <!-- Primary Interface Selector Dropdown -->
                <select
                    v-if="interfaces && interfaces.length > 0"
                    v-model="selectedInterface"
                    @change="onInterfaceSelect"
                    class="rounded-md border border-zinc-300 bg-zinc-50 px-2.5 py-1 font-mono text-xs font-medium text-zinc-800 focus:border-indigo-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    <option v-for="iface in interfaces" :key="iface.name" :value="iface.name">
                        {{ iface.name }}{{ iface.comment ? ` (${iface.comment})` : '' }}
                    </option>
                </select>
                <input
                    v-else
                    v-model="selectedInterface"
                    @change="onInterfaceSelect"
                    placeholder="interface name (e.g. ether1)"
                    class="w-32 rounded-md border border-zinc-300 bg-zinc-50 px-2 py-1 font-mono text-xs text-zinc-800 focus:border-indigo-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                />

                <!-- Compare Toggle Button -->
                <button
                    type="button"
                    class="flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium transition"
                    :class="isComparing ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300 font-semibold border border-indigo-300 dark:border-indigo-700' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400'"
                    title="Bandingkan dua interface sekaligus (misal WAN vs LAN)"
                    @click="toggleCompare"
                >
                    <span>⚖️ Bandingkan</span>
                </button>

                <!-- Second Compare Interface Dropdown -->
                <div v-if="isComparing" class="flex items-center gap-1.5">
                    <span class="text-xs font-semibold text-zinc-400">vs</span>
                    <select
                        v-if="interfaces && interfaces.length > 0"
                        v-model="compareInterface"
                        @change="onCompareInterfaceSelect"
                        class="rounded-md border border-sky-300 bg-sky-50/50 px-2.5 py-1 font-mono text-xs font-medium text-sky-900 focus:border-sky-500 focus:outline-none dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-200"
                    >
                        <option v-for="iface in interfaces" :key="iface.name" :value="iface.name">
                            {{ iface.name }}{{ iface.comment ? ` (${iface.comment})` : '' }}
                        </option>
                    </select>
                    <input
                        v-else
                        v-model="compareInterface"
                        @change="onCompareInterfaceSelect"
                        placeholder="e.g. ether2"
                        class="w-32 rounded-md border border-sky-300 bg-sky-50/50 px-2 py-1 font-mono text-xs text-sky-900 focus:border-sky-500 focus:outline-none dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-200"
                    />
                </div>
            </div>

            <!-- Right: Live Bandwidth Badges & Controls -->
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <!-- Rx Badge -->
                <div class="flex items-center gap-1.5 rounded-md bg-emerald-50 px-2.5 py-1 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                    <span class="font-semibold">{{ isComparing ? `${selectedInterface} Rx:` : 'Rx:' }}</span>
                    <span class="font-mono font-medium">{{ formatSpeed(currentRxBps) }}</span>
                    <span class="text-[10px] opacity-75">({{ formatPackets(currentRxPackets) }})</span>
                </div>

                <!-- Tx Badge -->
                <div class="flex items-center gap-1.5 rounded-md bg-indigo-50 px-2.5 py-1 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300">
                    <span class="font-semibold">{{ isComparing ? `${selectedInterface} Tx:` : 'Tx:' }}</span>
                    <span class="font-mono font-medium">{{ formatSpeed(currentTxBps) }}</span>
                    <span class="text-[10px] opacity-75">({{ formatPackets(currentTxPackets) }})</span>
                </div>

                <!-- Compare Badges -->
                <template v-if="isComparing && compareInterface">
                    <div class="flex items-center gap-1.5 rounded-md border border-sky-200 bg-sky-50 px-2.5 py-1 text-sky-700 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300">
                        <span class="font-semibold">{{ compareInterface }} Rx:</span>
                        <span class="font-mono font-medium">{{ formatSpeed(compareRxBps) }}</span>
                    </div>

                    <div class="flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-amber-700 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                        <span class="font-semibold">{{ compareInterface }} Tx:</span>
                        <span class="font-mono font-medium">{{ formatSpeed(compareTxBps) }}</span>
                    </div>
                </template>

                <!-- Interval selector -->
                <select
                    v-model="pollIntervalMs"
                    class="rounded border border-zinc-200 bg-zinc-50 px-1.5 py-1 text-[11px] text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400"
                    title="Polling Interval"
                >
                    <option :value="1000">1s</option>
                    <option :value="2000">2s</option>
                    <option :value="5000">5s</option>
                </select>

                <!-- Pause/Resume button -->
                <button
                    type="button"
                    @click="togglePause"
                    class="rounded border border-zinc-300 px-2 py-1 text-[11px] font-medium transition hover:bg-zinc-100 dark:border-zinc-700 dark:hover:bg-zinc-800"
                    :class="isPaused ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-600 dark:text-zinc-400'"
                >
                    {{ isPaused ? 'Resume' : 'Pause' }}
                </button>
            </div>
        </div>

        <!-- Error alert if traffic fetch failed -->
        <div v-if="lastError" class="mt-2 text-xs text-red-500 dark:text-red-400">
            ⚠ {{ lastError }}
        </div>

        <!-- ECharts chart -->
        <div class="mt-2" :class="heightClass">
            <VChart :option="chartOption" autoresize class="h-full w-full" />
        </div>
    </div>
</template>

