<script setup>
import VueApexCharts from 'vue3-apexcharts';
import { computed } from 'vue';

const props = defineProps({
    type: { type: String, default: 'bar' },
    title: { type: String, default: '' },
    categories: { type: Array, default: () => [] },
    series: { type: Array, default: () => [] },
    money: { type: Boolean, default: false },
});

const donut = computed(() => props.type === 'donut' || props.type === 'pie');
const categories = computed(() => (Array.isArray(props.categories) ? props.categories : []));
const numeric = (values) => (Array.isArray(values) ? values : []).map((value) => Number(value) || 0);

const chartSeries = computed(() => {
    const series = Array.isArray(props.series) ? props.series : [];

    if (donut.value) {
        const first = series[0];
        const values = first && typeof first === 'object' && !Array.isArray(first) && Array.isArray(first.data)
            ? first.data
            : series;

        return numeric(values);
    }

    return series.map((entry, index) => (
        entry && typeof entry === 'object' && !Array.isArray(entry)
            ? { name: entry.name || `Series ${index + 1}`, data: numeric(entry.data) }
            : { name: `Series ${index + 1}`, data: numeric(Array.isArray(entry) ? entry : []) }
    ));
});

const options = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['#1e3a5f', '#f2762e', '#0f766e', '#b45309', '#64748b', '#7c3aed'],
    dataLabels: { enabled: donut.value },
    legend: { position: 'bottom', fontSize: '12px' },
    // ApexCharts reads labels.length during axis parsing. undefined crashes; [] falls through to xaxis.categories.
    labels: donut.value ? categories.value : [],
    ...(donut.value ? {} : { xaxis: { categories: categories.value, labels: { style: { fontSize: '11px' }, rotate: -35, trim: true } } }),
    yaxis: { labels: { formatter: (value) => (props.money ? compact(value) : String(Math.round(value))) } },
    tooltip: { y: { formatter: (value) => (props.money ? `₹ ${Number(value).toLocaleString('en-IN', { maximumFractionDigits: 2 })}` : String(value)) } },
    plotOptions: { bar: { borderRadius: 3, columnWidth: '55%' } },
    stroke: { width: props.type === 'line' || props.type === 'area' ? 2 : 0, curve: 'smooth' },
    grid: { borderColor: '#e2e8f0' },
}));

function compact(value) {
    const abs = Math.abs(value);
    if (abs >= 1e7) {
        return `${(value / 1e7).toFixed(1)} Cr`;
    }
    if (abs >= 1e5) {
        return `${(value / 1e5).toFixed(1)} L`;
    }

    return Number(value).toLocaleString('en-IN', { maximumFractionDigits: 0 });
}
</script>

<template>
    <div class="min-w-0">
        <h3 v-if="title" class="mb-2 text-sm font-semibold text-slate-900">{{ title }}</h3>
        <VueApexCharts :type="donut ? 'donut' : type === 'area' ? 'area' : type === 'line' ? 'line' : 'bar'" height="280" :options="options" :series="chartSeries" />
    </div>
</template>
