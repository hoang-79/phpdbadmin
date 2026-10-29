<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Info, X } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import ObjectHeader from '@/components/workspace/ObjectHeader.vue';
import SqlPanel from '@/components/workspace/schema/SqlPanel.vue';
import TableEditor from '@/components/workspace/schema/TableEditor.vue';
import { useSchemaChange } from '@/composables/useSchemaChange';
import { titlePrefix } from '@/lib/environment';
import { t, tc } from '@/lib/i18n';
import {
    alterPayload,
    alterUrls,
    databaseField,
    draftFromProps,
} from '@/lib/schema';
import { structureHref } from '@/lib/workspace';
import type {
    ColumnDraft,
    ObjectStructure,
    SchemaEditorSettings,
    TableDraftProps,
    Workspace,
} from '@/types';

/**
 * Tabelle ändern (S-03, Wireframe 9): links der Entwurf — Spalten hinzufügen, ändern, umbenennen, löschen; Indizes
 * und Fremdschlüssel; Kommentare —, rechts das erzeugte SQL mit dem Verfahren je Treiber (ADR-003). Scheitert das
 * Anwenden, steht dort, was gelaufen ist; bei MySQL und MariaDB lädt die Seite den neuen Stand.
 */
const props = defineProps<{
    workspace: Workspace;
    structure: ObjectStructure;
    draft: TableDraftProps;
    editor: SchemaEditorSettings;
    targets?: Record<string, string[]>;
}>();

const table = computed(() => props.structure.object.name);
const state = ref(draftFromProps(table.value, props.draft));
const snapshot = computed(
    () =>
        new Map<string, Omit<ColumnDraft, 'key'>>(
            props.draft.columns.map((column) => [
                column.original ?? column.name,
                { ...column, drop: false, primary: false },
            ]),
        ),
);
const primaryColumns = computed(() =>
    props.structure.columns
        .filter((column) => column.primary)
        .map((column) => column.name),
);
const urls = computed(() => alterUrls(props.workspace, table.value));

const change = useSchemaChange({
    previewUrl: () => urls.value.preview,
    applyUrl: () => urls.value.apply,
    payload: () => ({
        ...alterPayload(state.value, snapshot.value, props.draft.comment),
        ...databaseField(props.workspace),
    }),
});
const { plan, loading, failure, errors, message, processing } = change;

watchDebounced(state, () => void change.refresh(), {
    deep: true,
    debounce: 400,
});
onMounted(() => void change.refresh());

// Nach einem Teilerfolg (MySQL, MariaDB) kommt der neue Stand der Tabelle — der Entwurf beginnt dort neu.
watch(
    () => props.draft,
    (next) => {
        state.value = draftFromProps(table.value, next);
    },
);

function discard(): void {
    state.value = draftFromProps(table.value, props.draft);
    change.reset();
}

const pending = computed(() => plan.value?.statements.length ?? 0);
const applyLabel = computed(() =>
    tc(':count Anweisung anwenden|:count Anweisungen anwenden', pending.value),
);
const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${t('Tabelle ändern')} · ${table.value} · ${props.workspace.connection.name}`,
);
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col">
        <ObjectHeader
            :workspace="workspace"
            :object="structure.object"
            active="structure"
        />

        <div class="flex flex-col gap-4 px-4 py-4 sm:px-6">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-[15px] font-semibold">
                    {{ t('Struktur wird bearbeitet') }}
                </h1>
                <Link
                    :href="structureHref(workspace, table)"
                    class="ml-auto inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-[13px] font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <X class="size-3.5" />{{ t('Bearbeiten beenden') }}
                </Link>
            </div>

            <p
                class="flex items-start gap-2 text-[13px] text-text-secondary md:hidden"
            >
                <Info class="mt-0.5 size-3.5 shrink-0" />{{
                    t(
                        'Strukturänderungen sind für den Desktop ausgelegt; hier sehen und prüfen Sie sie.',
                    )
                }}
            </p>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_26rem]">
                <TableEditor
                    v-model:draft="state"
                    mode="alter"
                    :editor="editor"
                    :targets="targets"
                    :snapshot="snapshot"
                    :primary-columns="primaryColumns"
                    :errors="errors"
                />
                <SqlPanel
                    :workspace="workspace"
                    :plan="plan"
                    :loading="loading"
                    :failure="failure"
                    :message="message"
                    :errors="errors"
                    :apply-label="applyLabel"
                    :processing="processing"
                    :empty="
                        t(
                            'Noch keine Änderung — bearbeiten Sie Spalten, Indizes oder Fremdschlüssel.',
                        )
                    "
                    @discard="discard"
                    @apply="change.apply($event)"
                />
            </div>
        </div>
    </div>
</template>
