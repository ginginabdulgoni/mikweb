<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

interface VoucherUser {
    '.id': string;
    name: string;
    password?: string;
    profile?: string;
    'limit-uptime'?: string;
    'limit-bytes-total'?: string;
    comment?: string;
    disabled?: string;
}

const props = defineProps<{
    router: {
        id: number;
        name: string;
        host: string;
        identity?: string;
    };
    users: VoucherUser[];
    dnsName?: string;
}>();

const selectedFormat = ref<'a4' | 'thermal80' | 'thermal58'>('a4');
const filterProfile = ref<string>('all');
const filterComment = ref<string>('all');

const uniqueProfiles = computed(() => {
    const s = new Set<string>();
    props.users.forEach((u) => {
        if (u.profile) s.add(u.profile);
    });
    return Array.from(s);
});

const uniqueComments = computed(() => {
    const s = new Set<string>();
    props.users.forEach((u) => {
        if (u.comment) s.add(u.comment);
    });
    return Array.from(s);
});

const filteredVouchers = computed(() => {
    return props.users.filter((u) => {
        if (filterProfile.value !== 'all' && u.profile !== filterProfile.value) return false;
        if (filterComment.value !== 'all' && u.comment !== filterComment.value) return false;
        return true;
    });
});

function printNow() {
    window.print();
}

function getLoginUrl(u: VoucherUser): string {
    const host = props.dnsName || props.router.host;
    const pwd = u.password || u.name;
    return `http://${host}/login?username=${encodeURIComponent(u.name)}&password=${encodeURIComponent(pwd)}`;
}
</script>

<template>
    <Head :title="`Cetak Voucher - ${router.name}`" />

    <div class="min-h-screen bg-zinc-100 text-zinc-900 p-4 dark:bg-zinc-950 dark:text-zinc-100 print:bg-white print:p-0 print:text-black">
        <!-- Toolbar (Hidden on Print) -->
        <div class="mx-auto max-w-5xl mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 print:hidden">
            <div>
                <h1 class="text-base font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span>🖨️ Cetak Voucher Hotspot: {{ router.name }}</span>
                    <span class="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                        {{ filteredVouchers.length }} Voucher
                    </span>
                </h1>
                <p class="text-xs text-zinc-500">Pilih format kertas, filter batch, lalu klik tombol cetak.</p>
            </div>

            <!-- Controls -->
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <!-- Format Selector -->
                <div class="flex items-center rounded-lg border border-zinc-200 bg-zinc-50 p-0.5 dark:border-zinc-700 dark:bg-zinc-800">
                    <button
                        type="button"
                        class="rounded px-2.5 py-1 font-medium transition"
                        :class="selectedFormat === 'a4' ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white font-semibold' : 'text-zinc-500'"
                        @click="selectedFormat = 'a4'"
                    >
                        📄 Lembar A4 (Grid)
                    </button>
                    <button
                        type="button"
                        class="rounded px-2.5 py-1 font-medium transition"
                        :class="selectedFormat === 'thermal80' ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white font-semibold' : 'text-zinc-500'"
                        @click="selectedFormat = 'thermal80'"
                    >
                        🧾 Thermal 80mm
                    </button>
                    <button
                        type="button"
                        class="rounded px-2.5 py-1 font-medium transition"
                        :class="selectedFormat === 'thermal58' ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white font-semibold' : 'text-zinc-500'"
                        @click="selectedFormat = 'thermal58'"
                    >
                        🧾 Thermal 58mm
                    </button>
                </div>

                <!-- Profile Filter -->
                <select
                    v-model="filterProfile"
                    class="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                >
                    <option value="all">Semua Profile</option>
                    <option v-for="p in uniqueProfiles" :key="p" :value="p">{{ p }}</option>
                </select>

                <!-- Batch / Comment Filter -->
                <select
                    v-if="uniqueComments.length > 0"
                    v-model="filterComment"
                    class="rounded-lg border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800"
                >
                    <option value="all">Semua Batch / Comment</option>
                    <option v-for="c in uniqueComments" :key="c" :value="c">{{ c }}</option>
                </select>

                <!-- Back link -->
                <Link
                    :href="route('routers.hotspot.index', router.id)"
                    class="rounded-lg border border-zinc-300 px-3 py-1 text-zinc-600 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                >
                    ← Kembali
                </Link>

                <!-- Print Button -->
                <button
                    type="button"
                    class="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-1.5 font-bold text-white shadow-xs hover:bg-indigo-700"
                    @click="printNow"
                >
                    🖨️ Cetak Sekarang
                </button>
            </div>
        </div>

        <!-- Voucher Cards Container -->
        <div class="mx-auto" :class="{
            'max-w-5xl': selectedFormat === 'a4',
            'max-w-[80mm]': selectedFormat === 'thermal80',
            'max-w-[58mm]': selectedFormat === 'thermal58',
        }">
            <!-- A4 Grid Layout -->
            <div
                v-if="selectedFormat === 'a4'"
                class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 print:grid-cols-3 print:gap-2"
            >
                <div
                    v-for="u in filteredVouchers"
                    :key="u['.id']"
                    class="rounded-lg border-2 border-dashed border-zinc-300 bg-white p-3 text-zinc-900 shadow-xs print:border-black print:shadow-none break-inside-avoid"
                >
                    <!-- Voucher Header -->
                    <div class="flex items-center justify-between border-b border-zinc-200 pb-1.5 text-[10px] font-bold text-indigo-700 print:text-black">
                        <span>⚡ {{ router.identity || router.name }}</span>
                        <span class="rounded bg-indigo-50 px-1 py-0.2 uppercase text-indigo-800 print:border print:border-black">{{ u.profile || 'Hotspot' }}</span>
                    </div>

                    <!-- Voucher Body -->
                    <div class="my-2 text-center">
                        <div class="text-[9px] uppercase tracking-wider text-zinc-400 print:text-zinc-600">Kode Voucher / Username</div>
                        <div class="font-mono text-base font-black tracking-wider text-zinc-900 print:text-black">
                            {{ u.name }}
                        </div>
                        <div v-if="u.password && u.password !== u.name" class="mt-0.5 text-xs text-zinc-600">
                            Password: <span class="font-mono font-bold">{{ u.password }}</span>
                        </div>
                    </div>

                    <!-- Voucher Footer -->
                    <div class="border-t border-zinc-100 pt-1.5 flex items-center justify-between text-[10px] text-zinc-500 print:text-black">
                        <span>{{ u['limit-uptime'] ? `Durasi: ${u['limit-uptime']}` : 'Masa aktif: Sesuai profile' }}</span>
                        <span class="font-bold text-indigo-600 print:text-black">{{ u.comment || '' }}</span>
                    </div>
                </div>
            </div>

            <!-- Thermal Format Layout (58mm / 80mm) -->
            <div
                v-else
                class="space-y-4 print:space-y-2 font-mono text-xs"
            >
                <div
                    v-for="u in filteredVouchers"
                    :key="u['.id']"
                    class="rounded border border-dashed border-zinc-300 bg-white p-3 text-center text-black shadow-xs print:border-b-2 print:border-t-0 print:border-x-0 print:border-black print:p-2 print:shadow-none break-inside-avoid"
                >
                    <div class="font-bold text-sm uppercase tracking-wide">{{ router.identity || router.name }}</div>
                    <div class="text-[10px] border-b border-dashed border-black pb-1 mb-2">Voucher Akses Internet Hotspot</div>

                    <div class="text-[10px] text-zinc-600">KODE LOGIN:</div>
                    <div class="text-lg font-black tracking-widest my-1">{{ u.name }}</div>
                    <div v-if="u.password && u.password !== u.name" class="text-xs mb-1">
                        PIN: <strong>{{ u.password }}</strong>
                    </div>

                    <div class="border-t border-dashed border-black pt-1 mt-1 text-[10px] flex justify-between">
                        <span>Paket: {{ u.profile }}</span>
                        <span>{{ u['limit-uptime'] || '' }}</span>
                    </div>
                    <div class="text-[9px] text-zinc-500 mt-1">Buka browser &amp; masukkan kode di atas</div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
@media print {
    @page {
        margin: 0.5cm;
    }
}
</style>

