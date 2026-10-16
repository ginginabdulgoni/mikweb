<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router as inertia, usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types';
import FlashMessages from '@/components/FlashMessages.vue';
import StatusDot from '@/components/ui/StatusDot.vue';

defineProps<{ title?: string }>();

const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user);
const activeRouter = computed(() => page.props.nav?.activeRouter ?? null);
const routers = computed(() => page.props.nav?.routers ?? []);

// ── Global navigation (not tied to a router) ─────────────────────────
interface NavItem { label: string; route?: string; icon: string; ready: boolean }
const globalNav: NavItem[] = [
    { label: 'Dashboard', route: 'dashboard', icon: '▣', ready: true },
    { label: 'Routers', route: 'routers.index', icon: '⧉', ready: true },
    { label: 'Monitoring', route: 'monitoring.index', icon: '📈', ready: true },
    { label: 'Network Map', route: 'network.map', icon: '🗺️', ready: true },
    { label: 'Automation', route: 'automation.index', icon: '⚙', ready: true },
    { label: 'Bulk Ops', route: 'bulk.index', icon: '≣', ready: true },
    { label: 'Search', route: 'search.index', icon: '⌕', ready: true },
    { label: 'Notifikasi', route: 'settings.notifications.edit', icon: '🔔', ready: true },
    { label: 'System Update', route: 'system.update.index', icon: '🚀', ready: true },
];

// ── WinBox-style device menu (requires an active router) ─────────────
interface DeviceItem { label: string; route?: string; ready: boolean; indent?: boolean }
interface DeviceGroup { label: string; icon: string; items: DeviceItem[] }

const deviceMenu = computed<DeviceGroup[]>(() => [
    { label: 'Overview', icon: '📊', items: [{ label: 'Device Overview', route: 'routers.show', ready: true }] },
    { label: 'Interfaces', icon: '⇄', items: [{ label: 'Interfaces', route: 'routers.interfaces.index', ready: true }] },
    { label: 'WireGuard', icon: '🛡', items: [{ label: 'WireGuard VPN', route: 'routers.wireguard.index', ready: true }] },
    { label: 'Bridge', icon: '⊞', items: [{ label: 'Bridge', route: 'routers.bridge.index', ready: true }] },
    { label: 'PPP', icon: '⇋', items: [{ label: 'PPP (Secrets & Active)', route: 'routers.ppp.index', ready: true }] },
    {
        label: 'IP', icon: '◎', items: [
            { label: 'ARP', route: 'routers.ip.arp.index', ready: true },
            { label: 'Addresses', route: 'routers.ip.addresses.index', ready: true },
            { label: 'DHCP Client', route: 'routers.ip.dhcp-client.index', ready: true },
            { label: 'DHCP Server', route: 'routers.ip.dhcp.index', ready: true },
            { label: 'DNS', route: 'routers.ip.dns.index', ready: true },
            { label: 'Firewall', route: 'routers.firewall.index', ready: true },
            { label: 'Hotspot', route: 'routers.hotspot.index', ready: true },
            { label: 'Cetak Voucher', route: 'routers.hotspot.vouchers.print', ready: true },
            { label: 'Neighbors', route: 'routers.ip.neighbors.index', ready: true },
            { label: 'Pool', route: 'routers.ip.pool.index', ready: true },
            { label: 'Routes', route: 'routers.ip.routes.index', ready: true },
            { label: 'Services', route: 'routers.ip.services.index', ready: true },
        ],
    },
    { label: 'Queues', icon: '≋', items: [{ label: 'Queues', route: 'routers.queues.index', ready: true }] },
    { label: 'Files', icon: '📁', items: [{ label: 'Files', route: 'routers.files.index', ready: true }] },
    { label: 'Log', icon: '📋', items: [{ label: 'Log', route: 'routers.log.index', ready: true }] },
    { label: 'RADIUS', icon: '🔑', items: [{ label: 'RADIUS', route: 'routers.radius.index', ready: true }] },
    {
        label: 'System', icon: '⚙', items: [
            { label: 'Resources & Scripts', route: 'routers.system.index', ready: true },
            { label: 'Users', route: 'routers.system.users.index', ready: true },
            { label: 'Backups', route: 'routers.backups.index', ready: true },
        ],
    },
    { label: 'Security Auditor', icon: '🔒', items: [{ label: 'Security Auditor', route: 'routers.security.audit', ready: true }] },
    { label: 'Tools', icon: '🛠', items: [{ label: 'Tools & Netwatch', route: 'routers.tools.index', ready: true }] },
    { label: 'Terminal', icon: '💻', items: [{ label: 'New Terminal', route: 'routers.terminal.index', ready: true }] },
]);

const collapsed = ref(false);
const isDark = ref(typeof document !== 'undefined' && document.documentElement.classList.contains('dark'));
const pickerOpen = ref(false);

function toggleTheme() {
    isDark.value = !isDark.value;
    document.documentElement.classList.toggle('dark', isDark.value);
    try { localStorage.setItem('mikweb-theme', isDark.value ? 'dark' : 'light'); } catch (e) { /* ignore */ }
}

function current(): string {
    const c = route().current();
    return typeof c === 'string' ? c : '';
}
function isGlobalActive(name?: string): boolean {
    return !!name && current() === name;
}
function isDeviceActive(name?: string): boolean {
    return !!name && current() === name;
}

function connect(id: number) {
    pickerOpen.value = false;
    const currentRoute = current();

    // If currently on any router-scoped page (e.g. interfaces, ppp, hotspot, firewall, etc.),
    // stay on the same page for the newly selected router.
    if (
        currentRoute &&
        currentRoute.startsWith('routers.') &&
        currentRoute !== 'routers.index' &&
        currentRoute !== 'routers.create'
    ) {
        try {
            // Keep query parameters (e.g. search, tab, filter) and replace router param with new id
            const currentParams = (route().params as Record<string, any>) || {};
            const params = { ...currentParams, router: id };
            inertia.get(route(currentRoute, params));
            return;
        } catch {
            try {
                inertia.get(route(currentRoute, id));
                return;
            } catch {
                // fallback to routers.show below
            }
        }
    }

    // If on monitoring page, switch the active router query parameter
    if (currentRoute === 'monitoring.index') {
        const url = new URL(window.location.href);
        url.searchParams.set('router', String(id));
        inertia.get(url.pathname + url.search);
        return;
    }

    // Default fallback to Device Overview
    inertia.get(route('routers.show', id));
}
function logout() {
    inertia.post(route('logout'));
}
</script>

<template>
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <aside
            class="flex shrink-0 flex-col border-r border-zinc-200 bg-white transition-all dark:border-zinc-800 dark:bg-zinc-900"
            :class="collapsed ? 'w-16' : 'w-60'"
        >
            <div class="flex h-14 items-center gap-2 border-b border-zinc-200 px-4 dark:border-zinc-800">
                <div class="flex h-7 w-7 items-center justify-center rounded-md bg-indigo-600 text-sm font-bold text-white">M</div>
                <span v-if="!collapsed" class="text-sm font-semibold tracking-tight">MikWeb</span>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto p-2">
                <!-- Global -->
                <div v-if="!collapsed" class="px-3 pb-1 pt-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-400">Overview</div>
                <Link
                    v-for="item in globalNav"
                    :key="item.label"
                    :href="route(item.route!)"
                    class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                    :class="isGlobalActive(item.route)
                        ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                        : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100'"
                >
                    <span class="w-5 text-center text-base">{{ item.icon }}</span>
                    <span v-if="!collapsed">{{ item.label }}</span>
                </Link>

                <!-- Device menu (WinBox-style), shown when connected to a router -->
                <template v-if="activeRouter">
                    <div v-if="!collapsed" class="mt-3 flex items-center gap-2 px-3 pb-1 pt-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-400">
                        <StatusDot :color="activeRouter.status_color" :pulse="activeRouter.status === 'online'" />
                        <span class="truncate">{{ activeRouter.name }}</span>
                    </div>

                    <div v-for="group in deviceMenu" :key="group.label" class="pt-0.5">
                        <!-- Group with a single item: render as a direct link -->
                        <template v-if="group.items.length === 1">
                            <Link
                                v-if="group.items[0].ready && group.items[0].route"
                                :href="route(group.items[0].route, activeRouter.id)"
                                class="flex items-center gap-3 rounded-lg px-3 py-1.5 text-sm font-medium"
                                :class="isDeviceActive(group.items[0].route)
                                    ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                                    : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100'"
                            >
                                <span class="w-5 text-center text-base">{{ group.icon }}</span>
                                <span v-if="!collapsed">{{ group.label }}</span>
                            </Link>
                            <div v-else class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-400 dark:text-zinc-600" :title="`${group.label} — coming soon`">
                                <span class="w-5 text-center text-base">{{ group.icon }}</span>
                                <template v-if="!collapsed"><span>{{ group.label }}</span><span class="ml-auto rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">soon</span></template>
                            </div>
                        </template>

                        <!-- Group with children -->
                        <template v-else>
                            <div class="flex items-center gap-3 px-3 py-1.5 text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                                <span class="w-5 text-center text-base">{{ group.icon }}</span>
                                <span v-if="!collapsed">{{ group.label }}</span>
                            </div>
                            <template v-if="!collapsed">
                                <template v-for="item in group.items" :key="item.label">
                                    <Link
                                        v-if="item.ready && item.route"
                                        :href="route(item.route, activeRouter.id)"
                                        class="ml-5 flex items-center gap-2 rounded-lg border-l border-zinc-200 py-1.5 pl-4 pr-3 text-sm dark:border-zinc-800"
                                        :class="isDeviceActive(item.route)
                                            ? 'text-indigo-700 dark:text-indigo-300'
                                            : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-100'"
                                    >
                                        {{ item.label }}
                                    </Link>
                                    <div v-else class="ml-5 flex items-center gap-2 border-l border-zinc-200 py-1.5 pl-4 pr-3 text-sm text-zinc-400 dark:border-zinc-800 dark:text-zinc-600">
                                        <span>{{ item.label }}</span>
                                        <span class="ml-auto rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] uppercase text-zinc-500 dark:bg-zinc-800">soon</span>
                                    </div>
                                </template>
                            </template>
                        </template>
                    </div>
                </template>
                <div v-else-if="!collapsed" class="mt-3 rounded-lg border border-dashed border-zinc-300 px-3 py-4 text-center text-xs text-zinc-400 dark:border-zinc-700">
                    Select a router in the top bar to open its menu.
                </div>
            </nav>

            <button class="border-t border-zinc-200 px-4 py-2.5 text-left text-xs text-zinc-500 hover:bg-zinc-100 dark:border-zinc-800 dark:hover:bg-zinc-800" @click="collapsed = !collapsed">
                {{ collapsed ? '»' : '« Collapse' }}
            </button>
        </aside>

        <!-- Main -->
        <div class="flex flex-1 flex-col overflow-hidden">
            <header class="flex h-14 shrink-0 items-center gap-4 border-b border-zinc-200 bg-white px-5 dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Router selector (session picker) -->
                <div class="relative">
                    <button
                        class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-1.5 text-sm hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                        @click="pickerOpen = !pickerOpen"
                    >
                        <StatusDot v-if="activeRouter" :color="activeRouter.status_color" :pulse="activeRouter.status === 'online'" />
                        <span class="font-medium">{{ activeRouter ? activeRouter.name : 'Select router' }}</span>
                        <span v-if="activeRouter" class="font-mono text-xs text-zinc-400">{{ activeRouter.host }}</span>
                        <span class="text-zinc-400">▾</span>
                    </button>
                    <div
                        v-if="pickerOpen"
                        class="absolute left-0 z-40 mt-1 max-h-80 w-72 overflow-y-auto rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                        @mouseleave="pickerOpen = false"
                    >
                        <div v-if="routers.length === 0" class="px-3 py-3 text-sm text-zinc-500">No routers yet.</div>
                        <button
                            v-for="r in routers"
                            :key="r.id"
                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800"
                            :class="activeRouter && activeRouter.id === r.id && 'bg-indigo-50 dark:bg-indigo-500/10'"
                            @click="connect(r.id)"
                        >
                            <StatusDot :color="r.status_color" />
                            <span class="flex-1 font-medium">{{ r.name }}</span>
                            <span class="font-mono text-xs text-zinc-400">{{ r.host }}</span>
                        </button>
                    </div>
                </div>

                <h1 class="truncate text-sm font-semibold text-zinc-500">{{ title }}</h1>

                <!-- Active router live stats (WinBox-style) -->
                <div v-if="activeRouter" class="hidden items-center gap-4 text-xs text-zinc-500 md:flex">
                    <span v-if="activeRouter.cpu_load != null">CPU <strong class="text-zinc-800 dark:text-zinc-200">{{ activeRouter.cpu_load }}%</strong></span>
                    <span v-if="activeRouter.mem_percent != null">MEM <strong class="text-zinc-800 dark:text-zinc-200">{{ activeRouter.mem_percent }}%</strong></span>
                    <span v-if="activeRouter.routeros_major">ROS v{{ activeRouter.routeros_major }}</span>
                </div>

                <div class="ml-auto flex items-center gap-3">
                    <button class="rounded-lg px-2 py-1.5 text-sm text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="toggleTheme">{{ isDark ? '☀' : '☾' }}</button>
                    <div class="flex items-center gap-2 border-l border-zinc-200 pl-3 dark:border-zinc-800">
                        <div class="text-right leading-tight">
                            <div class="text-sm font-medium">{{ user?.name }}</div>
                            <div class="text-[11px] text-zinc-500">{{ user?.organization?.name }}</div>
                        </div>
                        <Link :href="route('system.update.index')" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800">Update</Link>
                        <Link :href="route('settings.security')" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800">Security</Link>
                        <button class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="logout">Sign out</button>
                    </div>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto bg-zinc-50 p-6 dark:bg-zinc-950">
                <FlashMessages />
                <slot />
            </main>
        </div>
    </div>
</template>
