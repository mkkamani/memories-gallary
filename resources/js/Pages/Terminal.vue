<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, nextTick } from 'vue';

const command = ref('');
const loading = ref(false);
const output = ref('');
const isError = ref(false);
const outputViewport = ref(null);
const previousCommand = ref('');

const canExecute = computed(() => !loading.value && command.value.trim().length > 0);

const normalizeOutput = (value) => {
    if (!value) {
        return '';
    }

    const lineBreaksNormalized = String(value).replace(/<br\s*\/?\s*>/gi, '\n');

    return lineBreaksNormalized
        .replace(/\r\n/g, '\n')
        .replace(/[\t ]+$/gm, '')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
};

const executeCommand = async () => {
    if (!canExecute.value) {
        return;
    }

    const commandToRun = command.value.trim();

    loading.value = true;
    isError.value = false;
    output.value = '';
    previousCommand.value = commandToRun;

    try {
        const response = await window.axios.post(route('terminal.execute'), {
            command: commandToRun,
        });

        const responseOutput = normalizeOutput(response?.data?.output);
        output.value = responseOutput || 'Command executed successfully.';
        isError.value = false;
    } catch (error) {
        const responseData = error?.response?.data;
        const message =
            responseData?.output ||
            responseData?.message ||
            error?.message ||
            'Error executing command.';

        output.value = normalizeOutput(message) || 'Error executing command.';
        isError.value = true;
    } finally {
        loading.value = false;
        await nextTick();

        if (outputViewport.value) {
            outputViewport.value.scrollTop = outputViewport.value.scrollHeight;
        }
    }
};

const clearOutput = () => {
    output.value = '';
    isError.value = false;
};
</script>

<template>
    <Head title="Terminal" />

    <AuthenticatedLayout>
        <section class="animate-fade-in space-y-6 py-2 terminal-scene">
            <header class="space-y-1">
                <h1 class="font-heading text-3xl font-bold text-foreground">Terminal</h1>
                <p class="text-sm text-muted-foreground">
                    Execute project commands directly from the app.
                </p>
            </header>

            <div class="terminal-shell">
                <div class="terminal-titlebar">
                    <div class="terminal-dots" aria-hidden="true">
                        <span class="dot dot-close" />
                        <span class="dot dot-minimize" />
                        <span class="dot dot-expand" />
                    </div>
                    <p class="terminal-title">memories@cypherox: ~</p>
                    <button
                        type="button"
                        class="terminal-clear"
                        @click="clearOutput"
                        :disabled="loading || !output"
                    >
                        clear
                    </button>
                </div>

                <form class="terminal-input-row" @submit.prevent="executeCommand">
                    <label for="command" class="terminal-prompt">memories@cypherox:~$</label>
                    <input
                        id="command"
                        v-model="command"
                        type="text"
                        required
                        autofocus
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Type a command and press Enter"
                        class="terminal-input"
                    />
                    <button
                        type="submit"
                        :disabled="!canExecute"
                        class="terminal-run"
                    >
                        {{ loading ? 'running' : 'run' }}
                    </button>
                </form>

                <div ref="outputViewport" class="terminal-output" :class="isError ? 'terminal-output-error' : ''">
                    <template v-if="loading">
                        <p class="terminal-line terminal-dim">Executing {{ previousCommand || command }}...</p>
                        <div class="terminal-loader" aria-hidden="true" />
                    </template>

                    <template v-else-if="output">
                        <p class="terminal-line terminal-dim" v-if="previousCommand">
                            memories@cypherox:~$ {{ previousCommand }}
                        </p>
                        <pre class="terminal-content" v-text="output" />
                    </template>

                    <template v-else>
                        <p class="terminal-line terminal-dim">No output yet.</p>
                        <p class="terminal-line terminal-help">Try: ls, pwd, php artisan list</p>
                    </template>
                </div>

                <div class="terminal-status">
                    <span :class="loading ? 'status-running' : 'status-idle'">
                        {{ loading ? 'RUNNING' : 'READY' }}
                    </span>
                    <span class="terminal-dim">Shell: bash</span>
                </div>
            </div>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
.terminal-scene {
    position: relative;
}

.terminal-shell {
    border: 1px solid rgba(21, 25, 34, 0.7);
    border-radius: 18px;
    overflow: hidden;
    background: radial-gradient(circle at 20% 0%, #1a2130 0%, #10151f 55%, #0a0f17 100%);
    box-shadow: 0 20px 50px rgba(8, 10, 15, 0.4);
}

.terminal-titlebar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 14px;
    border-bottom: 1px solid rgba(107, 123, 153, 0.22);
    background: linear-gradient(180deg, rgba(53, 63, 81, 0.7), rgba(23, 29, 40, 0.72));
}

.terminal-dots {
    display: flex;
    align-items: center;
    gap: 7px;
}

.dot {
    width: 11px;
    height: 11px;
    border-radius: 999px;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.2);
}

.dot-close {
    background: #ff5f57;
}

.dot-minimize {
    background: #febc2e;
}

.dot-expand {
    background: #28c840;
}

.terminal-title {
    flex: 1;
    margin: 0;
    font-family: 'Ubuntu Mono', 'Courier New', monospace;
    font-size: 0.84rem;
    color: #d4deee;
    text-align: center;
}

.terminal-clear {
    border: 1px solid rgba(138, 155, 185, 0.36);
    background: rgba(27, 33, 46, 0.75);
    color: #b7c8e7;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 4px 10px;
    transition: all 0.18s ease;
}

.terminal-clear:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.terminal-clear:not(:disabled):hover {
    background: rgba(38, 47, 64, 0.92);
    color: #e5efff;
}

.terminal-input-row {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 10px;
    align-items: center;
    padding: 14px;
    border-bottom: 1px solid rgba(107, 123, 153, 0.16);
}

.terminal-prompt {
    font-family: 'Ubuntu Mono', 'Courier New', monospace;
    font-size: 0.9rem;
    color: #75f4a4;
    white-space: nowrap;
}

.terminal-input {
    width: 100%;
    border: 1px solid rgba(109, 127, 157, 0.3);
    border-radius: 10px;
    background: rgba(10, 14, 21, 0.8);
    color: #e7f0ff;
    font-family: 'Ubuntu Mono', 'Courier New', monospace;
    font-size: 0.95rem;
    padding: 10px 12px;
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.terminal-input::placeholder {
    color: #8ea1c5;
}

.terminal-input:focus {
    border-color: rgba(98, 197, 255, 0.7);
    box-shadow: 0 0 0 3px rgba(98, 197, 255, 0.2);
}

.terminal-run {
    border: 0;
    border-radius: 10px;
    padding: 10px 13px;
    background: linear-gradient(180deg, #2ec67a 0%, #149357 100%);
    color: #f4fff9;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    transition: filter 0.15s ease, opacity 0.15s ease;
}

.terminal-run:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.terminal-run:not(:disabled):hover {
    filter: brightness(1.08);
}

.terminal-output {
    min-height: 320px;
    max-height: 520px;
    overflow: auto;
    padding: 16px 14px;
    background: rgba(7, 10, 15, 0.86);
    color: #d8f2ff;
    font-family: 'Ubuntu Mono', 'Courier New', monospace;
    font-size: 0.94rem;
    line-height: 1.45;
}

.terminal-output-error {
    color: #ffd1cc;
}

.terminal-line {
    margin: 0;
}

.terminal-help {
    margin-top: 6px;
    color: #86a0c6;
}

.terminal-content {
    margin: 6px 0 0;
    white-space: pre-wrap;
    word-break: break-word;
}

.terminal-dim {
    color: #86a0c6;
}

.terminal-status {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 14px 10px;
    font-family: 'Ubuntu Mono', 'Courier New', monospace;
    font-size: 0.8rem;
    border-top: 1px solid rgba(107, 123, 153, 0.18);
    background: rgba(9, 13, 20, 0.78);
}

.status-running {
    color: #ffd36b;
    font-weight: 700;
}

.status-idle {
    color: #7ef0a0;
    font-weight: 700;
}

.terminal-loader {
    width: 120px;
    height: 4px;
    margin-top: 9px;
    border-radius: 999px;
    background: linear-gradient(90deg, #2ec67a 0%, #76d8ff 50%, #2ec67a 100%);
    background-size: 220% 100%;
    animation: terminal-loader 1.2s linear infinite;
}

@keyframes terminal-loader {
    0% {
        background-position: 220% 0;
    }
    100% {
        background-position: -220% 0;
    }
}

@media (max-width: 768px) {
    .terminal-input-row {
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .terminal-title {
        text-align: left;
        font-size: 0.78rem;
    }
}
</style>
