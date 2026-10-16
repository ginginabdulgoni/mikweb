<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, useForm, router as inertia } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';

interface CustomerRow {
    id: number;
    router_id: number | null;
    service_type: string;
    username: string;
    customer_name: string;
    phone_number: string | null;
    address: string | null;
    monthly_bill: string;
    due_day: number;
    billing_status: string;
    is_active: boolean;
    is_isolated: boolean;
    last_paid_at: string | null;
    notes: string | null;
    router?: { id: number; name: string; host: string } | null;
}

interface Stats {
    total: number;
    paid: number;
    unpaid: number;
    overdue: number;
    isolated: number;
    total_revenue: number;
    potential_revenue: number;
}

interface RouterOption {
    id: number;
    name: string;
    host: string;
}

const props = defineProps<{
    customers: {
        data: CustomerRow[];
        links: any[];
        total: number;
    };
    stats: Stats;
    routers: RouterOption[];
    filters: {
        status: string;
        search: string;
    };
}>();

const currentStatus = ref(props.filters.status || 'all');
const searchQuery = ref(props.filters.search || '');

function applyFilter(status: string) {
    currentStatus.value = status;
    inertia.get(route('customers.index'), {
        status,
        search: searchQuery.value,
    }, { preserveState: true, replace: true });
}

function search() {
    inertia.get(route('customers.index'), {
        status: currentStatus.value,
        search: searchQuery.value,
    }, { preserveState: true, replace: true });
}

function formatRupiah(num: number): string {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(num);
}

// ── Create & Edit Modal ──────────────────────────────────────────────
const showModal = ref(false);
const editingCustomer = ref<CustomerRow | null>(null);

const form = useForm({
    router_id: null as number | null,
    service_type: 'pppoe',
    username: '',
    customer_name: '',
    phone_number: '',
    address: '',
    monthly_bill: 150000,
    due_day: 10,
    billing_status: 'unpaid',
    notes: '',
});

function openCreateModal() {
    editingCustomer.value = null;
    form.reset();
    form.clearErrors();
    form.service_type = 'pppoe';
    form.monthly_bill = 150000;
    form.due_day = 10;
    form.billing_status = 'unpaid';
    showModal.value = true;
}

function openEditModal(c: CustomerRow) {
    editingCustomer.value = c;
    form.clearErrors();
    form.router_id = c.router_id;
    form.service_type = c.service_type;
    form.username = c.username;
    form.customer_name = c.customer_name;
    form.phone_number = c.phone_number || '';
    form.address = c.address || '';
    form.monthly_bill = Number(c.monthly_bill);
    form.due_day = c.due_day;
    form.billing_status = c.billing_status;
    form.notes = c.notes || '';
    showModal.value = true;
}

function saveCustomer() {
    if (editingCustomer.value) {
        form.put(route('customers.update', editingCustomer.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                showModal.value = false;
            },
        });
    } else {
        form.post(route('customers.store'), {
            preserveScroll: true,
            onSuccess: () => {
                showModal.value = false;
            },
        });
    }
}

function markPaid(c: CustomerRow) {
    if (confirm(`Catat pembayaran lunas untuk '${c.customer_name}'?`)) {
        inertia.post(route('customers.mark-paid', c.id), {}, { preserveScroll: true });
    }
}

function toggleIsolir(c: CustomerRow) {
    const action = c.is_isolated ? 'Buka Isolir' : 'Isolir';
    if (confirm(`${action} pelanggan '${c.customer_name}'?`)) {
        inertia.post(route('customers.toggle-isolir', c.id), {}, { preserveScroll: true });
    }
}

function deleteCustomer(c: CustomerRow) {
    if (confirm(`Hapus data pelanggan '${c.customer_name}'?`)) {
        inertia.delete(route('customers.destroy', c.id), { preserveScroll: true });
    }
}

function getWhatsAppUrl(c: CustomerRow): string {
    const phone = (c.phone_number || '').replace(/[^0-9]/g, '');
    let formattedPhone = phone;
    if (formattedPhone.startsWith('0')) {
        formattedPhone = '62' + formattedPhone.slice(1);
    }
    const billFormatted = formatRupiah(Number(c.monthly_bill));
    const msg = `Halo Bpk/Ibu *${c.customer_name}*,\n\n`
        + `Kami menginformasikan tagihan langganan internet Anda:\n`
        + `• User: *${c.username}*\n`
        + `• Tagihan: *${billFormatted}*\n`
        + `• Jatuh Tempo: Tanggal ${c.due_day} bulan ini\n`
        + `• Status: *${c.billing_status.toUpperCase()}*\n\n`
        + `Mohon segera melakukan pembayaran sebelum jatuh tempo agar tidak terkena isolir otomatis. Terima kasih! 🙏`;

    return `https://wa.me/${formattedPhone}?text=${encodeURIComponent(msg)}`;
}
</script>

<template>
    <Head title="Manajemen Pelanggan & Billing" />
    <AppLayout title="Customers & Billing">
        <div class="w-full space-y-6">
            <!-- Header & Actions -->
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-200 pb-4 dark:border-zinc-800">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <span>👥 Pelanggan &amp; Billing (PPPoE / Hotspot)</span>
                    </h2>
                    <p class="text-xs text-zinc-500">
                        Kelola data pelanggan, jatuh tempo tagihan bulanan, isolir otomatis MikroTik, dan kirim invoice WhatsApp.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <Btn variant="primary" size="md" @click="openCreateModal">
                        + Tambah Pelanggan
                    </Btn>
                </div>
            </div>

            <!-- Stats KPI Cards -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500">Total Pelanggan</span>
                    <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ stats.total }}</div>
                    <span class="text-[11px] text-zinc-400">Terdaftar</span>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500">Lunas</span>
                    <div class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ stats.paid }}</div>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400">Bulan ini</span>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500">Belum Bayar</span>
                    <div class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">{{ stats.unpaid }}</div>
                    <span class="text-[11px] text-amber-500">Menunggu</span>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500">Jatuh Tempo</span>
                    <div class="mt-1 text-2xl font-bold text-rose-600 dark:text-rose-400">{{ stats.overdue }}</div>
                    <span class="text-[11px] text-rose-500">Melewati due date</span>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500">Terisolir</span>
                    <div class="mt-1 text-2xl font-bold text-zinc-700 dark:text-zinc-300">{{ stats.isolated }}</div>
                    <span class="text-[11px] text-zinc-400">Akses tertutup</span>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500">Penerimaan Lunas</span>
                    <div class="mt-1 text-base font-bold text-indigo-600 dark:text-indigo-400">{{ formatRupiah(stats.total_revenue) }}</div>
                    <span class="text-[10px] text-zinc-400">Potensi: {{ formatRupiah(stats.potential_revenue) }}</span>
                </div>
            </div>

            <!-- Filter & Search Toolbar -->
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white p-3 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <!-- Status Pills -->
                <div class="flex flex-wrap items-center gap-1.5 text-xs">
                    <button
                        v-for="st in [
                            { id: 'all', label: 'Semua' },
                            { id: 'paid', label: 'Lunas' },
                            { id: 'unpaid', label: 'Belum Bayar' },
                            { id: 'overdue', label: 'Jatuh Tempo' },
                            { id: 'isolated', label: 'Terisolir' },
                        ]"
                        :key="st.id"
                        type="button"
                        class="rounded-lg px-3 py-1.5 font-medium transition"
                        :class="currentStatus === st.id
                            ? 'bg-indigo-600 text-white shadow-xs'
                            : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'"
                        @click="applyFilter(st.id)"
                    >
                        {{ st.label }}
                    </button>
                </div>

                <!-- Search Input -->
                <div class="flex items-center gap-2">
                    <input
                        v-model="searchQuery"
                        type="search"
                        placeholder="Cari nama, user, HP..."
                        class="h-8 w-60 rounded-lg border border-zinc-300 bg-white px-3 text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        @keyup.enter="search"
                    />
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-300 bg-zinc-50 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        @click="search"
                    >
                        Cari
                    </button>
                </div>
            </div>

            <!-- Table of Customers -->
            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50/75 text-xs font-semibold text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/40 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3">Pelanggan</th>
                                <th class="px-4 py-3">Layanan &amp; Router</th>
                                <th class="px-4 py-3">Tagihan / Bulan</th>
                                <th class="px-4 py-3">Jatuh Tempo</th>
                                <th class="px-4 py-3">Status Tagihan</th>
                                <th class="px-4 py-3">Akses</th>
                                <th class="px-4 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-if="customers.data.length === 0">
                                <td colspan="7" class="px-4 py-12 text-center text-xs text-zinc-500">
                                    Belum ada data pelanggan yang sesuai.
                                </td>
                            </tr>
                            <tr
                                v-for="c in customers.data"
                                :key="c.id"
                                class="transition-colors hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40"
                            >
                                <!-- Pelanggan Info -->
                                <td class="px-4 py-3">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ c.customer_name }}</div>
                                    <div class="font-mono text-xs text-zinc-400">{{ c.phone_number || 'Tanpa No. HP' }}</div>
                                </td>

                                <!-- Layanan & Router -->
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1.5">
                                        <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-bold uppercase text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                            {{ c.service_type }}
                                        </span>
                                        <span class="font-mono text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ c.username }}</span>
                                    </div>
                                    <span v-if="c.router" class="text-[11px] text-zinc-400">{{ c.router.name }}</span>
                                </td>

                                <!-- Tagihan -->
                                <td class="px-4 py-3 font-mono font-semibold text-zinc-800 dark:text-zinc-200">
                                    {{ formatRupiah(Number(c.monthly_bill)) }}
                                </td>

                                <!-- Jatuh tempo -->
                                <td class="px-4 py-3 text-xs text-zinc-600 dark:text-zinc-300">
                                    Tgl {{ c.due_day }} / bln
                                </td>

                                <!-- Status Tagihan -->
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="{
                                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300': c.billing_status === 'paid',
                                            'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300': c.billing_status === 'unpaid',
                                            'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300': c.billing_status === 'overdue',
                                        }"
                                    >
                                        {{ c.billing_status === 'paid' ? 'LUNAS' : c.billing_status === 'overdue' ? 'JATUH TEMPO' : 'BELUM BAYAR' }}
                                    </span>
                                </td>

                                <!-- Status Akses -->
                                <td class="px-4 py-3">
                                    <span
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="c.is_isolated
                                            ? 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 font-bold'
                                            : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'"
                                    >
                                        {{ c.is_isolated ? 'TERISOLIR' : 'AKTIF' }}
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1.5 text-xs font-sans">
                                        <!-- Mark Paid -->
                                        <button
                                            v-if="c.billing_status !== 'paid'"
                                            type="button"
                                            class="rounded bg-emerald-50 px-2 py-1 font-semibold text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300"
                                            title="Tandai sudah lunas"
                                            @click="markPaid(c)"
                                        >
                                            ✓ Bayar
                                        </button>

                                        <!-- Toggle Isolir -->
                                        <button
                                            type="button"
                                            class="rounded px-2 py-1 font-semibold transition"
                                            :class="c.is_isolated
                                                ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                : 'bg-rose-50 text-rose-700 hover:bg-rose-100 dark:bg-rose-950/40 dark:text-rose-300'"
                                            :title="c.is_isolated ? 'Buka isolir' : 'Isolir pelanggan'"
                                            @click="toggleIsolir(c)"
                                        >
                                            {{ c.is_isolated ? 'Buka Isolir' : 'Isolir' }}
                                        </button>

                                        <!-- WhatsApp Reminder -->
                                        <a
                                            v-if="c.phone_number"
                                            :href="getWhatsAppUrl(c)"
                                            target="_blank"
                                            class="rounded bg-emerald-500 px-2 py-1 font-semibold text-white hover:bg-emerald-600"
                                            title="Kirim pesan penagihan via WhatsApp"
                                        >
                                            💬 WA
                                        </a>

                                        <!-- Edit -->
                                        <button
                                            type="button"
                                            class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                            title="Edit data pelanggan"
                                            @click="openEditModal(c)"
                                        >
                                            ✏
                                        </button>

                                        <!-- Delete -->
                                        <button
                                            type="button"
                                            class="rounded p-1 text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40"
                                            title="Hapus pelanggan"
                                            @click="deleteCustomer(c)"
                                        >
                                            🗑
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Tambah/Edit Pelanggan -->
            <Modal :show="showModal" @close="showModal = false" :title="editingCustomer ? 'Edit Pelanggan' : 'Tambah Pelanggan Baru'">
                <form @submit.prevent="saveCustomer" class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Nama Pelanggan *</label>
                            <input
                                v-model="form.customer_name"
                                type="text"
                                required
                                placeholder="Budi Santoso"
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">No. WhatsApp</label>
                            <input
                                v-model="form.phone_number"
                                type="text"
                                placeholder="08123456789"
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Jenis Layanan</label>
                            <select
                                v-model="form.service_type"
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2 text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option value="pppoe">PPPoE</option>
                                <option value="hotspot">Hotspot</option>
                                <option value="static">Static IP</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Username MikroTik *</label>
                            <input
                                v-model="form.username"
                                type="text"
                                required
                                placeholder="user_pppoe1"
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Router</label>
                            <select
                                v-model="form.router_id"
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2 text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option :value="null">-- Tidak Terikat --</option>
                                <option v-for="r in routers" :key="r.id" :value="r.id">{{ r.name }} ({{ r.host }})</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Biaya Tagihan / Bulan (Rp) *</label>
                            <input
                                v-model.number="form.monthly_bill"
                                type="number"
                                required
                                min="0"
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Tanggal Jatuh Tempo (1-31)</label>
                            <input
                                v-model.number="form.due_day"
                                type="number"
                                min="1"
                                max="31"
                                required
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Status Pembayaran</label>
                            <select
                                v-model="form.billing_status"
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2 text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            >
                                <option value="unpaid">Belum Bayar</option>
                                <option value="paid">Lunas</option>
                                <option value="overdue">Jatuh Tempo (Telat)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Alamat Pemasangan</label>
                        <input
                            v-model="form.address"
                            type="text"
                            placeholder="Jl. Mawar No. 12 RT 01/02"
                            class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        />
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-zinc-200 pt-3 dark:border-zinc-800">
                        <Btn variant="secondary" size="sm" type="button" @click="showModal = false">Batal</Btn>
                        <Btn variant="primary" size="sm" type="submit" :disabled="form.processing">Simpan Data</Btn>
                    </div>
                </form>
            </Modal>
        </div>
    </AppLayout>
</template>

