<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from '@/lib/http';
import RouterWorkspace from '@/layouts/RouterWorkspace.vue';
import Btn from '@/components/ui/Btn.vue';

interface HistoryItem {
    cmd: string;
    output: string;
    isError?: boolean;
}

const props = defineProps<{
    router: any;
}>();

const commandInput = ref('');
const running = ref(false);
const terminalBody = ref<HTMLElement | null>(null);
const inputField = ref<HTMLInputElement | null>(null);

const history = ref<HistoryItem[]>([]);
const commandHistory = ref<string[]>([]);
const historyIndex = ref<number>(-1);

const quickCommands = [
    '/system/resource/print',
    '/interface/print',
    '/ip/address/print',
    '/ip/route/print',
    '/ip/dhcp-server/lease/print',
    '/log/print',
];

function scrollToBottom() {
    nextTick(() => {
        if (terminalBody.value) {
            terminalBody.value.scrollTop = terminalBody.value.scrollHeight;
        }
    });
}

async function executeCommand(cmd?: string) {
    const toRun = (cmd ?? commandInput.value).trim();
    if (!toRun) return;

    commandHistory.value.push(toRun);
    historyIndex.value = -1;
    commandInput.value = '';
    running.value = true;

    try {
        const url = route('routers.terminal.execute', props.router.id);
        const { data } = await axios.post<{ success: boolean; output?: string; error?: string }>(url, {
            command: toRun,
        });

        if (data.success) {
            history.value.push({
                cmd: toRun,
                output: data.output || 'ok',
            });
        } else {
            history.value.push({
                cmd: toRun,
                output: data.error || 'Execution error',
                isError: true,
            });
        }
    } catch (e: any) {
        history.value.push({
            cmd: toRun,
            output: e?.response?.data?.error || e?.message || 'Network / connection error',
            isError: true,
        });
    } finally {
        running.value = false;
        scrollToBottom();
        nextTick(() => inputField.value?.focus());
    }
}

function handleKeyDown(e: KeyboardEvent) {
    if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (commandHistory.value.length === 0) return;
        if (historyIndex.value === -1) {
            historyIndex.value = commandHistory.value.length - 1;
        } else if (historyIndex.value > 0) {
            historyIndex.value--;
        }
        commandInput.value = commandHistory.value[historyIndex.value] ?? '';
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (historyIndex.value !== -1) {
            if (historyIndex.value < commandHistory.value.length - 1) {
                historyIndex.value++;
                commandInput.value = commandHistory.value[historyIndex.value] ?? '';
            } else {
                historyIndex.value = -1;
                commandInput.value = '';
            }
        }
    }
}

function clearTerminal() {
    history.value = [];
    nextTick(() => inputField.value?.focus());
}

onMounted(() => {
    inputField.value?.focus();
});
</script>

<template>
    <Head :title="`New Terminal — ${router.name}`" />

    <RouterWorkspace :router="router">
        <div class="flex h-full flex-col bg-zinc-950 font-mono text-xs text-zinc-200">
            <!-- Toolbar -->
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-800 bg-zinc-900/90 px-4 py-2">
                <div class="flex items-center gap-2">
                    <span class="rounded bg-indigo-950/80 px-2 py-0.5 text-[11px] font-bold text-indigo-300 border border-indigo-800">
                        >_ Terminal
                    </span>
                    <span class="text-[11px] text-zinc-400 font-sans">Interactive RouterOS CLI Console</span>
                </div>

                <div class="flex items-center gap-1.5">
                    <span class="text-[10px] text-zinc-500 font-sans hidden sm:inline">Quick commands:</span>
                    <button
                        v-for="qc in quickCommands"
                        :key="qc"
                        type="button"
                        class="rounded bg-zinc-800 px-1.5 py-0.5 text-[10px] text-zinc-300 hover:bg-zinc-700 transition-colors"
                        @click="executeCommand(qc)"
                    >
                        {{ qc.replace('/print', '') }}
                    </button>
                    <Btn size="sm" variant="secondary" @click="clearTerminal">Clear</Btn>
                </div>
            </div>

            <!-- Terminal Output Window -->
            <div
                ref="terminalBody"
                class="flex-1 overflow-auto p-4 select-text leading-relaxed"
                @click="inputField?.focus()"
            >
                <!-- ASCII RouterOS Welcome Banner -->
                <div class="mb-4 text-indigo-400/90 whitespace-pre font-bold text-[10px] sm:text-xs leading-none">
  MMM      MMM       KKK                          TTTTTTTTTTT      KKK
  MMMM    MMMM       KKK                              TTT          KKK
  MMM MMMM MMM  III  KKK  KKK  RRRRR     OOOOOO       TTT    III   KKK  KKK
  MMM  MM  MMM  III  KKKKK     RRR  RR  OOO  OOO      TTT    III   KKKKK
  MMM      MMM  III  KKK KKK   RRRRR    OOO  OOO      TTT    III   KKK KKK
  MMM      MMM  III  KKK  KKK  RRR  RR   OOOOOO       TTT    III   KKK  KKK
                </div>
                <div class="mb-4 text-zinc-400 text-xs">
                    MikroTik RouterOS (c) 1999-2026 &bull; Session connected to <span class="text-white font-bold">{{ router.name }}</span> ({{ router.ip_address }})
                </div>

                <!-- Executed history -->
                <div v-for="(item, idx) in history" :key="idx" class="mb-3 space-y-1">
                    <div class="flex items-center gap-1 text-emerald-400 font-semibold">
                        <span class="text-indigo-400">[admin@{{ router.name }}]</span> &gt;
                        <span class="text-zinc-100 font-bold">{{ item.cmd }}</span>
                    </div>
                    <pre
                        class="pl-4 whitespace-pre-wrap rounded bg-zinc-900/50 p-2 border border-zinc-800/80"
                        :class="item.isError ? 'text-red-400 border-red-900/40 bg-red-950/20' : 'text-zinc-300'"
                    >{{ item.output }}</pre>
                </div>

                <!-- Prompt line -->
                <div class="flex items-center gap-1 pt-1">
                    <span class="text-indigo-400 shrink-0 font-semibold">[admin@{{ router.name }}]</span>
                    <span class="text-emerald-400 shrink-0">&gt;</span>
                    <input
                        ref="inputField"
                        v-model="commandInput"
                        type="text"
                        :disabled="running"
                        placeholder="Type RouterOS command (e.g. /system resource print, /ping 8.8.8.8 count=2)..."
                        class="flex-1 border-0 bg-transparent px-1 py-0.5 text-xs font-mono text-zinc-100 focus:outline-hidden placeholder:text-zinc-600"
                        @keydown.enter="executeCommand()"
                        @keydown="handleKeyDown"
                    />
                    <span v-if="running" class="animate-pulse text-amber-400 text-xs">Running...</span>
                </div>
            </div>

            <!-- Terminal Footer -->
            <div class="flex items-center justify-between border-t border-zinc-800 bg-zinc-900/60 px-4 py-1 text-[10px] text-zinc-500 font-sans">
                <span>Tips: Use <kbd class="rounded bg-zinc-800 px-1 py-0.5 font-mono text-zinc-300">↑</kbd> and <kbd class="rounded bg-zinc-800 px-1 py-0.5 font-mono text-zinc-300">↓</kbd> for command history. Press <kbd class="rounded bg-zinc-800 px-1 py-0.5 font-mono text-zinc-300">Enter</kbd> to execute.</span>
                <span class="font-mono text-zinc-400">/terminal</span>
            </div>
        </div>
    </RouterWorkspace>
</template>

