<script setup lang="ts">
import { ChevronDown, Circle, CircleCheck, CircleX, Zap } from '@lucide/vue';
import Notice from '@/components/Notice.vue';
import { Spinner } from '@/components/ui/spinner';
import { formatRecent } from '@/lib/format';
import type { ConnectionTestResult } from '@/types';

/** «Verbindung testen» (V-02, Wireframe 04): Prüfschritte, Fehler in Klartext, Meldung des Servers zum Aufklappen. */
defineProps<{
    result: ConnectionTestResult | null;
    testing: boolean;
    production: boolean;
}>();
</script>

<template>
    <aside class="flex min-w-0 flex-col gap-4">
        <section class="rounded-lg border bg-background" aria-live="polite">
            <div class="flex items-center gap-2 border-b px-4 py-3">
                <Zap class="size-4" />
                <h2 class="text-[15px] font-semibold">Verbindung testen</h2>
                <span
                    v-if="result && !testing"
                    class="ml-auto text-xs text-muted-foreground tabular-nums"
                    >getestet {{ formatRecent(result.testedAt) }} ·
                    {{ result.durationMs }} ms</span
                >
                <Spinner v-if="testing" class="ml-auto" />
            </div>
            <div class="flex flex-col gap-3 px-4 py-3">
                <p v-if="!result" class="text-sm text-text-secondary">
                    Prüft Host, Erreichbarkeit und Anmeldung mit den Angaben im
                    Formular — vor dem Speichern.
                </p>

                <template v-else>
                    <Notice v-if="result.ok" variant="success">
                        <b class="font-semibold text-success"
                            >Verbunden ·
                            {{
                                result.steps.find((s) => s.key === 'login')
                                    ?.detail
                            }}</b
                        >
                    </Notice>

                    <div
                        v-else-if="result.problem"
                        class="flex flex-col gap-2 rounded-lg border border-destructive bg-destructive-soft px-3.5 py-3"
                        role="alert"
                    >
                        <div
                            class="flex items-center gap-2 font-semibold text-destructive"
                        >
                            <CircleX class="size-4 shrink-0" />{{
                                result.problem.title
                            }}
                        </div>
                        <p class="text-[13px] leading-[18px]">
                            {{ result.problem.explanation }}
                        </p>
                        <p
                            v-if="result.problem.detail"
                            class="font-mono text-xs break-all text-text-secondary"
                        >
                            {{ result.problem.detail }}
                        </p>
                        <details
                            v-if="result.problem.serverMessage"
                            class="group border-t border-destructive/30 pt-2"
                        >
                            <summary
                                class="flex cursor-pointer list-none items-center gap-1 text-xs text-text-secondary"
                            >
                                <ChevronDown
                                    class="size-3.5 transition-transform group-open:rotate-180"
                                />Meldung des Servers
                            </summary>
                            <pre
                                class="mt-1.5 rounded-md border bg-background px-2.5 py-2 font-mono text-xs leading-[18px] break-all whitespace-pre-wrap"
                                >{{ result.problem.serverMessage }}</pre>
                        </details>
                    </div>

                    <ul class="divide-y">
                        <li
                            v-for="step in result.steps"
                            :key="step.key"
                            class="flex items-start gap-2.5 py-2 text-[13px] leading-[18px]"
                        >
                            <CircleCheck
                                v-if="step.state === 'ok'"
                                class="mt-px size-4 shrink-0 text-success"
                            />
                            <CircleX
                                v-else-if="step.state === 'failed'"
                                class="mt-px size-4 shrink-0 text-destructive"
                            />
                            <Circle
                                v-else
                                class="mt-px size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="flex min-w-0 flex-col">
                                <b class="font-semibold">{{ step.label }}</b>
                                <span
                                    v-if="step.detail"
                                    class="break-all text-text-secondary"
                                    >{{ step.detail }}</span
                                >
                            </span>
                        </li>
                    </ul>
                </template>
            </div>
        </section>

        <Notice v-if="production">
            <b class="font-semibold">Eigenes Lesekonto empfohlen.</b> Für
            Produktion ein Konto nur mit Leserechten — dann hilft der
            Schreibschutz doppelt.
        </Notice>
    </aside>
</template>
