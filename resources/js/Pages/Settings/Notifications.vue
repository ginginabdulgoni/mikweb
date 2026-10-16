<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';

interface SettingsData {
    has_telegram_token: boolean;
    telegram_chat_id: string;
    whatsapp_api_url: string;
    has_whatsapp_token: boolean;
    whatsapp_target_phone: string;
    notify_router_down: boolean;
    notify_router_recovery: boolean;
    notify_high_cpu: boolean;
    high_cpu_threshold: number;
    notify_daily_digest: boolean;
    notify_backup_completion: boolean;
    notify_security_alert: boolean;
}

const props = defineProps<{
    settings: SettingsData;
}>();

const form = useForm({
    telegram_bot_token: '',
    telegram_chat_id: props.settings.telegram_chat_id,
    whatsapp_api_url: props.settings.whatsapp_api_url,
    whatsapp_api_token: '',
    whatsapp_target_phone: props.settings.whatsapp_target_phone,
    notify_router_down: props.settings.notify_router_down,
    notify_router_recovery: props.settings.notify_router_recovery,
    notify_high_cpu: props.settings.notify_high_cpu,
    high_cpu_threshold: props.settings.high_cpu_threshold,
    notify_daily_digest: props.settings.notify_daily_digest,
    notify_backup_completion: props.settings.notify_backup_completion,
    notify_security_alert: props.settings.notify_security_alert,
});

const isTesting = ref(false);
const testResult = ref<{ success?: boolean; telegram?: boolean; whatsapp?: boolean; errors?: string[] } | null>(null);

function submit() {
    form.post(route('settings.notifications.update'), {
        preserveScroll: true,
        onSuccess: () => {
            testResult.value = null;
        },
    });
}

async function sendTest() {
    isTesting.value = true;
    testResult.value = null;
    try {
        const res = await fetch(route('settings.notifications.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                Accept: 'application/json',
            },
        });
        const data = await res.json();
        testResult.value = data;
    } catch (e: any) {
        testResult.value = { success: false, errors: [e.message || 'Gagal mengirim test notifikasi'] };
    } finally {
        isTesting.value = false;
    }
}
</script>

<template>
    <Head title="Pengaturan Notifikasi & Alert" />
    <AppLayout title="Notification & Alert Settings">
        <div class="mx-auto max-w-4xl space-y-6">
            <!-- Header -->
            <div class="border-b border-zinc-200 pb-4 dark:border-zinc-800">
                <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                    <span>🔔 Pengaturan Notifikasi &amp; Alerting</span>
                </h2>
                <p class="text-xs text-zinc-500 mt-1">
                    Konfigurasikan bot Telegram dan WhatsApp Gateway untuk menerima notifikasi otomatis saat router terputus, pulih, CPU tinggi, atau backup selesai.
                </p>
            </div>

            <!-- Test Result Banner if triggered -->
            <div
                v-if="testResult"
                class="rounded-xl border p-4 text-xs transition"
                :class="testResult.success
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                    : 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300'"
            >
                <div class="flex items-center gap-2 font-semibold">
                    <span>{{ testResult.success ? '✅ Test Notifikasi Terkirim!' : '❌ Gagal Mengirim Test Notifikasi' }}</span>
                </div>
                <div class="mt-2 space-y-1 text-[11px]">
                    <div v-if="testResult.telegram !== undefined">
                        Telegram: <strong :class="testResult.telegram ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">{{ testResult.telegram ? 'Berhasil' : 'Tidak terkirim' }}</strong>
                    </div>
                    <div v-if="testResult.whatsapp !== undefined">
                        WhatsApp: <strong :class="testResult.whatsapp ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">{{ testResult.whatsapp ? 'Berhasil' : 'Tidak terkirim' }}</strong>
                    </div>
                    <div v-if="testResult.errors && testResult.errors.length > 0" class="mt-1 font-mono text-rose-600 dark:text-rose-400">
                        Detail: {{ testResult.errors.join(', ') }}
                    </div>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Telegram Bot Channel Card -->
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-2.5 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-400 font-bold">
                            ✈
                        </span>
                        <div>
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Telegram Bot Channel</h3>
                            <p class="text-xs text-zinc-500">Kirim pesan alert instan ke grup, channel, atau akun Telegram pribadi.</p>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                Bot Token
                                <span v-if="settings.has_telegram_token" class="text-[11px] text-emerald-600 dark:text-emerald-400 font-normal ml-1">
                                    (Sudah tersimpan aman)
                                </span>
                            </label>
                            <input
                                v-model="form.telegram_bot_token"
                                type="password"
                                placeholder="e.g. 123456789:ABCdefGhIJKlmNoPQRstuVWXyz"
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                            <p class="mt-1 text-[11px] text-zinc-400">
                                Dapatkan token dari bot <a href="https://t.me/BotFather" target="_blank" class="text-indigo-600 underline">@BotFather</a>. Biarkan kosong jika tidak ingin mengubah.
                            </p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                Chat ID / Channel ID
                            </label>
                            <input
                                v-model="form.telegram_chat_id"
                                type="text"
                                placeholder="e.g. -100123456789 atau 987654321"
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                            <p class="mt-1 text-[11px] text-zinc-400">
                                ID grup/user tujuan. Gunakan <a href="https://t.me/userinfobot" target="_blank" class="text-indigo-600 underline">@userinfobot</a> untuk melihat ID Anda.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- WhatsApp Gateway Card -->
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-2.5 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400 font-bold">
                            💬
                        </span>
                        <div>
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">WhatsApp Gateway (Opsional)</h3>
                            <p class="text-xs text-zinc-500">Mendukung Fonnte, Wablas, atau REST API WhatsApp gateway mandiri.</p>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">API Endpoint URL</label>
                            <input
                                v-model="form.whatsapp_api_url"
                                type="url"
                                placeholder="https://api.fonnte.com/send"
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                API Token / Key
                                <span v-if="settings.has_whatsapp_token" class="text-[11px] text-emerald-600 dark:text-emerald-400 font-normal ml-1">
                                    (Tersimpan)
                                </span>
                            </label>
                            <input
                                v-model="form.whatsapp_api_token"
                                type="password"
                                placeholder="API Token gateway"
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Nomor WhatsApp Penerima</label>
                            <input
                                v-model="form.whatsapp_target_phone"
                                type="text"
                                placeholder="08123456789 atau 6281234..."
                                class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                            />
                        </div>
                    </div>
                </div>

                <!-- Event Toggles Card -->
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 pb-3 dark:border-zinc-800">
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Kondisi &amp; Pemicu Alert (Event Triggers)</h3>
                        <p class="text-xs text-zinc-500">Pilih jenis kejadian yang akan memicu pengiriman notifikasi otomatis.</p>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <!-- Router Down -->
                        <label class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/40 cursor-pointer">
                            <input
                                v-model="form.notify_router_down"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
                            />
                            <div>
                                <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">🚨 Router Terputus (Down)</span>
                                <p class="text-[11px] text-zinc-500">Kirim peringatan saat router tidak dapat dihubungi atau host unreachable.</p>
                            </div>
                        </label>

                        <!-- Router Recovery -->
                        <label class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/40 cursor-pointer">
                            <input
                                v-model="form.notify_router_recovery"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
                            />
                            <div>
                                <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">✅ Router Pulih (Recovery)</span>
                                <p class="text-[11px] text-zinc-500">Kirim kabar saat router yang sebelumnya down telah kembali online normal.</p>
                            </div>
                        </label>

                        <!-- High CPU -->
                        <label class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/40 cursor-pointer">
                            <input
                                v-model="form.notify_high_cpu"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
                            />
                            <div class="flex-1">
                                <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">⚠️ Beban CPU Tinggi (High CPU)</span>
                                <p class="text-[11px] text-zinc-500">Peringatan saat utilisasi core CPU melampaui batas ambang.</p>
                                <div class="mt-2 flex items-center gap-2">
                                    <span class="text-[11px] text-zinc-400">Batas Ambang:</span>
                                    <input
                                        v-model.number="form.high_cpu_threshold"
                                        type="number"
                                        min="50"
                                        max="99"
                                        class="h-6 w-16 rounded border border-zinc-300 px-1.5 text-center text-xs dark:border-zinc-700 dark:bg-zinc-800"
                                    />
                                    <span class="text-[11px] text-zinc-400">%</span>
                                </div>
                            </div>
                        </label>

                        <!-- Daily Digest -->
                        <label class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/40 cursor-pointer">
                            <input
                                v-model="form.notify_daily_digest"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
                            />
                            <div>
                                <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">📋 Ringkasan Pagi Harian</span>
                                <p class="text-[11px] text-zinc-500">Kirim rekapitulasi kesehatan armada router otomatis setiap jam 08:00 pagi.</p>
                            </div>
                        </label>

                        <!-- Backup Completion -->
                        <label class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/40 cursor-pointer">
                            <input
                                v-model="form.notify_backup_completion"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
                            />
                            <div>
                                <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">💾 Laporan Backup Selesai</span>
                                <p class="text-[11px] text-zinc-500">Konfirmasi setiap jadwal backup biner atau script berhasil dijalankan.</p>
                            </div>
                        </label>

                        <!-- Security Alerts -->
                        <label class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/40 cursor-pointer">
                            <input
                                v-model="form.notify_security_alert"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
                            />
                            <div>
                                <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">🛡️ Temuan Audit Keamanan</span>
                                <p class="text-[11px] text-zinc-500">Notifikasi saat terdeteksi celah keamanan kritis pada router.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="flex items-center justify-between border-t border-zinc-200 pt-4 dark:border-zinc-800">
                    <Btn
                        variant="secondary"
                        size="md"
                        type="button"
                        :disabled="isTesting"
                        @click="sendTest"
                    >
                        <span>{{ isTesting ? 'Mengirim Test...' : '⚡ Kirim Test Notifikasi' }}</span>
                    </Btn>

                    <Btn
                        variant="primary"
                        size="md"
                        type="submit"
                        :disabled="form.processing"
                    >
                        <span>{{ form.processing ? 'Menyimpan...' : '💾 Simpan Pengaturan' }}</span>
                    </Btn>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

