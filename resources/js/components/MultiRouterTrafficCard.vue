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

export interface LiveTrafficPayload {
    routerId: number;
    routerName: string;
    interfaceName: string;
    rxBps: number;
    txBps: number;
    rxPackets: number;
    txPackets: number;
    timestamp: string;
    isError: boolean;
}

const props = withDefaults(
    defineProps<{
        routerId: number;
        routerName: string;
        routerHost: string;
        pollIntervalMs?: number;
        isGlobalPaused?: boolean;
        heightClass?: string;
    }>(),
    {
        pollIntervalMs: 2000,
        isGlobalPaused: false,
        heightClass: 'h-48',
    }
);

const emit = defineEmits<{
    (e: 'remove', routerId: number): void;
    (e: 'trafficUpdate', payload: LiveTrafficPayload): void;
}>();

const interfaces = ref<InterfaceItem[]>([]);
const selectedInterface = ref<string>('ether1');
const isLoadingInterfaces = ref(false);
const isPaused = ref(false);
const lastError = ref<string | null>(null);
const capacityMbps = ref<number | null>(null);
const chartRef = ref<any>(null);

const currentRxBps = ref<number>(0);
const currentTxBps = ref<number>(0);
const currentRxPackets = ref<number>(0);
const currentTxPackets = ref<number>(0);
const peakRxBps = ref<number>(0);
const peakTxBps = ref<number>(0);

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

async function loadInterfaces(): Promise<void> {
    isLoadingInterfaces.value = true;
    try {
        const res = await fetch(route('routers.interfaces.index', props.routerId), {
            headers: { Accept: 'application/json' },
        });
        if (res.ok) {
            const json = await res.json();
            const list: InterfaceItem[] = json.interfaces ?? [];
            interfaces.value = list;

            if (list.length > 0) {
                // Check if cached selection exists in localStorage
                const savedKey = `mikweb:traffic:iface:${props.routerId}`;
                const saved = localStorage.getItem(savedKey);
                const hasSaved = saved && list.some((i) => i.name === saved);

                if (hasSaved) {
                    selectedInterface.value = saved;
                } else {
                    // Prefer first running interface, otherwise first available
                    const firstRunning = list.find((i) => i.running && !i.disabled);
                    selectedInterface.value = firstRunning ? firstRunning.name : list[0].name;
                }
            }
        }
    } catch {
        // Fallback to default ether1 if loading interfaces fails
        interfaces.value = [];
    } finally {
        isLoadingInterfaces.value = false;
    }
}

async function fetchTraffic(): Promise<void> {
    if (isPaused.value || props.isGlobalPaused || !props.routerId || !selectedInterface.value) {
        return;
    }

    abortController?.abort();
    abortController = new AbortController();

    try {
        const url = route('routers.interfaces.traffic', {
            router: props.routerId,
            interface: selectedInterface.value,
        });

        const res = await fetch(url, {
            headers: { Accept: 'application/json' },
            signal: abortController.signal,
        });

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

            if (currentRxBps.value > peakRxBps.value) {
                peakRxBps.value = currentRxBps.value;
            }
            if (currentTxBps.value > peakTxBps.value) {
                peakTxBps.value = currentTxBps.value;
            }

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

            emit('trafficUpdate', {
                routerId: props.routerId,
                routerName: props.routerName,
                interfaceName: selectedInterface.value,
                rxBps: currentRxBps.value,
                txBps: currentTxBps.value,
                rxPackets: currentRxPackets.value,
                txPackets: currentTxPackets.value,
                timestamp: timeStr,
                isError: false,
            });
        } else {
            lastError.value = data.error ?? 'Gagal membaca traffic';
            emit('trafficUpdate', {
                routerId: props.routerId,
                routerName: props.routerName,
                interfaceName: selectedInterface.value,
                rxBps: 0,
                txBps: 0,
                rxPackets: 0,
                txPackets: 0,
                timestamp: '',
                isError: true,
            });
        }
    } catch (e: any) {
        if (e.name !== 'AbortError') {
            lastError.value = e.message ?? 'Gagal terhubung ke router';
        }
    }
}

function startPolling(): void {
    stopPolling();
    fetchTraffic();
    timer = window.setInterval(fetchTraffic, props.pollIntervalMs);
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

function onInterfaceChange(): void {
    localStorage.setItem(`mikweb:traffic:iface:${props.routerId}`, selectedInterface.value);
    timestamps.value = [];
    rxData.value = [];
    txData.value = [];
    currentRxBps.value = 0;
    currentTxBps.value = 0;
    peakRxBps.value = 0;
    peakTxBps.value = 0;
    fetchTraffic();
}

function handleVisibility(): void {
    if (document.hidden) {
        stopPolling();
    } else if (!isPaused.value && !props.isGlobalPaused) {
        startPolling();
    }
}

watch(
    () => props.pollIntervalMs,
    () => {
        if (!isPaused.value && !props.isGlobalPaused) {
            startPolling();
        }
    }
);

watch(
    () => props.isGlobalPaused,
    (paused) => {
        if (paused) {
            stopPolling();
        } else if (!isPaused.value) {
            startPolling();
        }
    }
);

onMounted(async () => {
    document.addEventListener('visibilitychange', handleVisibility);
    await loadInterfaces();
    startPolling();
});

onUnmounted(() => {
    document.removeEventListener('visibilitychange', handleVisibility);
    stopPolling();
});

const isNearCapacity = computed(() => {
    if (!capacityMbps.value || capacityMbps.value <= 0) return false;
    const limitBps = capacityMbps.value * 1_000_000;
    return currentRxBps.value > limitBps * 0.85 || currentTxBps.value > limitBps * 0.85;
});

function exportCsv(): void {
    let csv = 'Timestamp,Rx (bps),Tx (bps),Rx (Formatted),Tx (Formatted)\n';
    for (let i = 0; i < timestamps.value.length; i++) {
        csv += `${timestamps.value[i]},${rxData.value[i] || 0},${txData.value[i] || 0},"${formatSpeed(rxData.value[i] || 0)}","${formatSpeed(txData.value[i] || 0)}"\n`;
    }
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', `traffic_${props.routerName}_${selectedInterface.value}_${Date.now()}.csv`);
    link.click();
}

function exportPng(): void {
    try {
        const echartsInstance = chartRef.value?.chart;
        if (!echartsInstance) return;
        const dataUrl = echartsInstance.getDataURL({
            type: 'png',
            pixelRatio: 2,
            backgroundColor: '#18181b',
        });
        const link = document.createElement('a');
        link.href = dataUrl;
        link.download = `traffic_${props.routerName}_${selectedInterface.value}.png`;
        link.click();
    } catch {
        // ignore
    }
}

const chartOption = computed(() => {
    const thresholdBps = capacityMbps.value && capacityMbps.value > 0 ? capacityMbps.value * 1_000_000 : null;

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
            data: ['Rx (Download)', 'Tx (Upload)'],
            textStyle: { color: '#a1a1aa', fontSize: 10 },
            top: 0,
            right: 0,
        },
        grid: {
            left: 45,
            right: 12,
            top: 24,
            bottom: 20,
        },
        xAxis: {
            type: 'category',
            data: timestamps.value,
            axisLine: { lineStyle: { color: '#3f3f46' } },
            axisLabel: { color: '#a1a1aa', fontSize: 9 },
        },
        yAxis: {
            type: 'value',
            min: 0,
            splitLine: { lineStyle: { color: 'rgba(161,161,170,0.12)' } },
            axisLabel: {
                color: '#a1a1aa',
                fontSize: 9,
                formatter: (v: number) => formatSpeed(v),
            },
        },
        series: [
            {
                name: 'Rx (Download)',
                type: 'line',
                smooth: 0.3,
                showSymbol: false,
                data: rxData.value,
                lineStyle: { color: '#10b981', width: 2 },
                areaStyle: { color: 'rgba(16, 185, 129, 0.15)' },
                ...(thresholdBps ? {
                    markLine: {
                        silent: true,
                        symbol: 'none',
                        lineStyle: { color: '#ef4444', type: 'dashed', width: 1.5 },
                        data: [{ yAxis: thresholdBps, label: { formatter: `Limit ${capacityMbps.value}M`, position: 'insideEndTop', color: '#ef4444', fontSize: 9 } }],
                    },
                } : {}),
            },
            {
                name: 'Tx (Upload)',
                type: 'line',
                smooth: 0.3,
                showSymbol: false,
                data: txData.value,
                lineStyle: { color: '#6366f1', width: 2 },
                areaStyle: { color: 'rgba(99, 102, 241, 0.15)' },
            },
        ],
    };
});
</script>

<template>
    <div class="flex flex-col rounded-xl border border-zinc-200 bg-white p-4 shadow-xs transition-shadow hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
        <!-- Card Header -->
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 pb-3 dark:border-zinc-800">
            <!-- Router Info & Status -->
            <div class="flex items-center gap-2">
                <span
                    class="h-2.5 w-2.5 rounded-full transition-colors"
                    :class="{
                        'bg-emerald-500 animate-pulse': !isPaused && !isGlobalPaused && !lastError,
                        'bg-amber-500': (isPaused || isGlobalPaused) && !lastError,
                        'bg-rose-500': !!lastError,
                    }"
                    :title="lastError ? `Error: ${lastError}` : isPaused || isGlobalPaused ? 'Paused' : 'Streaming Realtime'"
                />
                <div>
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-1.5">
                        {{ routerName }}
                    </h3>
                    <span class="font-mono text-[11px] text-zinc-400 dark:text-zinc-500">{{ routerHost }}</span>
                </div>
            </div>

            <!-- Interface Selector & Actions -->
            <div class="flex items-center gap-1.5">
                <!-- Interface Select -->
                <div class="relative">
                    <select
                        v-if="interfaces.length > 0"
                        v-model="selectedInterface"
                        @change="onInterfaceChange"
                        class="rounded-md border border-zinc-300 bg-zinc-50 px-2 py-1 font-mono text-xs font-medium text-zinc-800 focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                    >
                        <option v-for="iface in interfaces" :key="iface.name" :value="iface.name">
                            {{ iface.name }}{{ iface.comment ? ` - ${iface.comment}` : '' }}
                        </option>
                    </select>
                    <input
                        v-else
                        v-model="selectedInterface"
                        @change="onInterfaceChange"
                        placeholder="ether1"
                        class="w-24 rounded-md border border-zinc-300 bg-zinc-50 px-2 py-1 font-mono text-xs text-zinc-800 focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                    />
                </div>

                <!-- Single Pause Button -->
                <button
                    type="button"
                    class="rounded p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    :title="isPaused ? 'Resume Router Ini' : 'Pause Router Ini'"
                    @click="togglePause"
                >
                    <span v-if="isPaused">▶</span>
                    <span v-else>⏸</span>
                </button>

                <!-- Remove Card Button -->
                <button
                    type="button"
                    class="rounded p-1 text-zinc-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40 dark:hover:text-rose-400"
                    title="Tutup monitoring router ini"
                    @click="emit('remove', routerId)"
                >
                    ✕
                </button>
            </div>
        </div>

        <!-- Live KPI Badges -->
        <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
            <!-- Rx Badge -->
            <div class="rounded-lg border border-emerald-100 bg-emerald-50/70 p-2 dark:border-emerald-950 dark:bg-emerald-950/20">
                <div class="flex items-center justify-between text-[11px] font-medium text-emerald-800 dark:text-emerald-300">
                    <span>⬇ Rx (Download)</span>
                    <span class="text-[10px] opacity-75">{{ formatPackets(currentRxPackets) }}</span>
                </div>
                <div class="mt-1 font-mono text-base font-bold text-emerald-700 dark:text-emerald-400">
                    {{ formatSpeed(currentRxBps) }}
                </div>
                <div class="mt-0.5 text-[10px] text-emerald-600/80 dark:text-emerald-500">
                    Peak: {{ formatSpeed(peakRxBps) }}
                </div>
            </div>

            <!-- Tx Badge -->
            <div class="rounded-lg border border-indigo-100 bg-indigo-50/70 p-2 dark:border-indigo-950 dark:bg-indigo-950/20">
                <div class="flex items-center justify-between text-[11px] font-medium text-indigo-800 dark:text-indigo-300">
                    <span>⬆ Tx (Upload)</span>
                    <span class="text-[10px] opacity-75">{{ formatPackets(currentTxPackets) }}</span>
                </div>
                <div class="mt-1 font-mono text-base font-bold text-indigo-700 dark:text-indigo-400">
                    {{ formatSpeed(currentTxBps) }}
                </div>
                <div class="mt-0.5 text-[10px] text-indigo-600/80 dark:text-indigo-500">
                    Peak: {{ formatSpeed(peakTxBps) }}
                </div>
            </div>
        </div>

        <!-- Error notification if any -->
        <div v-if="lastError" class="mt-2 rounded-md bg-rose-50 px-2.5 py-1 text-xs text-rose-600 dark:bg-rose-950/40 dark:text-rose-400 flex items-center justify-between">
            <span>⚠ {{ lastError }}</span>
            <button type="button" class="underline font-semibold ml-2" @click="fetchTraffic">Retry</button>
        </div>

        <!-- Warning if near capacity threshold -->
        <div v-if="isNearCapacity" class="mt-2 rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300 flex items-center justify-between">
            <span>🚨 Traffic mendekati ambang batas limit {{ capacityMbps }} Mbps (>85%)!</span>
        </div>

        <!-- Realtime Chart -->
        <div class="mt-2" :class="heightClass">
            <VChart ref="chartRef" :option="chartOption" autoresize class="h-full w-full" />
        </div>

        <!-- Card Bottom Toolbar: Threshold setting, Torch tool, Export buttons -->
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-2 text-[11px] text-zinc-500 dark:border-zinc-800">
            <!-- Capacity Threshold Input -->
            <div class="flex items-center gap-1.5">
                <span>Batas ISP:</span>
                <input
                    v-model.number="capacityMbps"
                    type="number"
                    placeholder="e.g. 100"
                    min="1"
                    max="10000"
                    class="h-5 w-16 rounded border border-zinc-300 bg-zinc-50 px-1 text-center font-mono text-[10px] focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                    title="Masukkan kapasitas ISP (Mbps) untuk menampilkan garis limit merah"
                />
                <span class="text-zinc-400">Mbps</span>
            </div>

            <!-- Action shortcuts: Torch, CSV, PNG -->
            <div class="flex items-center gap-2">
                <a
                    :href="route('routers.tools.index', routerId) + '?tool=torch&interface=' + selectedInterface"
                    target="_blank"
                    class="flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 font-medium text-amber-700 transition hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-300"
                    title="Buka MikroTik Torch untuk memantau top talker IP"
                >
                    🔥 Torch
                </a>
                <button
                    type="button"
                    class="rounded px-1.5 py-0.5 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    title="Unduh data riwayat sebagai CSV"
                    @click="exportCsv"
                >
                    📥 CSV
                </button>
                <button
                    type="button"
                    class="rounded px-1.5 py-0.5 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                    title="Ambil screenshot grafik sebagai gambar PNG"
                    @click="exportPng"
                >
                    📷 PNG
                </button>
            </div>
        </div>
    </div>
</template>
