<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    initialData: {
        type: Object,
        required: true,
    },
});

const ranges = [
    { value: '24h', label: 'Last 24 hours' },
    { value: '7d', label: 'Last 7 days' },
    { value: '3m', label: 'Last 3 months' },
    { value: '1y', label: 'Last year' },
    { value: 'custom', label: 'Custom range' },
];

const data = ref(props.initialData);
const selectedRange = ref(props.initialData.range || '7d');
const customStart = ref(props.initialData.period.start.slice(0, 10));
const customEnd = ref(props.initialData.period.end.slice(0, 10));
const today = new Date().toISOString().slice(0, 10);
const isLoading = ref(false);
const isSyncing = ref(false);
const errorMessage = ref('');

const requestParams = () => ({
    range: selectedRange.value,
    ...(selectedRange.value === 'custom' ? { start: customStart.value, end: customEnd.value } : {}),
});

const loadData = async () => {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.get(route('admin.pi-revenue.data'), { params: requestParams() });
        data.value = response.data;
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'Unable to load PI revenue data.';
    } finally {
        isLoading.value = false;
    }
};

const selectRange = (range) => {
    selectedRange.value = range;

    if (range !== 'custom') {
        loadData();
    }
};

const syncNow = async () => {
    isSyncing.value = true;
    errorMessage.value = '';

    try {
        const response = await window.axios.post(route('admin.pi-revenue.sync'), requestParams());
        data.value = response.data.analytics;
    } catch (error) {
        errorMessage.value = error.response?.data?.message || 'Unable to synchronize the EVE wallet.';
    } finally {
        isSyncing.value = false;
    }
};

const formatIsk = (value, compact = false) => {
    const formatter = new Intl.NumberFormat('en-US', {
        notation: compact ? 'compact' : 'standard',
        maximumFractionDigits: compact ? 2 : 0,
    });

    return `${formatter.format(Number(value || 0))} ISK`;
};

const formatDateTime = (value) => {
    if (!value) return 'No data yet';

    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    }).format(new Date(value));
};

const periodLabel = computed(() => `${formatDateTime(data.value.period.start)} — ${formatDateTime(data.value.period.end)} UTC`);
const granularityLabel = computed(
    () =>
        ({
            hour: 'Hourly',
            day: 'Daily',
            month: 'Monthly',
        })[data.value.series.granularity] || data.value.series.granularity,
);

const chart = computed(() => {
    const points = data.value.series.points;
    const width = 1000;
    const height = 280;
    const left = 52;
    const right = 18;
    const top = 18;
    const bottom = 48;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const maximum = Math.max(...points.map((point) => Number(point.total)), 1);
    const step = plotWidth / Math.max(points.length, 1);
    const barWidth = Math.max(2, Math.min(38, step * 0.68));

    return {
        width,
        height,
        left,
        top,
        bottom,
        plotHeight,
        maximum,
        bars: points.map((point, index) => {
            const exportHeight = (Number(point.export) / maximum) * plotHeight;
            const importHeight = (Number(point.import) / maximum) * plotHeight;
            const x = left + index * step + (step - barWidth) / 2;

            return {
                ...point,
                x,
                width: barWidth,
                exportY: top + plotHeight - exportHeight,
                exportHeight,
                importY: top + plotHeight - exportHeight - importHeight,
                importHeight,
                showLabel: index === 0 || index === points.length - 1 || index % Math.max(1, Math.ceil(points.length / 6)) === 0,
            };
        }),
        grid: [0, 0.5, 1].map((ratio) => ({
            y: top + plotHeight - ratio * plotHeight,
            value: maximum * ratio,
        })),
    };
});

const pointLabel = (timestamp) => {
    if (data.value.series.granularity === 'hour') {
        return new Intl.DateTimeFormat('en-GB', { day: '2-digit', hour: '2-digit', timeZone: 'UTC' }).format(new Date(timestamp));
    }

    if (data.value.series.granularity === 'month') {
        return new Intl.DateTimeFormat('en-GB', { month: 'short', year: '2-digit', timeZone: 'UTC' }).format(new Date(`${timestamp}-01T00:00:00Z`));
    }

    return new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', timeZone: 'UTC' }).format(new Date(`${timestamp}T00:00:00Z`));
};
</script>

<template>
    <Head title="PI Revenue" />

    <AppLayout title="PI TAX REVENUE">
        <div class="relative flex w-full flex-col gap-5">
            <section class="bg-blur border border-white/20 p-4 lg:p-5">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <p class="text-xs font-bold tracking-[0.2em] text-sky-300 uppercase">Corporation wallet archive</p>
                        <p class="mt-1 text-sm text-white/60">{{ periodLabel }}</p>
                        <p class="mt-1 text-xs text-white/40">
                            Coverage: {{ formatDateTime(data.coverage.start) }} — {{ formatDateTime(data.coverage.end) }} UTC
                        </p>
                    </div>

                    <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="range in ranges"
                                :key="range.value"
                                type="button"
                                class="min-h-10 border px-3 py-2 text-sm transition"
                                :class="
                                    selectedRange === range.value
                                        ? 'border-sky-400 bg-sky-700 text-white'
                                        : 'border-white/20 bg-slate-950/40 text-white/65 hover:border-white/40 hover:text-white'
                                "
                                @click="selectRange(range.value)"
                            >
                                {{ range.label }}
                            </button>
                        </div>

                        <button type="button" class="btn whitespace-nowrap" :disabled="isSyncing" @click="syncNow">
                            <i class="fas fa-fw" :class="isSyncing ? 'fa-spinner fa-spin' : 'fa-arrows-rotate'"></i>
                            {{ isSyncing ? 'Syncing' : 'Sync ESI' }}
                        </button>
                    </div>
                </div>

                <div v-if="selectedRange === 'custom'" class="mt-4 flex flex-col gap-3 border-t border-white/10 pt-4 sm:flex-row sm:items-end">
                    <label class="flex flex-1 flex-col gap-1 text-sm">
                        <span class="font-semibold text-white/70">From</span>
                        <input
                            id="pi-range-start"
                            v-model="customStart"
                            name="pi_range_start"
                            type="date"
                            :max="today"
                            class="min-h-11 border border-white/25 bg-slate-950/60 px-3 py-2 outline-none focus:border-sky-400"
                        />
                    </label>
                    <label class="flex flex-1 flex-col gap-1 text-sm">
                        <span class="font-semibold text-white/70">To</span>
                        <input
                            id="pi-range-end"
                            v-model="customEnd"
                            name="pi_range_end"
                            type="date"
                            :max="today"
                            class="min-h-11 border border-white/25 bg-slate-950/60 px-3 py-2 outline-none focus:border-sky-400"
                        />
                    </label>
                    <button type="button" class="btn" :disabled="!customStart || !customEnd || isLoading" @click="loadData">
                        <i class="fas fa-chart-column fa-fw"></i>
                        Apply range
                    </button>
                </div>
            </section>

            <div v-if="errorMessage" class="border border-red-400/40 bg-red-950/60 px-4 py-3 text-sm text-red-100">
                <i class="fas fa-triangle-exclamation mr-2"></i>{{ errorMessage }}
            </div>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
                <article class="bg-blur border border-white/20 p-4 sm:col-span-2 xl:col-span-1">
                    <p class="text-xs tracking-widest text-white/50 uppercase">Total PI revenue</p>
                    <p class="mt-3 text-3xl font-bold text-white">{{ formatIsk(data.stats.total_revenue, true) }}</p>
                    <p class="mt-2 text-xs text-white/45">Import and export taxes combined</p>
                </article>
                <article class="border border-violet-400/25 bg-violet-950/30 p-4">
                    <p class="text-xs tracking-widest text-violet-300 uppercase">Average per day</p>
                    <p class="mt-3 text-2xl font-bold text-white">{{ formatIsk(data.stats.average_daily_revenue, true) }}</p>
                    <p class="mt-2 text-xs text-violet-200/60">Across the selected period</p>
                </article>
                <article class="bg-blur border border-white/20 p-4">
                    <p class="text-xs tracking-widest text-white/50 uppercase">Journal entries</p>
                    <p class="mt-3 text-3xl font-bold text-white">{{ data.stats.entries.toLocaleString() }}</p>
                    <p class="mt-2 text-xs text-white/45">Archived tax payments</p>
                </article>
                <article class="bg-blur border border-white/20 p-4">
                    <p class="text-xs tracking-widest text-white/50 uppercase">Active taxpayers</p>
                    <p class="mt-3 text-3xl font-bold text-white">{{ data.stats.active_taxpayers.toLocaleString() }}</p>
                    <p class="mt-2 text-xs text-white/45">Unique characters in range</p>
                </article>
                <article class="border border-sky-400/25 bg-sky-950/35 p-4">
                    <p class="text-xs tracking-widest text-sky-300 uppercase">Export taxes</p>
                    <p class="mt-3 text-2xl font-bold text-white">{{ formatIsk(data.stats.export_revenue, true) }}</p>
                    <p class="mt-2 text-sm text-sky-200/70">{{ data.stats.export_share.toFixed(1) }}% of revenue</p>
                </article>
                <article class="border border-teal-400/25 bg-teal-950/30 p-4">
                    <p class="text-xs tracking-widest text-teal-300 uppercase">Import taxes</p>
                    <p class="mt-3 text-2xl font-bold text-white">{{ formatIsk(data.stats.import_revenue, true) }}</p>
                    <p class="mt-2 text-sm text-teal-200/70">{{ data.stats.import_share.toFixed(1) }}% of revenue</p>
                </article>
            </section>

            <section class="bg-blur border border-white/20 p-4 lg:p-5">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2>Revenue over time</h2>
                        <p class="mt-1 text-xs text-white/45">{{ granularityLabel }} totals, UTC</p>
                    </div>
                    <div class="flex gap-4 text-xs text-white/60">
                        <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 bg-sky-500"></span>Export</span>
                        <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 bg-teal-400"></span>Import</span>
                    </div>
                </div>

                <div v-if="data.stats.entries" class="overflow-x-auto">
                    <svg :viewBox="`0 0 ${chart.width} ${chart.height}`" class="h-72 w-full min-w-[720px]" role="img" aria-label="PI revenue chart">
                        <g v-for="line in chart.grid" :key="line.y">
                            <line :x1="chart.left" :x2="chart.width - 18" :y1="line.y" :y2="line.y" stroke="rgba(255,255,255,.12)" />
                            <text x="0" :y="line.y + 4" fill="rgba(255,255,255,.45)" font-size="12">
                                {{ formatIsk(line.value, true).replace(' ISK', '') }}
                            </text>
                        </g>
                        <g v-for="bar in chart.bars" :key="bar.timestamp">
                            <rect :x="bar.x" :y="bar.exportY" :width="bar.width" :height="bar.exportHeight" fill="#0ea5e9">
                                <title>{{ pointLabel(bar.timestamp) }} · Export {{ formatIsk(bar.export) }}</title>
                            </rect>
                            <rect :x="bar.x" :y="bar.importY" :width="bar.width" :height="bar.importHeight" fill="#2dd4bf">
                                <title>{{ pointLabel(bar.timestamp) }} · Import {{ formatIsk(bar.import) }}</title>
                            </rect>
                            <text
                                v-if="bar.showLabel"
                                :x="bar.x + bar.width / 2"
                                :y="chart.height - 18"
                                fill="rgba(255,255,255,.5)"
                                font-size="11"
                                text-anchor="middle"
                            >
                                {{ pointLabel(bar.timestamp) }}
                            </text>
                        </g>
                    </svg>
                </div>
                <div v-else class="flex min-h-64 items-center justify-center border border-dashed border-white/15 text-sm text-white/45">
                    No PI tax revenue was recorded in this period.
                </div>
            </section>

            <section class="grid gap-5 xl:grid-cols-2">
                <article class="bg-blur overflow-hidden border border-white/20">
                    <div class="border-b border-white/15 p-4 lg:p-5">
                        <h2>Largest taxpayers</h2>
                        <p class="mt-1 text-xs text-white/45">Ranked within the selected period</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead class="bg-slate-950/45 text-left text-xs tracking-wider text-white/45 uppercase">
                                <tr>
                                    <th class="px-4 py-3">#</th>
                                    <th class="px-4 py-3">Character</th>
                                    <th class="px-4 py-3 text-right">Revenue</th>
                                    <th class="px-4 py-3 text-right">Entries</th>
                                    <th class="px-4 py-3 text-right">Share</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                                <tr v-for="(taxpayer, index) in data.taxpayers" :key="taxpayer.id" class="hover:bg-white/5">
                                    <td class="px-4 py-3 text-white/35">{{ index + 1 }}</td>
                                    <td class="px-4 py-3 font-semibold">{{ taxpayer.name }}</td>
                                    <td class="px-4 py-3 text-right text-sky-200">{{ formatIsk(taxpayer.amount) }}</td>
                                    <td class="px-4 py-3 text-right text-white/60">{{ taxpayer.entries.toLocaleString() }}</td>
                                    <td class="px-4 py-3 text-right text-white/60">{{ taxpayer.share.toFixed(1) }}%</td>
                                </tr>
                                <tr v-if="!data.taxpayers.length">
                                    <td colspan="5" class="px-4 py-10 text-center text-white/40">No taxpayers in this period.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="bg-blur overflow-hidden border border-white/20">
                    <div class="border-b border-white/15 p-4 lg:p-5">
                        <h2>Revenue by planet</h2>
                        <p class="mt-1 text-xs text-white/45">POCO activity within the selected period</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead class="bg-slate-950/45 text-left text-xs tracking-wider text-white/45 uppercase">
                                <tr>
                                    <th class="px-4 py-3">#</th>
                                    <th class="px-4 py-3">Planet</th>
                                    <th class="px-4 py-3 text-right">Revenue</th>
                                    <th class="px-4 py-3 text-right">Entries</th>
                                    <th class="px-4 py-3 text-right">Share</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                                <tr v-for="(planet, index) in data.planets" :key="planet.id" class="hover:bg-white/5">
                                    <td class="px-4 py-3 text-white/35">{{ index + 1 }}</td>
                                    <td class="px-4 py-3 font-semibold">{{ planet.name }}</td>
                                    <td class="px-4 py-3 text-right text-teal-200">{{ formatIsk(planet.amount) }}</td>
                                    <td class="px-4 py-3 text-right text-white/60">{{ planet.entries.toLocaleString() }}</td>
                                    <td class="px-4 py-3 text-right text-white/60">{{ planet.share.toFixed(1) }}%</td>
                                </tr>
                                <tr v-if="!data.planets.length">
                                    <td colspan="5" class="px-4 py-10 text-center text-white/40">No planets in this period.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <p class="text-right text-xs text-white/35">Last archive sync: {{ formatDateTime(data.coverage.last_synced_at) }} UTC</p>

            <div v-if="isLoading" class="absolute inset-0 z-20 flex min-h-80 items-center justify-center bg-slate-950/65 backdrop-blur-sm">
                <span class="flex items-center gap-3 text-sky-200"><i class="fas fa-spinner fa-spin"></i>Loading PI revenue…</span>
            </div>
        </div>
    </AppLayout>
</template>
