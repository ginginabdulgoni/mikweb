<script setup lang="ts">
import { computed } from 'vue';
import { use } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import VChart from 'vue-echarts';

use([LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

export interface RouterTrafficStream {
    routerId: number;
    routerName: string;
    interfaceName: string;
    rxBps: number;
    txBps: number;
    rxHistory: number[];
    txHistory: number[];
    timestamps: string[];
    isError: boolean;
}

const props = withDefaults(
    defineProps<{
        streams: Record<number, RouterTrafficStream>;
        selectedRouterIds: number[];
        heightClass?: string;
    }>(),
    {
        heightClass: 'h-96',
    }
);

// High-contrast color palette pairs for multiple routers (Rx solid, Tx dashed)
const PALETTE = [
    { rx: '#10b981', tx: '#34d399' }, // Emerald / Mint
    { rx: '#6366f1', tx: '#818cf8' }, // Indigo / Violet
    { rx: '#f59e0b', tx: '#fbbf24' }, // Amber / Yellow
    { rx: '#0ea5e9', tx: '#38bdf8' }, // Sky / Cyan
    { rx: '#f43f5e', tx: '#fb7185' }, // Rose / Pink
    { rx: '#a855f7', tx: '#c084fc' }, // Purple / Fuchsia
    { rx: '#14b8a6', tx: '#2dd4bf' }, // Teal / Turquoise
    { rx: '#f97316', tx: '#fb923c' }, // Orange / Coral
    { rx: '#3b82f6', tx: '#60a5fa' }, // Blue / Light Blue
    { rx: '#84cc16', tx: '#a3e635' }, // Lime
];

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

const combinedChartRef = ref<any>(null);

function exportCombinedCsv(): void {
    const timestamps = commonTimestamps.value;
    if (timestamps.length === 0 || activeStreams.value.length === 0) return;

    let headers = ['Timestamp'];
    for (const s of activeStreams.value) {
        headers.push(`${s.routerName} Rx (bps)`, `${s.routerName} Tx (bps)`);
    }
    let csv = headers.join(',') + '\n';

    for (let i = 0; i < timestamps.length; i++) {
        let row = [timestamps[i]];
        for (const s of activeStreams.value) {
            row.push(String(s.rxHistory[i] ?? 0), String(s.txHistory[i] ?? 0));
        }
        csv += row.join(',') + '\n';
    }

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `fleet_traffic_combined_${Date.now()}.csv`;
    link.click();
}

function exportCombinedPng(): void {
    try {
        const echartsInstance = combinedChartRef.value?.chart;
        if (!echartsInstance) return;
        const dataUrl = echartsInstance.getDataURL({
            type: 'png',
            pixelRatio: 2,
            backgroundColor: '#18181b',
        });
        const link = document.createElement('a');
        link.href = dataUrl;
        link.download = `fleet_traffic_combined_${Date.now()}.png`;
        link.click();
    } catch {
        // ignore
    }
}

const activeStreams = computed(() => {
    return props.selectedRouterIds
        .map((id) => props.streams[id])
        .filter((s): s is RouterTrafficStream => Boolean(s));
});

// Total aggregate throughput across all selected routers
const aggregateTotals = computed(() => {
    let totalRx = 0;
    let totalTx = 0;
    for (const stream of activeStreams.value) {
        totalRx += stream.rxBps || 0;
        totalTx += stream.txBps || 0;
    }
    return {
        totalRx,
        totalTx,
        totalCombined: totalRx + totalTx,
    };
});

// Longest timeline labels from any stream
const commonTimestamps = computed(() => {
    let maxArr: string[] = [];
    for (const stream of activeStreams.value) {
        if (stream.timestamps && stream.timestamps.length > maxArr.length) {
            maxArr = stream.timestamps;
        }
    }
    return maxArr;
});

const chartOption = computed(() => {
    const seriesList: any[] = [];
    const legendList: string[] = [];

    activeStreams.value.forEach((stream, idx) => {
        const colors = PALETTE[idx % PALETTE.length];
        const rxName = `${stream.routerName} Rx`;
        const txName = `${stream.routerName} Tx`;

        legendList.push(rxName, txName);

        // Download (Rx)
        seriesList.push({
            name: rxName,
            type: 'line',
            smooth: 0.3,
            showSymbol: false,
            data: stream.rxHistory,
            lineStyle: { color: colors.rx, width: 2 },
            areaStyle: { color: colors.rx, opacity: 0.08 },
        });

        // Upload (Tx)
        seriesList.push({
            name: txName,
            type: 'line',
            smooth: 0.3,
            showSymbol: false,
            data: stream.txHistory,
            lineStyle: { color: colors.tx, width: 2, type: 'dashed' },
            areaStyle: { color: colors.tx, opacity: 0.04 },
        });
    });

    return {
        animation: false,
        tooltip: {
            trigger: 'axis',
            formatter: (params: any[]) => {
                if (!params || !params.length) return '';
                let html = `<div class="font-mono text-xs"><div class="text-zinc-400 mb-1 border-b border-zinc-700 pb-1">${params[0].axisValue}</div>`;
                for (const p of params) {
                    html += `<div class="flex items-center justify-between gap-4 py-0.5">
                        <span style="color:${p.color}">● ${p.seriesName}</span>
                        <span class="font-semibold">${formatSpeed(p.value ?? 0)}</span>
                    </div>`;
                }
                html += '</div>';
                return html;
            },
        },
        legend: {
            data: legendList,
            textStyle: { color: '#a1a1aa', fontSize: 11 },
            top: 0,
            type: 'scroll',
        },
        grid: {
            left: 55,
            right: 20,
            top: 40,
            bottom: 24,
        },
        xAxis: {
            type: 'category',
            data: commonTimestamps.value,
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
    <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <!-- Header & Aggregate KPI Bar -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-4 border-b border-zinc-100 pb-4 dark:border-zinc-800">
            <div>
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span>Grafik Gabungan Traffic Realtime</span>
                    <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                        {{ activeStreams.length }} Router Terpilih
                    </span>
                </h3>
                <p class="text-xs text-zinc-500">
                    Membandingkan throughput live download (garis tegas) &amp; upload (garis putus-putus) seluruh router yang dipilih dalam satu grafik sinkron.
                </p>
            </div>

            <!-- Fleet Aggregate Bandwidth Badges & Export Buttons -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-1.5 dark:border-zinc-700 dark:bg-zinc-800">
                    <span class="text-[10px] font-medium uppercase tracking-wider text-zinc-400">Total Throughput</span>
                    <div class="font-mono text-sm font-bold text-zinc-900 dark:text-zinc-100">
                        {{ formatSpeed(aggregateTotals.totalCombined) }}
                    </div>
                </div>

                <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 dark:border-emerald-950 dark:bg-emerald-950/30">
                    <span class="text-[10px] font-medium uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Total Rx (Download)</span>
                    <div class="font-mono text-sm font-bold text-emerald-700 dark:text-emerald-400">
                        {{ formatSpeed(aggregateTotals.totalRx) }}
                    </div>
                </div>

                <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 dark:border-indigo-950 dark:bg-indigo-950/30">
                    <span class="text-[10px] font-medium uppercase tracking-wider text-indigo-600 dark:text-indigo-400">Total Tx (Upload)</span>
                    <div class="font-mono text-sm font-bold text-indigo-700 dark:text-indigo-400">
                        {{ formatSpeed(aggregateTotals.totalTx) }}
                    </div>
                </div>

                <!-- Export Action Buttons -->
                <div class="flex items-center gap-1.5 pl-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 shadow-xs hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        title="Unduh seluruh riwayat trafik sebagai file CSV"
                        @click="exportCombinedCsv"
                    >
                        📥 CSV
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 shadow-xs hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        title="Unduh grafik gabungan sebagai gambar PNG"
                        @click="exportCombinedPng"
                    >
                        📷 PNG
                    </button>
                </div>
            </div>
        </div>

        <!-- ECharts Combined View -->
        <div v-if="activeStreams.length === 0" class="py-16 text-center text-sm text-zinc-400">
            Pilih minimal 1 router di atas untuk menampilkan grafik realtime gabungan.
        </div>
        <div v-else :class="heightClass">
            <VChart ref="combinedChartRef" :option="chartOption" autoresize class="h-full w-full" />
        </div>

        <!-- Per-Router Bandwidth Breakdown Table -->
        <div v-if="activeStreams.length > 0" class="mt-4 overflow-x-auto border-t border-zinc-100 pt-3 dark:border-zinc-800">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="text-zinc-400 border-b border-zinc-100 dark:border-zinc-800">
                        <th class="py-1.5 px-2">Router</th>
                        <th class="py-1.5 px-2">Interface</th>
                        <th class="py-1.5 px-2">Live Rx (Download)</th>
                        <th class="py-1.5 px-2">Live Tx (Upload)</th>
                        <th class="py-1.5 px-2">Total Bandwidth</th>
                        <th class="py-1.5 px-2 text-right">Porsi Traffic</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60 font-mono">
                    <tr v-for="(stream, idx) in activeStreams" :key="stream.routerId">
                        <td class="py-1.5 px-2 font-sans font-medium text-zinc-900 dark:text-zinc-100 flex items-center gap-1.5">
                            <span class="inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: PALETTE[idx % PALETTE.length].rx }"></span>
                            {{ stream.routerName }}
                        </td>
                        <td class="py-1.5 px-2 text-zinc-500">{{ stream.interfaceName }}</td>
                        <td class="py-1.5 px-2 text-emerald-600 dark:text-emerald-400 font-semibold">{{ formatSpeed(stream.rxBps) }}</td>
                        <td class="py-1.5 px-2 text-indigo-600 dark:text-indigo-400 font-semibold">{{ formatSpeed(stream.txBps) }}</td>
                        <td class="py-1.5 px-2 text-zinc-800 dark:text-zinc-200">{{ formatSpeed(stream.rxBps + stream.txBps) }}</td>
                        <td class="py-1.5 px-2 text-right text-zinc-500">
                            {{ aggregateTotals.totalCombined > 0 ? (((stream.rxBps + stream.txBps) / aggregateTotals.totalCombined) * 100).toFixed(1) + '%' : '0%' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

