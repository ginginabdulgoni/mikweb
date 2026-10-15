<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router as inertia, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Btn from '@/components/ui/Btn.vue';
import Modal from '@/components/ui/Modal.vue';
import type { PageProps } from '@/types';

interface Rule { id: number; name: string; trigger: string; action: string; conditions: any; enabled: boolean; last_triggered_at: string | null }
interface Execution { id: number; status: string; message: string; router: string | null; created_at: string | null }

const props = defineProps<{ rules: Rule[]; executions: Execution[]; can: { manage: boolean } }>();
const page = usePage<PageProps>();

const showModal = ref(false);
const form = useForm({
    name: '', trigger: 'router_offline', action: 'notify_log', threshold: 80,
    webhook_url: '', telegram_bot_token: '', telegram_chat_id: '',
});

function submit() {
    form.post(route('automation.store'), { preserveScroll: true, onSuccess: () => { showModal.value = false; form.reset(); } });
}
function toggle(r: Rule) { inertia.post(route('automation.toggle', r.id), {}, { preserveScroll: true }); }
function remove(r: Rule) { if (confirm(`Delete rule "${r.name}"?`)) inertia.delete(route('automation.destroy', r.id), { preserveScroll: true }); }
function evaluate() { inertia.post(route('automation.evaluate'), {}, { preserveScroll: true }); }

const triggerLabel = (t: string) => ({ router_offline: 'Router offline', router_unreachable: 'Router unreachable', cpu_high: 'CPU high' }[t] ?? t);
</script>

<template>
    <Head title="Automation" />
    <AppLayout title="Automation">
        <div class="w-full space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold">Automation rules</h2>
                    <p class="text-xs text-zinc-500">Evaluated every 5 minutes by the scheduler (or on demand).</p>
                </div>
                <div class="flex gap-2">
                    <Btn @click="evaluate">Evaluate now</Btn>
                    <Btn v-if="can.manage" variant="primary" @click="showModal = true">+ New rule</Btn>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50">
                        <tr><th class="px-4 py-2.5">Name</th><th class="px-4 py-2.5">Trigger</th><th class="px-4 py-2.5">Action</th><th class="px-4 py-2.5">Last fired</th><th class="px-4 py-2.5">State</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-if="rules.length === 0"><td colspan="6" class="px-4 py-8 text-center text-zinc-500">No rules yet.</td></tr>
                        <tr v-for="r in rules" :key="r.id" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50" :class="!r.enabled && 'opacity-50'">
                            <td class="px-4 py-2.5 font-medium">{{ r.name }}</td>
                            <td class="px-4 py-2.5">{{ triggerLabel(r.trigger) }}<span v-if="r.conditions?.threshold" class="text-xs text-zinc-400"> ≥{{ r.conditions.threshold }}%</span></td>
                            <td class="px-4 py-2.5 text-zinc-500">{{ r.action.replace('notify_', '') }}</td>
                            <td class="px-4 py-2.5 text-xs text-zinc-400">{{ r.last_triggered_at ?? 'never' }}</td>
                            <td class="px-4 py-2.5">{{ r.enabled ? 'enabled' : 'disabled' }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <div v-if="can.manage" class="flex justify-end gap-1.5">
                                    <Btn size="sm" @click="toggle(r)">{{ r.enabled ? 'Disable' : 'Enable' }}</Btn>
                                    <Btn size="sm" variant="danger" @click="remove(r)">Delete</Btn>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="border-b border-zinc-200 px-4 py-2.5 text-sm font-semibold dark:border-zinc-800">Recent executions</h3>
                <ul class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                    <li v-if="executions.length === 0" class="px-4 py-6 text-center text-zinc-500">No executions yet.</li>
                    <li v-for="e in executions" :key="e.id" class="flex items-center gap-3 px-4 py-2.5">
                        <span :class="e.status === 'error' ? 'text-red-500' : 'text-amber-500'">●</span>
                        <span class="flex-1">{{ e.message }}</span>
                        <span class="text-xs text-zinc-400">{{ e.created_at }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <Modal :open="showModal" title="New automation rule" @close="showModal = false">
            <form class="space-y-4" @submit.prevent="submit">
                <div><label class="mb-1 block text-sm font-medium">Name</label><input v-model="form.name" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" required /><p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p></div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Trigger</label>
                        <select v-model="form.trigger" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option value="router_offline">Router offline</option>
                            <option value="router_unreachable">Router unreachable</option>
                            <option value="cpu_high">CPU high</option>
                        </select>
                    </div>
                    <div v-if="form.trigger === 'cpu_high'">
                        <label class="mb-1 block text-sm font-medium">CPU threshold %</label>
                        <input v-model.number="form.threshold" type="number" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Action</label>
                    <select v-model="form.action" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        <option value="notify_log">Log</option>
                        <option value="notify_webhook">Webhook</option>
                        <option value="notify_telegram">Telegram</option>
                    </select>
                </div>
                <div v-if="form.action === 'notify_webhook'">
                    <label class="mb-1 block text-sm font-medium">Webhook URL</label>
                    <input v-model="form.webhook_url" placeholder="https://…" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                </div>
                <div v-if="form.action === 'notify_telegram'" class="grid grid-cols-2 gap-4">
                    <div><label class="mb-1 block text-sm font-medium">Bot token</label><input v-model="form.telegram_bot_token" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" /></div>
                    <div><label class="mb-1 block text-sm font-medium">Chat ID</label><input v-model="form.telegram_chat_id" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" /></div>
                </div>
                <div class="flex justify-end gap-2"><Btn variant="ghost" @click="showModal = false">Cancel</Btn><Btn type="submit" variant="primary" :disabled="form.processing">Create</Btn></div>
            </form>
        </Modal>
    </AppLayout>
</template>
