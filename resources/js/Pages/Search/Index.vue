<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router as inertia } from '@inertiajs/vue3';
import { debounce } from '@/lib/debounce';
import AppLayout from '@/layouts/AppLayout.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

interface RouterResult {
    id: number;
    name: string;
    host: string;
    status: string;
    status_color: string;
    identity: string | null;
    routeros_version: string | null;
}

interface GroupResult {
    id: number;
    name: string;
    type: string;
}

interface BackupResult {
    id: number;
    filename: string;
    router_id: number;
    router_name: string;
    size: number | null;
    created_at: string | null;
}

interface AuditResult {
    id: number;
    action: string;
    user_name: string;
    router_name: string | null;
    router_id: number | null;
    ip_address: string | null;
    created_at: string | null;
}

const props = defineProps<{
    q: string;
    routers: RouterResult[];
    groups: GroupResult[];
    backups: BackupResult[];
    auditLogs: AuditResult[];
}>();

const activeTab = ref<'all' | 'routers' | 'groups' | 'backups' | 'audit'>('all');
const state = reactive({ q: props.q });

const search = debounce(() => {
    inertia.get(
        route('search.index'),
        { q: state.q || undefined },
        { preserveState: true, replace: true },
    );
}, 250);

watch(() => state.q, search);

const totalResults = computed(() => {
    return props.routers.length + props.groups.length + props.backups.length + props.auditLogs.length;
});

function formatBytes(bytes?: number | null): string {
    if (!bytes) return '-';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KiB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MiB`;
}

function setQuery(sample: string) {
    state.q = sample;
    search();
}
</script>

<template>
    <Head title="Global Search" />
    <AppLayout title="Global Network Search">
        <div class="w-full space-y-6">
            <!-- Search bar header -->
            <div class="relative">
                <input
                    v-model="state.q"
                    type="search"
                    autofocus
                    placeholder="Search anything: router name, IP host, identity, backup files, audit logs..."
                    class="w-full rounded-xl border border-zinc-300 bg-white px-5 py-3.5 pr-10 text-sm shadow-xs focus:border-indigo-500 focus:outline-hidden focus:ring-2 focus:ring-indigo-500/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                />
                <button
                    v-if="state.q"
                    type="button"
                    class="absolute right-3.5 top-3.5 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
                    @click="state.q = ''; search()"
                >
                    ✕
                </button>
            </div>

            <!-- Suggestion pills -->
            <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                <span class="font-medium text-zinc-400">Pencarian Cepat:</span>
                <button
                    v-for="s in ['192.168', 'core', 'backup', 'reboot', 'hotspot', 'ppp']"
                    :key="s"
                    type="button"
                    class="rounded-md border border-zinc-200 bg-white px-2 py-0.5 hover:border-indigo-300 hover:text-indigo-600 dark:border-zinc-800 dark:bg-zinc-800/80 dark:hover:border-indigo-500"
                    @click="setQuery(s)"
                >
                    {{ s }}
                </button>
            </div>

            <!-- Category Tabs -->
            <div v-if="q" class="flex flex-wrap items-center gap-2 border-b border-zinc-200 pb-2 text-xs dark:border-zinc-800">
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 font-medium transition-colors"
                    :class="activeTab === 'all'
                        ? 'bg-indigo-600 text-white shadow-xs'
                        : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                    @click="activeTab = 'all'"
                >
                    Semua Hasil ({{ totalResults }})
                </button>
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 font-medium transition-colors"
                    :class="activeTab === 'routers'
                        ? 'bg-indigo-600 text-white shadow-xs'
                        : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                    @click="activeTab = 'routers'"
                >
                    Routers ({{ routers.length }})
                </button>
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 font-medium transition-colors"
                    :class="activeTab === 'backups'
                        ? 'bg-indigo-600 text-white shadow-xs'
                        : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                    @click="activeTab = 'backups'"
                >
                    Backups ({{ backups.length }})
                </button>
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 font-medium transition-colors"
                    :class="activeTab === 'groups'
                        ? 'bg-indigo-600 text-white shadow-xs'
                        : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                    @click="activeTab = 'groups'"
                >
                    Groups ({{ groups.length }})
                </button>
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 font-medium transition-colors"
                    :class="activeTab === 'audit'
                        ? 'bg-indigo-600 text-white shadow-xs'
                        : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                    @click="activeTab = 'audit'"
                >
                    Audit Logs ({{ auditLogs.length }})
                </button>
            </div>

            <!-- Empty state when searching but no results -->
            <div v-if="q && totalResults === 0" class="rounded-xl border border-dashed border-zinc-200 py-16 text-center text-sm text-zinc-500 dark:border-zinc-800">
                Tidak ada hasil yang cocok dengan kata kunci “{{ q }}”.
            </div>

            <!-- Results Section -->
            <div v-if="totalResults > 0" class="space-y-6">
                <!-- 1. Routers -->
                <div v-if="(activeTab === 'all' || activeTab === 'routers') && routers.length" class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/75 px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900/60">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Routers &amp; Gateway Devices ({{ routers.length }})</span>
                    </div>
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <li v-for="r in routers" :key="r.id" class="p-3 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <Link :href="route('routers.show', r.id)" class="flex items-center gap-3">
                                    <StatusDot :color="r.status_color" :pulse="r.status === 'online'" />
                                    <div>
                                        <div class="font-semibold text-zinc-900 hover:underline dark:text-zinc-100">
                                            {{ r.name }}
                                        </div>
                                        <div class="flex items-center gap-2 text-xs text-zinc-500 font-mono">
                                            <span>{{ r.host }}</span>
                                            <span v-if="r.identity" class="font-sans text-zinc-400">· {{ r.identity }}</span>
                                            <span v-if="r.routeros_version" class="rounded bg-zinc-100 px-1 text-[10px] dark:bg-zinc-800">v{{ r.routeros_version }}</span>
                                        </div>
                                    </div>
                                </Link>

                                <!-- Module Jump Shortcuts -->
                                <div class="flex flex-wrap items-center gap-1.5 text-xs">
                                    <Link
                                        :href="route('routers.hotspot.index', r.id)"
                                        class="rounded-md border border-zinc-200 bg-white px-2 py-1 font-medium text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                    >
                                        Hotspot
                                    </Link>
                                    <Link
                                        :href="route('routers.ppp.index', r.id)"
                                        class="rounded-md border border-zinc-200 bg-white px-2 py-1 font-medium text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                    >
                                        PPP
                                    </Link>
                                    <Link
                                        :href="route('routers.ip.dhcp.index', r.id)"
                                        class="rounded-md border border-zinc-200 bg-white px-2 py-1 font-medium text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                    >
                                        DHCP
                                    </Link>
                                    <Link
                                        :href="route('routers.firewall.index', r.id)"
                                        class="rounded-md border border-zinc-200 bg-white px-2 py-1 font-medium text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                    >
                                        Firewall
                                    </Link>
                                    <Link
                                        :href="route('routers.queues.index', r.id)"
                                        class="rounded-md border border-zinc-200 bg-white px-2 py-1 font-medium text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                    >
                                        Queues
                                    </Link>
                                    <Link
                                        :href="route('routers.system.index', r.id)"
                                        class="rounded-md border border-zinc-200 bg-white px-2 py-1 font-medium text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                    >
                                        System
                                    </Link>
                                    <Link
                                        :href="route('routers.terminal.index', r.id)"
                                        class="rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1 font-mono font-medium text-indigo-700 hover:bg-indigo-100 dark:border-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-300"
                                    >
                                        Terminal
                                    </Link>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- 2. Backups -->
                <div v-if="(activeTab === 'all' || activeTab === 'backups') && backups.length" class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/75 px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900/60">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Backup Archives ({{ backups.length }})</span>
                    </div>
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <li v-for="b in backups" :key="b.id" class="flex items-center justify-between px-4 py-2.5 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <div class="flex items-center gap-3">
                                <span class="text-base">💾</span>
                                <div>
                                    <div class="font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ b.filename }}</div>
                                    <div class="text-xs text-zinc-500">
                                        Router: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ b.router_name }}</span> · Size: {{ formatBytes(b.size) }} · {{ b.created_at }}
                                    </div>
                                </div>
                            </div>
                            <Link
                                :href="route('routers.backups.index', b.router_id)"
                                class="text-xs text-indigo-600 hover:underline dark:text-indigo-400"
                            >
                                Kelola Backups →
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- 3. Groups -->
                <div v-if="(activeTab === 'all' || activeTab === 'groups') && groups.length" class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/75 px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900/60">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Router Groups ({{ groups.length }})</span>
                    </div>
                    <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <li v-for="g in groups" :key="g.id" class="flex items-center justify-between px-4 py-2.5 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <div>
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ g.name }}</span>
                                <span class="ml-2 rounded bg-zinc-100 px-1.5 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800">{{ g.type }}</span>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- 4. Audit Logs -->
                <div v-if="(activeTab === 'all' || activeTab === 'audit') && auditLogs.length" class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/75 px-4 py-2.5 dark:border-zinc-800 dark:bg-zinc-900/60">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Aktivitas &amp; Audit Logs ({{ auditLogs.length }})</span>
                    </div>
                    <ul class="divide-y divide-zinc-100 text-xs dark:divide-zinc-800">
                        <li v-for="a in auditLogs" :key="a.id" class="flex items-center justify-between px-4 py-2.5 transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                            <div>
                                <span class="font-mono font-medium text-zinc-800 dark:text-zinc-200">{{ a.action }}</span>
                                <span class="ml-2 text-zinc-500">oleh {{ a.user_name }}</span>
                                <span v-if="a.router_name" class="ml-2 text-indigo-600 dark:text-indigo-400">@ {{ a.router_name }}</span>
                                <span v-if="a.ip_address" class="ml-1 text-zinc-400">({{ a.ip_address }})</span>
                            </div>
                            <span class="text-zinc-400">{{ a.created_at }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
