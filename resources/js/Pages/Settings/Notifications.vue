<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';

interface TemplateVariable {
    key: string;
    desc: string;
}

interface SettingsData {
    has_telegram_token: boolean;
    telegram_chat_id: string;
    whatsapp_api_url: string;
    has_whatsapp_token: boolean;
    whatsapp_target_phone: string;
    whatsapp_device_id: string;
    notify_router_down: boolean;
    notify_router_recovery: boolean;
    notify_high_cpu: boolean;
    high_cpu_threshold: number;
    notify_daily_digest: boolean;
    notify_backup_completion: boolean;
    notify_security_alert: boolean;
    template_router_down: string;
    template_router_recovery: string;
    template_high_cpu: string;
    template_daily_digest: string;
}

const props = defineProps<{
    settings: SettingsData;
    default_templates: Record<string, string>;
    available_variables: Record<string, TemplateVariable[]>;
    sample_data: Record<string, string>;
}>();

const form = useForm({
    telegram_bot_token: '',
    telegram_chat_id: props.settings.telegram_chat_id || '',
    whatsapp_api_url: props.settings.whatsapp_api_url || 'https://mywifi.weagate.com',
    whatsapp_api_token: '',
    whatsapp_target_phone: props.settings.whatsapp_target_phone || '',
    whatsapp_device_id: props.settings.whatsapp_device_id || '',
    notify_router_down: props.settings.notify_router_down,
    notify_router_recovery: props.settings.notify_router_recovery,
    notify_high_cpu: props.settings.notify_high_cpu,
    high_cpu_threshold: props.settings.high_cpu_threshold,
    notify_daily_digest: props.settings.notify_daily_digest,
    notify_backup_completion: props.settings.notify_backup_completion,
    notify_security_alert: props.settings.notify_security_alert,
    template_router_down: props.settings.template_router_down || props.default_templates.router_down || '',
    template_router_recovery: props.settings.template_router_recovery || props.default_templates.router_recovery || '',
    template_high_cpu: props.settings.template_high_cpu || props.default_templates.high_cpu || '',
    template_daily_digest: props.settings.template_daily_digest || props.default_templates.daily_digest || '',
});

const isTesting = ref(false);
const testingChannel = ref<'telegram' | 'whatsapp' | 'all'>('all');
const testingEventType = ref<string | null>(null);

interface TestResultData {
    success: boolean;
    channel: 'telegram' | 'whatsapp' | 'all';
    telegram?: boolean;
    whatsapp?: boolean;
    message?: string;
    target_phone?: string;
    chat_id?: string;
    errors?: string[];
}

const testResult = ref<TestResultData | null>(null);

type TemplateType = 'router_down' | 'router_recovery' | 'high_cpu' | 'daily_digest';
const activeTemplateTab = ref<TemplateType>('router_down');
const previewMode = ref<'telegram' | 'whatsapp'>('telegram');
const insertNotification = ref<string | null>(null);

const textareaRefs = ref<Record<string, HTMLTextAreaElement | null>>({});

function submit() {
    form.post(route('settings.notifications.update'), {
        preserveScroll: true,
        onSuccess: () => {
            testResult.value = null;
        },
    });
}

async function sendTest(channel: 'telegram' | 'whatsapp' | 'all' = 'all', eventType?: string) {
    if (isTesting.value) {
        return; // Prevent duplicate clicks / double send
    }

    isTesting.value = true;
    testingChannel.value = channel;
    testingEventType.value = eventType || null;
    testResult.value = null;

    try {
        const payload: Record<string, any> = {
            channel: channel,
            telegram_bot_token: form.telegram_bot_token,
            telegram_chat_id: form.telegram_chat_id,
            whatsapp_api_url: form.whatsapp_api_url,
            whatsapp_api_token: form.whatsapp_api_token,
            whatsapp_target_phone: form.whatsapp_target_phone,
            whatsapp_device_id: form.whatsapp_device_id,
        };

        if (eventType) {
            payload.alert_type = eventType;
            const fieldKey = `template_${eventType}` as keyof typeof form;
            payload[fieldKey] = form[fieldKey];
        }

        const res = await fetch(route('settings.notifications.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                Accept: 'application/json',
            },
            body: JSON.stringify(payload),
        });

        const data = await res.json();
        testResult.value = data;
    } catch (e: any) {
        testResult.value = {
            success: false,
            channel: channel,
            message: 'Gagal menghubungi server MikWeb',
            errors: [e.message || 'Terjadi kesalahan jaringan saat mengirim test'],
        };
    } finally {
        isTesting.value = false;
        testingEventType.value = null;
    }
}

function isActionTesting(channel: string, eventType?: string): boolean {
    if (!isTesting.value) return false;
    if (eventType) {
        return testingChannel.value === channel && testingEventType.value === eventType;
    }
    return testingChannel.value === channel && !testingEventType.value;
}

function insertVariable(varKey: string) {
    const tab = activeTemplateTab.value;
    const fieldKey = `template_${tab}` as keyof typeof form;
    const el = textareaRefs.value[tab];

    const currentVal = (form[fieldKey] as string) || '';

    if (el) {
        const start = el.selectionStart ?? currentVal.length;
        const end = el.selectionEnd ?? currentVal.length;
        const before = currentVal.substring(0, start);
        const after = currentVal.substring(end);
        (form as any)[fieldKey] = before + varKey + after;

        // Reposition cursor right after inserted variable
        setTimeout(() => {
            el.focus();
            const newPos = start + varKey.length;
            el.setSelectionRange(newPos, newPos);
        }, 10);
    } else {
        (form as any)[fieldKey] = currentVal + varKey;
    }

    insertNotification.value = `Variabel ${varKey} disisipkan!`;
    setTimeout(() => {
        if (insertNotification.value?.includes(varKey)) {
            insertNotification.value = null;
        }
    }, 2500);
}

function resetToDefault(tab: TemplateType) {
    if (confirm(`Kembalikan template ini ke teks bawaan default?`)) {
        const fieldKey = `template_${tab}` as keyof typeof form;
        (form as any)[fieldKey] = props.default_templates[tab] || '';
    }
}

const renderedPreview = computed(() => {
    const tab = activeTemplateTab.value;
    const fieldKey = `template_${tab}` as keyof typeof form;
    let text = (form[fieldKey] as string) || props.default_templates[tab] || '';

    // Substitute variables from sample_data
    for (const [k, v] of Object.entries(props.sample_data)) {
        text = text.replaceAll(`{${k}}`, v);
    }

    if (previewMode.value === 'whatsapp') {
        let waText = text;
        waText = waText.replace(/<b[^>]*>(.*?)<\/b>/gis, '*$1*');
        waText = waText.replace(/<strong[^>]*>(.*?)<\/strong>/gis, '*$1*');
        waText = waText.replace(/<i[^>]*>(.*?)<\/i>/gis, '_$1_');
        waText = waText.replace(/<em[^>]*>(.*?)<\/em>/gis, '_$1_');
        waText = waText.replace(/<code[^>]*>(.*?)<\/code>/gis, '`$1`');
        waText = waText.replace(/<pre[^>]*>(.*?)<\/pre>/gis, '```\n$1\n```');
        waText = waText.replace(/<br\s*\/?>/gis, '\n');
        waText = waText.replace(/<\/p>/gis, '\n');
        waText = waText.replace(/<[^>]+>/g, '');
        return waText;
    }

    return text;
});

const tabs = [
    { id: 'router_down', label: '🚨 Router Down', desc: 'Notifikasi saat router terputus / unreachable' },
    { id: 'router_recovery', label: '✅ Router Recovery', desc: 'Notifikasi saat koneksi router pulih online' },
    { id: 'high_cpu', label: '⚠️ High CPU', desc: 'Peringatan utilisasi CPU melampaui batas ambang' },
    { id: 'daily_digest', label: '📋 Daily Digest', desc: 'Rekapitulasi otomatis armada router harian' },
] as const;
</script>

<template>
    <Head title="Pengaturan Notifikasi & Alert" />
    <AppLayout title="Notification & Alert Settings">
        <div class="mx-auto max-w-5xl space-y-6 pb-12">
            <!-- Header -->
            <div class="border-b border-zinc-200 pb-4 dark:border-zinc-800">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <span>🔔 Pengaturan Notifikasi &amp; Alerting</span>
                        </h2>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            Konfigurasikan channel bot Telegram, WhatsApp Weagate, dan kustomisasi template pesan alert router sesuai kebutuhan.
                        </p>
                    </div>

                    <!-- Quick channel test buttons in header -->
                    <div class="flex flex-wrap items-center gap-2 mt-2 sm:mt-0">
                        <button
                            type="button"
                            :disabled="isTesting"
                            @click="sendTest('telegram')"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 bg-sky-50 px-2.5 py-1.5 text-xs font-medium text-sky-700 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300 dark:hover:bg-sky-900/50 transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        >
                            <svg v-if="isActionTesting('telegram')" class="h-3 w-3 animate-spin text-sky-600 dark:text-sky-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span v-else>✈</span>
                            <span>{{ isActionTesting('telegram') ? 'Mengirim Telegram...' : 'Test Telegram' }}</span>
                        </button>

                        <button
                            type="button"
                            :disabled="isTesting"
                            @click="sendTest('whatsapp')"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-900/50 transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        >
                            <svg v-if="isActionTesting('whatsapp')" class="h-3 w-3 animate-spin text-emerald-600 dark:text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span v-else>💬</span>
                            <span>{{ isActionTesting('whatsapp') ? 'Mengirim Weagate...' : 'Test WhatsApp (Weagate)' }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Global In-Progress Loading Notice -->
            <div
                v-if="isTesting"
                class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300 flex items-center gap-2.5 animate-pulse shadow-xs"
            >
                <svg class="h-4 w-4 animate-spin text-amber-600 dark:text-amber-400 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <div class="font-medium">
                    Sedang mengirim pesan test ke
                    <strong class="underline decoration-amber-400">
                        {{ testingChannel === 'whatsapp' ? 'WhatsApp Gateway (Weagate)' : (testingChannel === 'telegram' ? 'Telegram Bot Channel' : 'Telegram & WhatsApp') }}
                    </strong>
                    ... Mohon tunggu proses selesai agar tidak double kirim.
                </div>
            </div>

            <!-- Test Result Banner -->
            <div
                v-if="testResult"
                class="rounded-xl border p-4 text-xs transition shadow-xs"
                :class="testResult.success
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                    : 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300'"
            >
                <div class="flex items-center justify-between font-semibold">
                    <div class="flex items-center gap-2">
                        <span class="text-sm">{{ testResult.success ? '✅' : '❌' }}</span>
                        <span class="text-sm font-bold">{{ testResult.message }}</span>
                    </div>
                    <button type="button" @click="testResult = null" class="text-xs opacity-60 hover:opacity-100 p-1 cursor-pointer">✕ Tutup</button>
                </div>

                <!-- Channel status details if user tested both -->
                <div v-if="testResult.channel === 'all'" class="mt-2 space-y-1 text-[11px] pt-1 border-t border-current/10">
                    <div>
                        Telegram: <strong :class="testResult.telegram ? 'text-emerald-600 dark:text-emerald-400 font-bold' : 'text-rose-600 dark:text-rose-400'">{{ testResult.telegram ? 'Berhasil Terkirim' : 'Tidak terkirim' }}</strong>
                    </div>
                    <div>
                        WhatsApp (Weagate): <strong :class="testResult.whatsapp ? 'text-emerald-600 dark:text-emerald-400 font-bold' : 'text-rose-600 dark:text-rose-400'">{{ testResult.whatsapp ? 'Berhasil Terkirim' : 'Tidak terkirim' }}</strong>
                    </div>
                </div>

                <!-- Single channel recipient confirmation -->
                <div v-else-if="testResult.success" class="mt-1.5 text-[11px] opacity-90">
                    <span v-if="testResult.channel === 'whatsapp' && testResult.target_phone">
                        Tujuan WhatsApp: <strong class="font-mono">{{ testResult.target_phone }}</strong> (via Weagate)
                    </span>
                    <span v-else-if="testResult.channel === 'telegram' && testResult.chat_id">
                        Tujuan Telegram: <strong class="font-mono">Chat ID {{ testResult.chat_id }}</strong>
                    </span>
                </div>

                <!-- Error detail if any -->
                <div v-if="testResult.errors && testResult.errors.length > 0" class="mt-2 font-mono text-rose-600 dark:text-rose-400 bg-white/70 dark:bg-zinc-900/70 p-2.5 rounded-lg border border-rose-200 dark:border-rose-900 text-[11px] leading-relaxed">
                    <strong>Detail Response:</strong> {{ testResult.errors.join(' | ') }}
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Grid: Channels (Telegram & WhatsApp Weagate) -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <!-- Telegram Bot Channel Card -->
                    <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-400 font-bold text-sm">
                                        ✈
                                    </span>
                                    <div>
                                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Telegram Bot Channel</h3>
                                        <p class="text-xs text-zinc-500">Kirim alert instan ke grup, channel, atau akun Telegram pribadi.</p>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    :disabled="isTesting"
                                    @click="sendTest('telegram')"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-sky-200 bg-sky-50 px-2 py-1 text-[11px] font-semibold text-sky-700 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/60 dark:text-sky-300 dark:hover:bg-sky-900/50 transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                                >
                                    <svg v-if="isActionTesting('telegram')" class="h-3 w-3 animate-spin text-sky-600 dark:text-sky-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                    </svg>
                                    <span>{{ isActionTesting('telegram') ? 'Menguji...' : '⚡ Test Telegram' }}</span>
                                </button>
                            </div>

                            <div class="mt-4 space-y-4">
                                <div>
                                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                        Bot Token
                                        <span v-if="settings.has_telegram_token" class="text-[11px] text-emerald-600 dark:text-emerald-400 font-normal ml-1">
                                            (Tersimpan aman)
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
                                        ID tujuan pesan. Gunakan <a href="https://t.me/userinfobot" target="_blank" class="text-indigo-600 underline">@userinfobot</a> untuk melihat ID chat Anda.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 text-[11px] text-zinc-400 flex items-center justify-between">
                            <span>Format: HTML (<code>&lt;b&gt;</code>, <code>&lt;code&gt;</code>)</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-300">
                                Official Telegram API
                            </span>
                        </div>
                    </div>

                    <!-- WhatsApp Weagate Gateway Card -->
                    <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400 font-bold text-sm">
                                        💬
                                    </span>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">WhatsApp Gateway</h3>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700">
                                                Vendor: Weagate
                                            </span>
                                        </div>
                                        <p class="text-xs text-zinc-500">Integrasi pengiriman pesan instan via Weagate API.</p>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    :disabled="isTesting"
                                    @click="sendTest('whatsapp')"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 dark:hover:bg-emerald-900/50 transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                                >
                                    <svg v-if="isActionTesting('whatsapp')" class="h-3 w-3 animate-spin text-emerald-600 dark:text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                    </svg>
                                    <span>{{ isActionTesting('whatsapp') ? 'Menguji...' : '⚡ Test WhatsApp' }}</span>
                                </button>
                            </div>

                            <div class="mt-4 space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                            API Domain Weagate
                                        </label>
                                        <input
                                            v-model="form.whatsapp_api_url"
                                            type="text"
                                            placeholder="https://mywifi.weagate.com"
                                            class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                        />
                                        <p class="mt-1 text-[10px] text-zinc-400">Default: <code>https://mywifi.weagate.com</code></p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                            Device Token Weagate
                                            <span v-if="settings.has_whatsapp_token" class="text-[11px] text-emerald-600 dark:text-emerald-400 font-normal ml-1">
                                                (Tersimpan)
                                            </span>
                                        </label>
                                        <input
                                            v-model="form.whatsapp_api_token"
                                            type="password"
                                            placeholder="Token perangkat Weagate"
                                            class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                        />
                                        <p class="mt-1 text-[10px] text-zinc-400">Parameter <code>token</code> akun Weagate</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                            ID Devices / Sender <span class="text-zinc-400 text-[10px]">(Opsional)</span>
                                        </label>
                                        <input
                                            v-model="form.whatsapp_device_id"
                                            type="text"
                                            placeholder="e.g. DEV-1 atau nomor sender"
                                            class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                        />
                                        <p class="mt-1 text-[10px] text-zinc-400">Parameter <code>id_devices</code> Weagate</p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                            Nomor Tujuan / Group JID
                                        </label>
                                        <input
                                            v-model="form.whatsapp_target_phone"
                                            type="text"
                                            placeholder="08123456789 atau ...@g.us"
                                            class="mt-1 h-8 w-full rounded-lg border border-zinc-300 bg-white px-2.5 font-mono text-xs focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                        />
                                        <p class="mt-1 text-[10px] text-zinc-400">Format lokal <code>08...</code>, <code>628...</code> atau Group</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 text-[11px] text-zinc-400 flex items-center justify-between">
                            <span>Format: Otomatis konversi ke format WhatsApp (<code>*tebal*</code>)</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                Weagate REST API
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Event Toggles Card -->
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 pb-3 dark:border-zinc-800">
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Kondisi &amp; Pemicu Alert (Event Triggers)</h3>
                        <p class="text-xs text-zinc-500">Pilih jenis kejadian yang akan memicu pengiriman notifikasi otomatis.</p>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <!-- Router Down -->
                        <label class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/40 cursor-pointer">
                            <input
                                v-model="form.notify_router_down"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
                            />
                            <div>
                                <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">🚨 Router Terputus (Down)</span>
                                <p class="text-[11px] text-zinc-500">Peringatan saat router gagal dihubungi atau unreachable.</p>
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
                                <p class="text-[11px] text-zinc-500">Kabar saat router yang sempat down kembali online normal.</p>
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
                                <p class="text-[11px] text-zinc-500">Alert bila pemakaian CPU melebihi ambang batas.</p>
                                <div class="mt-2 flex items-center gap-2">
                                    <span class="text-[11px] text-zinc-400">Threshold:</span>
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
                                <p class="text-[11px] text-zinc-500">Rekapitulasi berkala armada router otomatis setiap 08:00 WIB.</p>
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
                                <p class="text-[11px] text-zinc-500">Konfirmasi status saat backup config dan biner router selesai.</p>
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
                                <p class="text-[11px] text-zinc-500">Peringatan saat terdeteksi celah security kritis pada router.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Custom Template & Variables Card -->
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="border-b border-zinc-100 pb-3 dark:border-zinc-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                                <span>📝 Kustomisasi Template Pesan Alert</span>
                                <span class="text-[10px] font-normal px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-600 dark:bg-indigo-950/60 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800">
                                    Telegram HTML &amp; WhatsApp Weagate
                                </span>
                            </h3>
                            <p class="text-xs text-zinc-500 mt-0.5">
                                Sesuaikan format teks, tata letak, emoji, dan gunakan variabel dinamis untuk setiap jenis kejadian.
                            </p>
                        </div>

                        <!-- Reset tab button -->
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="resetToDefault(activeTemplateTab)"
                                class="text-xs text-zinc-500 hover:text-rose-600 dark:hover:text-rose-400 transition underline underline-offset-2 flex items-center gap-1 cursor-pointer"
                            >
                                <span>↺ Reset Template Ini ke Bawaan</span>
                            </button>
                        </div>
                    </div>

                    <!-- Template Tabs -->
                    <div class="mt-4 flex flex-wrap gap-1.5 border-b border-zinc-200 dark:border-zinc-800 pb-3">
                        <button
                            v-for="tab in tabs"
                            :key="tab.id"
                            type="button"
                            @click="activeTemplateTab = tab.id"
                            class="rounded-lg px-3 py-1.5 text-xs font-medium transition cursor-pointer"
                            :class="activeTemplateTab === tab.id
                                ? 'bg-indigo-600 text-white shadow-xs'
                                : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700'"
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <!-- Active Tab Editor & Preview Grid -->
                    <div class="mt-4 grid grid-cols-1 lg:grid-cols-12 gap-5">
                        <!-- Left: Editor & Available Variables (7 cols) -->
                        <div class="lg:col-span-7 space-y-3">
                            <!-- Variables Chips Bar -->
                            <div class="rounded-lg border border-zinc-200 bg-zinc-50/70 p-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-[11px] font-semibold text-zinc-700 dark:text-zinc-300 flex items-center gap-1">
                                        <span>🏷️ Variabel Tersedia (Klik untuk Menambahkan):</span>
                                    </span>
                                    <span v-if="insertNotification" class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 animate-pulse">
                                        {{ insertNotification }}
                                    </span>
                                </div>

                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        v-for="v in (available_variables[activeTemplateTab] || [])"
                                        :key="v.key"
                                        type="button"
                                        @click="insertVariable(v.key)"
                                        :title="v.desc"
                                        class="inline-flex items-center gap-1 rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-[11px] text-indigo-600 hover:border-indigo-400 hover:bg-indigo-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-indigo-400 dark:hover:border-indigo-500 dark:hover:bg-zinc-700 transition cursor-pointer shadow-2xs"
                                    >
                                        <span>{{ v.key }}</span>
                                    </button>
                                </div>

                                <div class="mt-2 text-[10px] text-zinc-400">
                                    Tip: Klik variabel di atas untuk langsung menyisipkan tag variabel pada posisi kursor.
                                </div>
                            </div>

                            <!-- Textarea Editor -->
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                        Format Template ({{ tabs.find(t => t.id === activeTemplateTab)?.label }})
                                    </label>
                                    <span class="text-[11px] text-zinc-400">
                                        Support HTML: <code>&lt;b&gt;tebal&lt;/b&gt;</code>, <code>&lt;i&gt;miring&lt;/i&gt;</code>, <code>&lt;code&gt;mono&lt;/code&gt;</code>
                                    </span>
                                </div>

                                <textarea
                                    v-if="activeTemplateTab === 'router_down'"
                                    :ref="(el) => { textareaRefs['router_down'] = el as HTMLTextAreaElement }"
                                    v-model="form.template_router_down"
                                    rows="9"
                                    class="w-full rounded-lg border border-zinc-300 bg-white p-3 font-mono text-xs leading-relaxed text-zinc-800 focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                    placeholder="Format pesan template router down..."
                                />
                                <textarea
                                    v-else-if="activeTemplateTab === 'router_recovery'"
                                    :ref="(el) => { textareaRefs['router_recovery'] = el as HTMLTextAreaElement }"
                                    v-model="form.template_router_recovery"
                                    rows="9"
                                    class="w-full rounded-lg border border-zinc-300 bg-white p-3 font-mono text-xs leading-relaxed text-zinc-800 focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                    placeholder="Format pesan template router recovery..."
                                />
                                <textarea
                                    v-else-if="activeTemplateTab === 'high_cpu'"
                                    :ref="(el) => { textareaRefs['high_cpu'] = el as HTMLTextAreaElement }"
                                    v-model="form.template_high_cpu"
                                    rows="9"
                                    class="w-full rounded-lg border border-zinc-300 bg-white p-3 font-mono text-xs leading-relaxed text-zinc-800 focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                    placeholder="Format pesan template high cpu..."
                                />
                                <textarea
                                    v-else-if="activeTemplateTab === 'daily_digest'"
                                    :ref="(el) => { textareaRefs['daily_digest'] = el as HTMLTextAreaElement }"
                                    v-model="form.template_daily_digest"
                                    rows="9"
                                    class="w-full rounded-lg border border-zinc-300 bg-white p-3 font-mono text-xs leading-relaxed text-zinc-800 focus:border-indigo-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                    placeholder="Format pesan template ringkasan harian..."
                                />
                            </div>

                            <!-- Single Tab Test Buttons (Telegram / WhatsApp specific!) -->
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 pt-1 border-t border-zinc-100 dark:border-zinc-800">
                                <span class="text-[11px] text-zinc-400">
                                    Pilih channel tujuan untuk mencoba kirim template ini:
                                </span>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        :disabled="isTesting"
                                        @click="sendTest('telegram', activeTemplateTab)"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 bg-sky-50 px-2.5 py-1.5 text-xs font-medium text-sky-700 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300 dark:hover:bg-sky-900/50 transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                                    >
                                        <svg v-if="isActionTesting('telegram', activeTemplateTab)" class="h-3 w-3 animate-spin text-sky-600 dark:text-sky-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                        </svg>
                                        <span v-else>✈</span>
                                        <span>{{ isActionTesting('telegram', activeTemplateTab) ? 'Mengirim...' : 'Test ke Telegram' }}</span>
                                    </button>

                                    <button
                                        type="button"
                                        :disabled="isTesting"
                                        @click="sendTest('whatsapp', activeTemplateTab)"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-900/50 transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                                    >
                                        <svg v-if="isActionTesting('whatsapp', activeTemplateTab)" class="h-3 w-3 animate-spin text-emerald-600 dark:text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                        </svg>
                                        <span v-else>💬</span>
                                        <span>{{ isActionTesting('whatsapp', activeTemplateTab) ? 'Mengirim...' : 'Test ke WhatsApp' }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Live Simulation Preview (5 cols) -->
                        <div class="lg:col-span-5 flex flex-col rounded-xl border border-zinc-200 bg-zinc-50/50 p-3.5 dark:border-zinc-800 dark:bg-zinc-950/40">
                            <!-- Preview Channel Selector -->
                            <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-2 mb-3">
                                <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300 flex items-center gap-1.5">
                                    <span>👁️ Live Preview</span>
                                </span>
                                <div class="flex items-center gap-1 bg-zinc-200 dark:bg-zinc-800 p-0.5 rounded-lg text-[10px]">
                                    <button
                                        type="button"
                                        @click="previewMode = 'telegram'"
                                        class="px-2 py-0.5 rounded font-medium transition cursor-pointer"
                                        :class="previewMode === 'telegram'
                                            ? 'bg-sky-600 text-white shadow-2xs'
                                            : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900'"
                                    >
                                        ✈ Telegram
                                    </button>
                                    <button
                                        type="button"
                                        @click="previewMode = 'whatsapp'"
                                        class="px-2 py-0.5 rounded font-medium transition cursor-pointer"
                                        :class="previewMode === 'whatsapp'
                                            ? 'bg-emerald-600 text-white shadow-2xs'
                                            : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900'"
                                    >
                                        💬 WhatsApp
                                    </button>
                                </div>
                            </div>

                            <!-- Realistic Chat Bubble Container -->
                            <div class="flex-1 flex flex-col justify-start">
                                <!-- Telegram Bubble Style -->
                                <div
                                    v-if="previewMode === 'telegram'"
                                    class="rounded-xl border border-sky-100 bg-white p-3.5 shadow-xs dark:border-sky-950 dark:bg-zinc-900 text-xs leading-relaxed"
                                >
                                    <div class="flex items-center gap-1.5 mb-2 pb-1.5 border-b border-zinc-100 dark:border-zinc-800 text-[10px] text-sky-600 dark:text-sky-400 font-semibold">
                                        <span>✈ MikWeb Bot Telegram</span>
                                        <span class="text-zinc-400 font-normal ml-auto">Baru saja</span>
                                    </div>
                                    <div
                                        class="whitespace-pre-line text-zinc-800 dark:text-zinc-200 font-sans text-xs [&>b]:font-bold [&>strong]:font-bold [&>i]:italic [&>code]:bg-zinc-100 [&>code]:px-1 [&>code]:rounded [&>code]:font-mono [&>code]:text-[11px] dark:[&>code]:bg-zinc-800"
                                        v-html="renderedPreview"
                                    ></div>
                                </div>

                                <!-- WhatsApp Weagate Bubble Style -->
                                <div
                                    v-else
                                    class="rounded-xl border border-emerald-100 bg-[#EFEAE2] p-3 shadow-xs dark:border-emerald-950 dark:bg-zinc-900/90 text-xs leading-relaxed"
                                >
                                    <div class="rounded-lg bg-white p-3 shadow-2xs dark:bg-zinc-800/90 border border-emerald-100 dark:border-zinc-700">
                                        <div class="flex items-center gap-1.5 mb-2 pb-1 border-b border-zinc-100 dark:border-zinc-700 text-[10px] text-emerald-700 dark:text-emerald-400 font-semibold">
                                            <span>💬 Weagate WhatsApp Notifikasi</span>
                                            <span class="text-zinc-400 font-normal ml-auto">12:00</span>
                                        </div>
                                        <div class="whitespace-pre-line font-sans text-xs text-zinc-900 dark:text-zinc-100">
                                            {{ renderedPreview }}
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3 text-[10px] text-zinc-400 leading-normal">
                                    Preview menampilkan data sampel simulasi (Core-CCR, IP 192.168.88.1). Variabel <code>{...}</code> akan diganti dengan nilai riil saat alert dikirimkan.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="flex flex-col sm:flex-row items-center justify-between border-t border-zinc-200 pt-4 dark:border-zinc-800 gap-3">
                    <!-- Left: Multi Channel Test Options -->
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs text-zinc-500 font-medium mr-1">Uji Coba:</span>

                        <button
                            type="button"
                            :disabled="isTesting"
                            @click="sendTest('telegram')"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        >
                            <svg v-if="isActionTesting('telegram')" class="h-3 w-3 animate-spin text-sky-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span v-else>✈</span>
                            <span>{{ isActionTesting('telegram') ? 'Mengirim...' : 'Test Telegram' }}</span>
                        </button>

                        <button
                            type="button"
                            :disabled="isTesting"
                            @click="sendTest('whatsapp')"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        >
                            <svg v-if="isActionTesting('whatsapp')" class="h-3 w-3 animate-spin text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span v-else>💬</span>
                            <span>{{ isActionTesting('whatsapp') ? 'Mengirim...' : 'Test WhatsApp (Weagate)' }}</span>
                        </button>

                        <button
                            type="button"
                            :disabled="isTesting"
                            @click="sendTest('all')"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        >
                            <svg v-if="isActionTesting('all')" class="h-3 w-3 animate-spin text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span v-else>⚡</span>
                            <span>{{ isActionTesting('all') ? 'Mengirim Keduanya...' : 'Test Keduanya' }}</span>
                        </button>
                    </div>

                    <!-- Right: Save button -->
                    <Btn
                        variant="primary"
                        size="md"
                        type="submit"
                        :disabled="form.processing || isTesting"
                    >
                        <span>{{ form.processing ? 'Menyimpan...' : '💾 Simpan Pengaturan & Template' }}</span>
                    </Btn>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
