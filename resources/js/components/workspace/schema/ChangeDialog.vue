<script setup lang="ts">
import { CircleX } from '@lucide/vue';
import ApplyBox from '@/components/workspace/schema/ApplyBox.vue';
import PlanView from '@/components/workspace/schema/PlanView.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { t } from '@/lib/i18n';
import type { SchemaFailure, SchemaPlanPreview } from '@/types';

/**
 * Dialog für Strukturänderungen ohne eigene Seite (S-04 umbenennen, kopieren, leeren, löschen; S-05 Schemas) im Stil
 * der SQL-Vorschau (Wireframe 6): oben die Eingaben, darunter genau das SQL mit dem Hinweis je Treiber, unten die
 * Bestätigung. Fehler des Servers bleiben im Dialog stehen.
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string | null;
        plan: SchemaPlanPreview | null;
        loading?: boolean;
        error?: string | null;
        failure?: SchemaFailure | null;
        connectionName: string | null;
        applyLabel: string;
        processing?: boolean;
        errors?: Record<string, string | undefined>;
    }>(),
    {
        description: null,
        loading: false,
        error: null,
        failure: null,
        processing: false,
        errors: () => ({}),
    },
);

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{
    apply: [confirmation: { confirmName: string; confirmation: string }];
}>();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[90svh] flex-col gap-4 sm:max-w-2xl">
            <DialogTitle>{{ title }}</DialogTitle>
            <DialogDescription v-if="description">{{
                description
            }}</DialogDescription>

            <div class="flex min-h-0 flex-col gap-4 overflow-y-auto">
                <slot />

                <div
                    v-if="error"
                    class="flex items-start gap-2 rounded-lg border border-destructive bg-destructive-soft px-3.5 py-3 text-sm"
                    role="alert"
                >
                    <CircleX class="mt-0.5 size-4 shrink-0 text-destructive" />
                    <span class="wrap-break-word">{{ error }}</span>
                </div>

                <PlanView
                    :plan="plan"
                    :loading="loading"
                    :failure="failure"
                    :empty="t('Keine Anweisung nötig.')"
                />
            </div>

            <ApplyBox
                :plan="error ? null : plan"
                :connection-name="connectionName"
                :apply-label="applyLabel"
                :processing="processing"
                :disabled="loading"
                :errors="errors"
                :discard-label="t('Abbrechen')"
                @discard="open = false"
                @apply="emit('apply', $event)"
            />
        </DialogContent>
    </Dialog>
</template>
