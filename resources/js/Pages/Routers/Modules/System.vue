<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router as inertia, useForm } from '@inertiajs/vue3';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface LogRow {
    '.id': string;
    time?: string;
    topics?: string;
    message?: string;
}

interface Script {
    '.id': string;
    name: string;
    owner?: string;
    'run-count'?: string;
    source?: string;
}

interface SchedulerItem {
    '.id': string;
    name: string;
    'start-time'?: string;
    interval?: string;
    'on-event'?: string;
    'run-count'?: string;
    disabled?: string;
    comment?: string;
}

const props = defineProps<{
    router: any;
    logs: LogRow[];
    scripts: Script[];
    schedulers?: SchedulerItem[];
    resource: Record<string, string>;
    identity?: Record<string, string>;
    deviceError: string | null;
    can: { manage: boolean };
}>();

const actualSchedulers = computed(() => props.schedulers ?? []);

type SystemTab = 'resources' | 'schedulers' | 'scripts' | 'logs';
const activeTab = ref<SystemTab>('resources');

const logSearch = ref('');
const topicFilter = ref('all');
const scriptSearch = ref('');
const schedulerSearch = ref('');
const selectedId = ref<string | null>(null);

// ── Modals & Forms ───────────────────────────────────────────────────
const showAddScript = ref(false);
const showAddScheduler = ref(false);
const showEditIdentity = ref(false);

const scriptForm = useForm({
    name: '',
    source: '',
    comment: '',
});

const schedulerForm = useForm({
    name: '',
    interval: '1d',
    on_event: '',
    start_time: 'startup',
    comment: '',
});

const identityForm = useForm({
    name: props.identity?.name ?? props.router.name,
});

function runScript(s: Script) {
    if (confirm(`Run script "${s.name}" on the device?`)) {
        inertia.post(
            route('routers.system.scripts.run', props.router.id),
            { id: s['.id'] },
            { preserveScroll: true },
        );
    }
}

function removeScript(s: Script) {
    if (!confirm(`Remove script "${s.name}"?`)) return;
    inertia.delete(route('routers.system.scripts.destroy', props.router.id), {
        data: { id: s['.id'] },
        preserveScroll: true,
    });
}

function saveScript() {
    scriptForm.post(route('routers.system.scripts.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddScript.value = false;
            scriptForm.reset();
        },
    });
}

function saveScheduler() {
    schedulerForm.post(route('routers.system.schedulers.store', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showAddScheduler.value = false;
            schedulerForm.reset();
        },
    });
}

function toggleScheduler(item: SchedulerItem, disable: boolean) {
    inertia.post(
        route('routers.system.schedulers.toggle', props.router.id),
        { id: item['.id'], disable },
        { preserveScroll: true },
    );
}

function removeScheduler(item: SchedulerItem) {
    if (!confirm(`Remove scheduler "${item.name}"?`)) return;
    inertia.delete(route('routers.system.schedulers.destroy', props.router.id), {
        data: { id: item['.id'] },
        preserveScroll: true,
    });
}

function openEditIdentity() {
    identityForm.name = props.identity?.name || props.router.name;
    showEditIdentity.value = true;
}

function saveIdentity() {
    identityForm.post(route('routers.system.identity.update', props.router.id), {
        preserveScroll: true,
        onSuccess: () => {
            showEditIdentity.value = false;
        },
    });
}

function triggerReboot() {
    if (confirm(`Reboot router "${props.router.name}"? Active connections will be dropped.`)) {
        inertia.post(route('routers.system.reboot', props.router.id));
    }
}

const memPct = () => {
    const free = Number(props.resource['free-memory'] ?? 0);
    const total = Number(props.resource['total-memory'] ?? 1);
    return total ? Math.round(((total - free) / total) * 100) : 0;
};

const fmtBytes = (bytesStr?: string) => {
    const n = Number(bytesStr ?? 0);
    if (!n) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(n) / Math.log(1024));
    return `${(n / 1024 ** i).toFixed(1)} ${u[i]}`;
};

const uniqueTopics = computed(() => {
    const set = new Set<string>();
    for (const l of props.logs) {
        if (l.topics) {
            for (const t of l.topics.split(',')) {
                if (t.trim()) set.add(t.trim());
            }
        }
    }
    return Array.from(set).sort();
});

const filteredLogs = computed(() => {
    const q = logSearch.value.trim().toLowerCase();
    return props.logs.filter((l) => {
        if (topicFilter.value !== 'all' && !(l.topics ?? '').includes(topicFilter.value)) return false;
        if (!q) return true;
        return (
            (l.message ?? '').toLowerCase().includes(q) ||
            (l.topics ?? '').toLowerCase().includes(q) ||
            (l.time ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredScripts = computed(() => {
    const q = scriptSearch.value.trim().toLowerCase();
    return props.scripts.filter((s) => {
        if (!q) return true;
        return (
            s.name.toLowerCase().includes(q) ||
            (s.owner ?? '').toLowerCase().includes(q) ||
            (s.source ?? '').toLowerCase().includes(q)
        );
    });
});

const filteredSchedulers = computed(() => {
    const q = schedulerSearch.value.trim().toLowerCase();
    return actualSchedulers.value.filter((sc) => {
        if (!q) return true;
        return (
            sc.name.toLowerCase().includes(q) ||
            (sc['on-event'] ?? '').toLowerCase().includes(q) ||
            (sc.interval ?? '').toLowerCase().includes(q)
        );
    });
});

function refreshData() {
    inertia.reload({ preserveScroll: true });
}
</script>

<template>
    <Head :title="`System — ${router.name}`" />

    <RouterWorkspace :router="router" :device-error="deviceError">
        <div class="flex h-full flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <!-- WinBox Header with Tabs -->
            <div class="border-b border-zinc-200 bg-zinc-50/75 px-4 pt-3 dark:border-zinc-800 dark:bg-zinc-900/60">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">System</span>
                        <span class="rounded bg-zinc-200/70 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                            /system
                        </span>
                        <span class="ml-2 rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300">
                            Identity: {{ identity?.name || router.name }}
                        </span>
                    </div>

                    <div v-if="can.manage" class="flex items-center gap-2">
                        <Btn size="sm" variant="secondary" @click="openEditIdentity">
                            🏷 Set Identity
                        </Btn>
                        <Btn size="sm" variant="secondary" class="text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/30" @click="triggerReboot">
                            ⟳ Reboot
                        </Btn>
                    </div>
                </div>

                <div class="flex gap-1 overflow-x-auto text-xs">
                    <button
                        class="border-b-2 px-3 py-2 font-medium transition-colors"
                        :class="activeTab === 'resources'
                            ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                            : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                        @click="activeTab = 'resources'"
                    >
                        Resources
                    </button>
                    <button
                        class="border-b-2 px-3 py-2 font-medium transition-colors"
                        :class="activeTab === 'schedulers'
                            ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                            : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                        @click="activeTab = 'schedulers'"
                    >
                        Scheduler ({{ actualSchedulers.length }})
                    </button>
                    <button
                        class="border-b-2 px-3 py-2 font-medium transition-colors"
                        :class="activeTab === 'scripts'
                            ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                            : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                        @click="activeTab = 'scripts'"
                    >
                        Scripts ({{ scripts.length }})
                    </button>
                    <button
                        class="border-b-2 px-3 py-2 font-medium transition-colors"
                        :class="activeTab === 'logs'
                            ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                            : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200'"
                        @click="activeTab = 'logs'"
                    >
                        Log ({{ logs.length }})
                    </button>
                </div>
            </div>

            <!-- Tab Content -->
            <div class="flex-1 overflow-auto">
                <!-- 1. Resources Tab -->
                <div v-if="activeTab === 'resources'" class="space-y-6 p-6">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/40">
                            <div class="text-xs font-medium text-zinc-500">CPU Load</div>
                            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                                {{ resource['cpu-load'] ?? 0 }}%
                            </div>
                            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                <div class="h-full bg-indigo-600 transition-all" :style="{ width: `${resource['cpu-load'] ?? 0}%` }" />
                            </div>
                            <div class="mt-1 text-[11px] text-zinc-400">{{ resource['cpu-count'] ?? 1 }} Cores ({{ resource.cpu ?? 'ARM' }})</div>
                        </div>

                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/40">
                            <div class="text-xs font-medium text-zinc-500">Memory Usage</div>
                            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                                {{ memPct() }}%
                            </div>
                            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                <div class="h-full bg-indigo-600 transition-all" :style="{ width: `${memPct()}%` }" />
                            </div>
                            <div class="mt-1 text-[11px] text-zinc-400">
                                Free: {{ fmtBytes(resource['free-memory']) }} / Total: {{ fmtBytes(resource['total-memory']) }}
                            </div>
                        </div>

                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/40">
                            <div class="text-xs font-medium text-zinc-500">RouterOS Version</div>
                            <div class="mt-1 font-mono text-xl font-bold text-zinc-900 dark:text-zinc-100">
                                {{ resource.version ?? '-' }}
                            </div>
                            <div class="mt-2 text-xs text-zinc-400">Architecture: {{ resource['architecture-name'] ?? '-' }}</div>
                        </div>

                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/40">
                            <div class="text-xs font-medium text-zinc-500">System Uptime</div>
                            <div class="mt-1 font-mono text-xl font-bold text-emerald-600 dark:text-emerald-400">
                                {{ resource.uptime ?? '-' }}
                            </div>
                            <div class="mt-2 text-xs text-zinc-400">Board: {{ resource['board-name'] ?? '-' }}</div>
                        </div>
                    </div>

                    <!-- Details Table -->
                    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="border-b border-zinc-200 bg-zinc-50/50 px-4 py-2.5 text-xs font-semibold text-zinc-700 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-300">
                            Detailed System Hardware Info
                        </div>
                        <table class="w-full text-left text-xs font-mono">
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                <tr v-for="(v, k) in resource" :key="k" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="w-1/3 px-4 py-2 text-zinc-500">{{ k }}</td>
                                    <td class="px-4 py-2 text-zinc-900 dark:text-zinc-100">{{ v }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 2. Scheduler Tab -->
                <div v-else-if="activeTab === 'schedulers'" class="flex h-full flex-col">
                    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                        <div class="flex items-center gap-2">
                            <Btn v-if="can.manage" size="sm" variant="primary" class="gap-1.5" @click="showAddScheduler = true">
                                <span>＋</span> Add Scheduler
                            </Btn>
                            <Btn size="sm" variant="ghost" @click="refreshData">↻ Refresh</Btn>
                        </div>
                        <input
                            v-model="schedulerSearch"
                            type="text"
                            placeholder="Search schedulers..."
                            class="w-48 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>

                    <div class="flex-1 overflow-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                                <tr>
                                    <th class="w-8 px-3 py-2 text-center">#</th>
                                    <th class="px-3 py-2">Name</th>
                                    <th class="px-3 py-2">Start Time</th>
                                    <th class="px-3 py-2">Interval</th>
                                    <th class="px-3 py-2">On Event</th>
                                    <th class="px-3 py-2 text-right">Run Count</th>
                                    <th class="px-3 py-2">Comment</th>
                                    <th v-if="can.manage" class="w-24 px-3 py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                                <tr
                                    v-for="sc in filteredSchedulers"
                                    :key="sc['.id']"
                                    class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40"
                                    :class="sc.disabled === 'true' ? 'opacity-50 line-through' : ''"
                                >
                                    <td class="px-3 py-2 text-center font-sans">
                                        <StatusDot :color="sc.disabled === 'true' ? 'gray' : 'emerald'" />
                                    </td>
                                    <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ sc.name }}</td>
                                    <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ sc['start-time'] || 'startup' }}</td>
                                    <td class="px-3 py-2 font-semibold text-indigo-700 dark:text-indigo-400">{{ sc.interval || '-' }}</td>
                                    <td class="px-3 py-2 text-zinc-800 dark:text-zinc-200">{{ sc['on-event'] }}</td>
                                    <td class="px-3 py-2 text-right text-zinc-500">{{ sc['run-count'] || '0' }}</td>
                                    <td class="px-3 py-2 font-sans italic text-zinc-500">{{ sc.comment || '-' }}</td>
                                    <td v-if="can.manage" class="px-3 py-2 text-right font-sans">
                                        <div class="flex items-center justify-end gap-1">
                                            <button
                                                class="rounded px-1.5 py-0.5 text-xs text-zinc-600 hover:bg-zinc-200 dark:text-zinc-400 dark:hover:bg-zinc-700"
                                                :title="sc.disabled === 'true' ? 'Enable' : 'Disable'"
                                                @click="toggleScheduler(sc, sc.disabled !== 'true')"
                                            >
                                                {{ sc.disabled === 'true' ? '✓' : '✕' }}
                                            </button>
                                            <button
                                                class="rounded px-1.5 py-0.5 text-xs text-rose-600 hover:bg-rose-100 dark:text-rose-400 dark:hover:bg-rose-950/40"
                                                title="Remove"
                                                @click="removeScheduler(sc)"
                                            >
                                                🗑
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredSchedulers.length === 0">
                                    <td colspan="8" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                        No scheduled tasks found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 3. Scripts Tab -->
                <div v-else-if="activeTab === 'scripts'" class="flex h-full flex-col">
                    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                        <div class="flex items-center gap-2">
                            <Btn v-if="can.manage" size="sm" variant="primary" class="gap-1.5" @click="showAddScript = true">
                                <span>＋</span> Add Script
                            </Btn>
                            <Btn size="sm" variant="ghost" @click="refreshData">↻ Refresh</Btn>
                        </div>
                        <input
                            v-model="scriptSearch"
                            type="text"
                            placeholder="Search scripts..."
                            class="w-48 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>

                    <div class="flex-1 overflow-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                                <tr>
                                    <th class="px-3 py-2">Name</th>
                                    <th class="px-3 py-2">Owner</th>
                                    <th class="px-3 py-2">Source Code</th>
                                    <th class="px-3 py-2 text-right">Run Count</th>
                                    <th v-if="can.manage" class="w-32 px-3 py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200/70 font-mono text-[11px] dark:divide-zinc-800/70">
                                <tr v-for="s in filteredScripts" :key="s['.id']" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                                    <td class="px-3 py-2 font-semibold text-zinc-900 dark:text-zinc-100">{{ s.name }}</td>
                                    <td class="px-3 py-2 text-zinc-500">{{ s.owner || 'admin' }}</td>
                                    <td class="max-w-md truncate px-3 py-2 text-zinc-700 dark:text-zinc-300">{{ s.source }}</td>
                                    <td class="px-3 py-2 text-right text-zinc-500">{{ s['run-count'] || '0' }}</td>
                                    <td v-if="can.manage" class="px-3 py-2 text-right font-sans">
                                        <div class="flex items-center justify-end gap-1">
                                            <Btn size="sm" variant="secondary" @click="runScript(s)">
                                                ▶ Run
                                            </Btn>
                                            <button
                                                class="rounded px-1.5 py-0.5 text-xs text-rose-600 hover:bg-rose-100 dark:text-rose-400 dark:hover:bg-rose-950/40"
                                                title="Remove"
                                                @click="removeScript(s)"
                                            >
                                                🗑
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="filteredScripts.length === 0">
                                    <td colspan="5" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                        No scripts found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 4. Logs Tab -->
                <div v-else-if="activeTab === 'logs'" class="flex h-full flex-col">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/50 px-4 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
                        <div class="flex items-center gap-2">
                            <select
                                v-model="topicFilter"
                                class="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <option value="all">All Topics</option>
                                <option v-for="t in uniqueTopics" :key="t" :value="t">{{ t }}</option>
                            </select>
                            <Btn size="sm" variant="ghost" @click="refreshData">↻ Refresh</Btn>
                        </div>
                        <input
                            v-model="logSearch"
                            type="text"
                            placeholder="Filter logs..."
                            class="w-48 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>

                    <div class="flex-1 overflow-auto">
                        <table class="w-full text-left text-xs font-mono">
                            <thead class="sticky top-0 border-b border-zinc-200 bg-zinc-100/90 font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-800 dark:bg-zinc-800/90 dark:text-zinc-400">
                                <tr>
                                    <th class="w-36 px-3 py-2">Time</th>
                                    <th class="w-40 px-3 py-2">Topics</th>
                                    <th class="px-3 py-2">Message</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200/70 text-[11px] dark:divide-zinc-800/70">
                                <tr v-for="l in filteredLogs" :key="l['.id']" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                    <td class="whitespace-nowrap px-3 py-1.5 text-zinc-500">{{ l.time }}</td>
                                    <td class="whitespace-nowrap px-3 py-1.5">
                                        <span class="rounded bg-zinc-100 px-1 py-0.5 text-[10px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                            {{ l.topics }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-1.5 text-zinc-900 dark:text-zinc-100">{{ l.message }}</td>
                                </tr>
                                <tr v-if="filteredLogs.length === 0">
                                    <td colspan="3" class="px-4 py-8 text-center font-sans text-xs text-zinc-400">
                                        No log records found.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-between border-t border-zinc-200 bg-zinc-50/80 px-4 py-1.5 text-[11px] text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/60">
                <span class="font-mono text-zinc-600 dark:text-zinc-400">
                    RouterOS {{ resource.version || '7.x' }} — Board: {{ resource['board-name'] || 'MikroTik' }}
                </span>
                <span class="font-mono text-[10px] text-zinc-400">
                    MikWeb Device Controller
                </span>
            </div>
        </div>

        <!-- Modal: Set Identity -->
        <Modal :show="showEditIdentity" title="Change Router Identity" @close="showEditIdentity = false">
            <form class="space-y-3" @submit.prevent="saveIdentity">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Identity Name</label>
                    <input
                        v-model="identityForm.name"
                        type="text"
                        required
                        placeholder="e.g. Core-Router-HQ"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showEditIdentity = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="identityForm.processing">Save Identity</Btn>
                </div>
            </form>
        </Modal>

        <!-- Modal: Add Scheduler -->
        <Modal :show="showAddScheduler" title="New Scheduled Task" @close="showAddScheduler = false">
            <form class="space-y-3" @submit.prevent="saveScheduler">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Task Name</label>
                    <input
                        v-model="schedulerForm.name"
                        type="text"
                        required
                        placeholder="e.g. daily-reboot or backup-job"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Interval</label>
                        <input
                            v-model="schedulerForm.interval"
                            type="text"
                            required
                            placeholder="e.g. 1d, 1h, 10m"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Start Time</label>
                        <input
                            v-model="schedulerForm.start_time"
                            type="text"
                            placeholder="startup or 03:00:00"
                            class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                        />
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">On Event (RouterOS CLI Command)</label>
                    <textarea
                        v-model="schedulerForm.on_event"
                        required
                        rows="2"
                        placeholder="e.g. /system script run daily-backup"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Comment</label>
                    <input
                        v-model="schedulerForm.comment"
                        type="text"
                        placeholder="Note"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showAddScheduler = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="schedulerForm.processing">Add Task</Btn>
                </div>
            </form>
        </Modal>

        <!-- Modal: Add Script -->
        <Modal :show="showAddScript" title="New RouterOS Script" @close="showAddScript = false">
            <form class="space-y-3" @submit.prevent="saveScript">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Script Name</label>
                    <input
                        v-model="scriptForm.name"
                        type="text"
                        required
                        placeholder="e.g. backup-wan"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Script Source</label>
                    <textarea
                        v-model="scriptForm.source"
                        required
                        rows="4"
                        placeholder="/system backup save name=auto"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-1.5 font-mono text-xs dark:border-zinc-700 dark:bg-zinc-800"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <Btn type="button" variant="secondary" @click="showAddScript = false">Cancel</Btn>
                    <Btn type="submit" variant="primary" :disabled="scriptForm.processing">Save Script</Btn>
                </div>
            </form>
        </Modal>
    </RouterWorkspace>
</template>
