<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { debounce } from '@/lib/debounce';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';
import StatusDot from '@/components/ui/StatusDot.vue';
import type { PageProps, RouterSummary } from '@/types';

interface PaginatedRouters {
    data: RouterSummary[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

const props = defineProps<{
    routers: PaginatedRouters;
    filters: { search: string; status: string };
    statusOptions: { value: string; label: string }[];
}>();

const page = usePage<PageProps>();
const can = page.props.auth.can;

const query = reactive({ search: props.filters.search ?? '', status: props.filters.status ?? '' });

const apply = debounce(() => {
    router.get(route('routers.index'), { search: query.search || undefined, status: query.status || undefined }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch(() => ({ ...query }), apply);

function test(id: number) {
    router.post(route('routers.test', id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Routers" />
    <AppLayout title="Routers">
        <div class="w-full space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <input
                    v-model="query.search"
                    type="search"
                    placeholder="Search name, host, identity…"
                    class="w-64 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800"
                />
                <select
                    v-model="query.status"
                    class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800"
                >
                    <option value="">All statuses</option>
                    <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <span class="text-xs text-zinc-500">{{ routers.total }} total</span>

                <Link v-if="can['router.manage']" :href="route('routers.create')" class="ml-auto">
                    <Btn variant="primary">+ Add router</Btn>
                </Link>
            </div>

            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 font-medium">Name</th>
                            <th class="px-5 py-3 font-medium">Host</th>
                            <th class="hidden px-5 py-3 font-medium md:table-cell">Version</th>
                            <th class="hidden px-5 py-3 font-medium lg:table-cell">Site</th>
                            <th class="px-5 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-if="routers.data.length === 0">
                            <td colspan="6" class="px-5 py-10 text-center text-zinc-500">No routers match.</td>
                        </tr>
                        <tr v-for="r in routers.data" :key="r.id" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="px-5 py-3">
                                <StatusDot :color="r.status_color" :label="r.status_label" :pulse="r.status === 'online'" />
                            </td>
                            <td class="px-5 py-3">
                                <Link :href="route('routers.show', r.id)" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                    {{ r.name }}
                                </Link>
                                <div v-if="r.identity" class="text-xs text-zinc-500">{{ r.identity }}</div>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs">{{ r.host }}</td>
                            <td class="hidden px-5 py-3 text-xs text-zinc-500 md:table-cell">{{ r.routeros_version ?? '—' }}</td>
                            <td class="hidden px-5 py-3 text-xs text-zinc-500 lg:table-cell">{{ r.site ?? '—' }}</td>
                            <td class="px-5 py-3 text-right">
                                <Btn size="sm" @click="test(r.id)">Test</Btn>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="routers.links.length > 3" class="flex flex-wrap gap-1">
                <component
                    :is="link.url ? Link : 'span'"
                    v-for="(link, i) in routers.links"
                    :key="i"
                    :href="link.url ?? undefined"
                    class="rounded-md px-3 py-1.5 text-xs"
                    :class="[
                        link.active ? 'bg-indigo-600 text-white' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800',
                        !link.url && 'cursor-default opacity-40',
                    ]"
                    v-html="link.label"
                />
            </div>
        </div>
    </AppLayout>
</template>
