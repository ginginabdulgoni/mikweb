<script setup lang="ts">
import { computed } from 'vue';
import { use } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import VChart from 'vue-echarts';

use([LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

const props = withDefaults(
    defineProps<{
        labels: (string | null)[];
        series: { name: string; data: (number | null)[]; color: string }[];
        unit?: string;
        max?: number | null;
        heightClass?: string;
    }>(),
    {
        unit: '%',
        max: null,
        heightClass: 'h-72',
    },
);

const option = computed(() => {
    const isPercent = props.unit === '%';
    const computedMax = props.max !== null ? props.max : (isPercent ? 100 : undefined);

    return {
        tooltip: {
            trigger: 'axis',
            valueFormatter: (value: any) => (value !== null && value !== undefined ? `${value} ${props.unit}` : '—'),
        },
        legend: { data: props.series.map((s) => s.name), textStyle: { color: '#a1a1aa' }, top: 0 },
        grid: { left: 45, right: 16, top: 30, bottom: 24 },
        xAxis: {
            type: 'category',
            data: props.labels,
            axisLine: { lineStyle: { color: '#3f3f46' } },
            axisLabel: { color: '#a1a1aa', fontSize: 10 },
        },
        yAxis: {
            type: 'value',
            min: 0,
            ...(computedMax !== undefined ? { max: computedMax } : {}),
            splitLine: { lineStyle: { color: 'rgba(161,161,170,0.15)' } },
            axisLabel: { color: '#a1a1aa', fontSize: 10, formatter: `{value} ${props.unit}` },
        },
        series: props.series.map((s) => ({
            name: s.name,
            type: 'line',
            smooth: true,
            showSymbol: false,
            data: s.data,
            lineStyle: { color: s.color, width: 2 },
            areaStyle: { color: s.color, opacity: 0.08 },
        })),
    };
});
</script>

<template>
    <VChart :option="option" autoresize :class="[heightClass, 'w-full']" />
</template>
