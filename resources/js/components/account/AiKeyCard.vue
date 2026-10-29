<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { destroy, update } from '@/routes/account/ai-key';

/**
 * Eigener Schlüssel für die SQL-Hilfe mit KI (Q-08). Gespeichert verschlüsselt; angezeigt werden nur die letzten
 * vier Zeichen. Gesendet werden nur Frage und Schema, nie Daten.
 */
defineProps<{ hint: string | null }>();
</script>

<template>
    <section id="ki-hilfe" class="rounded-lg border bg-background">
        <div class="flex items-center gap-2 border-b px-4 py-3">
            <Sparkles class="size-4" />
            <h2 class="text-[15px] font-semibold">{{ t('KI-Hilfe') }}</h2>
        </div>
        <div class="flex flex-col gap-3 p-4 text-sm">
            <p class="text-text-secondary">
                {{
                    t(
                        'Im SQL-Editor schreibt Claude auf Wunsch eine Anweisung aus Ihrer Frage. Dafür braucht es Ihren eigenen Schlüssel von Anthropic; die Kosten laufen über Ihr Konto dort. Gesendet werden nur Frage und Schema — nie Daten.',
                    )
                }}
            </p>

            <div v-if="hint" class="flex flex-wrap items-center gap-2">
                <span
                    >{{ t('Schlüssel hinterlegt') }}
                    <code class="font-mono">{{ hint }}</code></span
                >
                <Form
                    v-bind="destroy.form()"
                    class="ml-auto"
                    :options="{ preserveScroll: true }"
                    #default="{ processing }"
                >
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        :disabled="processing"
                        >{{ t('Entfernen') }}</Button
                    >
                </Form>
            </div>

            <Form
                v-else
                v-bind="update.form()"
                class="flex flex-col gap-2"
                :options="{ preserveScroll: true }"
                reset-on-success
                #default="{ errors, processing }"
            >
                <Label for="ai-key" class="text-[13px]">{{
                    t('Schlüssel (API key)')
                }}</Label>
                <div class="flex flex-wrap gap-2">
                    <Input
                        id="ai-key"
                        name="key"
                        type="password"
                        autocomplete="off"
                        spellcheck="false"
                        class="min-w-0 flex-1 font-mono"
                        placeholder="sk-ant-…"
                    />
                    <Button type="submit" size="sm" :disabled="processing">{{
                        t('Speichern')
                    }}</Button>
                </div>
                <InputError :message="errors.key" />
            </Form>

            <Notice>
                {{
                    t(
                        'Der Schlüssel liegt verschlüsselt in der App-Datenbank und geht nur an Anthropic. Jede Anfrage steht im Protokoll — ohne Frage und Antwort.',
                    )
                }}
            </Notice>
        </div>
    </section>
</template>
