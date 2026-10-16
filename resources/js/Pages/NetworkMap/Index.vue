<script setup lang="ts">
import { computed } from 'vue';
import { Head, router as inertia } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { use } from 'echarts/core';
import { GraphChart } from 'echarts/charts';
import { TooltipComponent, LegendComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import VChart from 'vue-echarts';

use([GraphChart, TooltipComponent, LegendComponent, CanvasRenderer]);

interface NodeItem {
    id: string;
    name: string;
    symbol: string;
    symbolSize: number | number[];
    itemStyle: { color: string };
    category: number;
    routerId?: number;
    host?: string;
    status: string;
    cpu?: number | null;
    mem?: number | null;
    latency?: number | null;
    model?: string;
}

interface LinkItem {
    source: string;
    target: string;
    lineStyle: { color: string; width: number; type?: string };
    label?: { show: boolean; formatter: string };
}

const props = defineProps<{
    nodes: NodeItem[];
    links: LinkItem[];
    summary: {
        total: number;
        online: number;
        unreachable: number;
    };
}>();

const chartOption = computed(() => {
    return {
        tooltip: {
            formatter: (params: any) => {
                if (params.dataType === 'node') {
                    const d = params.data;
                    if (d.id === 'internet_cloud') {
                        return `<div class="font-sans text-xs"><strong>☁️ ${d.name}</strong><br/>Global ISP Uplink</div>`;
                    }
                    return `<div class="font-sans text-xs space-y-1">
                        <div class="font-bold text-sm text-zinc-100 flex items-center gap-1.5">
                            ● ${d.name}
                        </div>
                        <div class="font-mono text-zinc-400">Host: ${d.host}</div>
                        <div>Status: <strong class="${d.status === 'online' ? 'text-emerald-400' : 'text-rose-400'}">${d.status.toUpperCase()}</strong></div>
                        <div>Model: <strong>${d.model || 'MikroTik'}</strong></div>
                        ${d.cpu !== undefined && d.cpu !== null ? `<div>CPU Load: <strong>${d.cpu}%</strong></div>` : ''}
                        ${d.mem !== undefined && d.mem !== null ? `<div>Memory: <strong>${d.mem}%</strong></div>` : ''}
                        ${d.latency !== undefined && d.latency !== null ? `<div>Latency: <strong>${d.latency}ms</strong></div>` : ''}
                        <div class="mt-1 pt-1 border-t border-zinc-700 text-[10px] text-zinc-400">Klik node untuk membuka detail router</div>
                    </div>`;
                }
                if (params.dataType === 'edge') {
                    return `<div class="text-xs font-mono">Link: ${params.data.source} ➔ ${params.data.target}</div>`;
                }
                return '';
            },
        },
        animationDurationUpdate: 1500,
        animationEasingUpdate: 'quinticInOut',
        series: [
            {
                type: 'graph',
                layout: 'force',
                roam: true,
                draggable: true,
                data: props.nodes,
                links: props.links,
                categories: [{ name: 'Gateway / Cloud' }, { name: 'MikroTik Routers' }],
                label: {
                    show: true,
                    position: 'bottom',
                    formatter: '{b}',
                    fontSize: 11,
                    color: '#e4e4e7',
                },
                force: {
                    repulsion: 450,
                    edgeLength: [140, 220],
                    gravity: 0.1,
                },
                edgeSymbol: ['circle', 'arrow'],
                edgeSymbolSize: [4, 8],
                edgeLabel: {
                    fontSize: 10,
                    color: '#a1a1aa',
                },
                lineStyle: {
                    curveness: 0.1,
                },
                emphasis: {
                    focus: 'adjacency',
                    lineStyle: { width: 4 },
                },
            },
        ],
    };
});

function onChartClick(params: any) {
    if (params.dataType === 'node' && params.data?.routerId) {
        inertia.visit(route('routers.show', params.data.routerId));
    }
}
</script>

<template>
    <Head title="Peta Topologi Jaringan (Network Map)" />
    <AppLayout title="Visual Network Topology Map">
        <div class="w-full space-y-6">
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-200 pb-4 dark:border-zinc-800">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <span>🗺️ Visual Network Topology Map</span>
                    </h2>
                    <p class="text-xs text-zinc-500">
                        Visualisasi interaktif relasi node armada MikroTik, status link, dan jalur gateway.
                    </p>
                </div>

                <!-- Summary Badges -->
                <div class="flex items-center gap-2 text-xs">
                    <span class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1 font-semibold text-zinc-700 shadow-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                        Total Node: {{ summary.total }}
                    </span>
                    <span class="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 font-semibold text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        🟢 Online: {{ summary.online }}
                    </span>
                    <span
                        v-if="summary.unreachable > 0"
                        class="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 font-semibold text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300"
                    >
                        🔴 Down: {{ summary.unreachable }}
                    </span>
                </div>
            </div>

            <!-- ECharts Interactive Canvas Card -->
            <div class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-900 p-4 shadow-md dark:border-zinc-800">
                <!-- Watermark / Hint -->
                <div class="absolute top-4 left-4 z-10 flex items-center gap-2 rounded-lg bg-zinc-800/80 px-3 py-1.5 text-xs text-zinc-300 backdrop-blur-sm border border-zinc-700/60">
                    <span>💡 <strong>Tips:</strong> Geser mouse untuk pan, scroll untuk zoom, drag node untuk memindahkan, klik node untuk membuka router.</span>
                </div>

                <!-- Canvas -->
                <div class="h-[620px] w-full">
                    <VChart
                        :option="chartOption"
                        autoresize
                        class="h-full w-full"
                        @click="onChartClick"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>

