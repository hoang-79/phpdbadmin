<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleX, Code, Copy, SquareTerminal } from '@lucide/vue';
import { toast } from 'vue-sonner';
import ApplyBox from '@/components/workspace/schema/ApplyBox.vue';
import PlanView from '@/components/workspace/schema/PlanView.vue';
import { t } from '@/lib/i18n';
import { editorHref } from '@/lib/schema';
import type { SchemaFailure, SchemaPlanPreview, Workspace } from '@/types';

/**
 * Rechte Spalte von «Tabelle anlegen» und «Tabelle ändern» (Wireframe 9): das erzeugte SQL, das genau so läuft,
 * Kopieren und «Im Editor öffnen», darunter die Bestätigung. Fehler, die zu keinem Feld gehören (Server, Katalog),
 * stehen hier.
 */
const props = defineProps<{
    workspace: Workspace;
    plan: SchemaPlanPreview | null;
    loading: boolean;
    failure: SchemaFailure | null;
    message: string | null;
    errors: Record<string, string | undefined>;
    applyLabel: string;
    processing: boolean;
    empty: string;
}>();

const emit = defineEmits<{
    apply: [confirmation: { confirmName: string; confirmation: string }];
    discard: [];
}>();

async function copy(): Promise<void> {
    const statements =
        props.plan?.statements.map((statement) => `${statement.sql};`) ?? [];

    try {
        await navigator.clipboard.writeText(statements.join('\n'));
        toast.success(t('SQL kopiert.'));
    } catch {
        toast.error(
            t('Kopieren ging nicht — markieren Sie den Text von Hand.'),
        );
    }
}
</script>

<template>
    <aside
        class="flex flex-col gap-3 self-start xl:sticky xl:top-4"
        :aria-label="t('Erzeugtes SQL')"
    >
        <section class="flex flex-col rounded-lg border bg-background">
            <div class="flex items-center gap-2 border-b px-4 py-3">
                <h2 class="flex items-center gap-2 text-[15px] font-semibold">
                    <Code class="size-4" />{{ t('Erzeugtes SQL') }}
                </h2>
                <span class="hidden text-xs text-text-secondary sm:inline">{{
                    t('wird genau so ausgeführt')
                }}</span>
                <div class="ml-auto flex gap-1">
                    <button
                        type="button"
                        class="inline-flex size-8 items-center justify-center rounded-md border outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                        :disabled="!plan?.statements.length"
                        :aria-label="t('SQL kopieren')"
                        :title="t('SQL kopieren')"
                        @click="copy"
                    >
                        <Copy class="size-3.5" />
                    </button>
                    <Link
                        v-if="plan?.statements.length"
                        :href="
                            editorHref(
                                workspace,
                                plan.statements.map(
                                    (statement) => statement.sql,
                                ),
                            )
                        "
                        class="inline-flex size-8 items-center justify-center rounded-md border outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                        :aria-label="t('Im Editor öffnen')"
                        :title="t('Im Editor öffnen')"
                    >
                        <SquareTerminal class="size-3.5" />
                    </Link>
                </div>
            </div>
            <div class="flex flex-col gap-3 p-3">
                <div
                    v-if="message"
                    class="flex items-start gap-2 rounded-lg border border-destructive bg-destructive-soft px-3.5 py-3 text-sm"
                    role="alert"
                >
                    <CircleX class="mt-0.5 size-4 shrink-0 text-destructive" />
                    <span class="wrap-break-word">{{ message }}</span>
                </div>
                <PlanView
                    :plan="plan"
                    :loading="loading"
                    :failure="failure"
                    :empty="empty"
                />
            </div>
        </section>

        <ApplyBox
            :plan="message ? null : plan"
            :connection-name="
                workspace.connection.environment === 'production'
                    ? workspace.connection.name
                    : null
            "
            :apply-label="applyLabel"
            :processing="processing"
            :disabled="loading"
            :errors="errors"
            :discard-label="t('Verwerfen')"
            @discard="emit('discard')"
            @apply="emit('apply', $event)"
        />
    </aside>
</template>
