<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Table2, X } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SqlPanel from '@/components/workspace/schema/SqlPanel.vue';
import TableEditor from '@/components/workspace/schema/TableEditor.vue';
import { Input } from '@/components/ui/input';
import { useSchemaChange } from '@/composables/useSchemaChange';
import { titlePrefix } from '@/lib/environment';
import { t } from '@/lib/i18n';
import {
    createPayload,
    databaseField,
    emptyDraft,
    tableUrls,
} from '@/lib/schema';
import { schemaHref } from '@/lib/workspace';
import type { ColumnDraft, SchemaEditorSettings, Workspace } from '@/types';

/**
 * Tabelle anlegen (S-02): Name, Spalten, Primärschlüssel, Indizes und Fremdschlüssel; rechts das SQL aus
 * Blueprint::toSql(), das genau so läuft. Vorgeschlagen ist eine Spalte «id» mit Auto-Increment.
 */
const props = defineProps<{
    workspace: Workspace;
    editor: SchemaEditorSettings;
    targets?: Record<string, string[]>;
}>();

const schema = computed(() => props.workspace.schema ?? '');
const draft = ref(emptyDraft(props.editor.types));
const snapshot = new Map<string, Omit<ColumnDraft, 'key'>>();
const urls = computed(() => tableUrls(props.workspace, schema.value));

const change = useSchemaChange({
    previewUrl: () => urls.value.preview,
    applyUrl: () => urls.value.apply,
    payload: () => ({
        ...createPayload(draft.value),
        ...databaseField(props.workspace),
    }),
});
const { plan, loading, failure, errors, message, processing } = change;

/** Ohne Namen gibt es noch nichts zu zeigen — keine Fehlermeldung, bevor man getippt hat. */
function refresh(): void {
    if (draft.value.name.trim() === '') {
        change.reset();

        return;
    }

    void change.refresh();
}

watchDebounced(draft, refresh, { deep: true, debounce: 400 });
onMounted(refresh);

function discard(): void {
    router.visit(schemaHref(props.workspace, schema.value));
}

const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${t('Tabelle anlegen')} · ${props.workspace.connection.name}`,
);
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-4 px-4 py-4 sm:px-6">
        <header class="flex flex-wrap items-start gap-3">
            <div class="flex min-w-0 flex-col gap-0.5">
                <h1 class="flex items-center gap-2 text-xl font-semibold">
                    <Table2 class="size-5 text-text-secondary" />{{
                        t('Tabelle anlegen')
                    }}
                </h1>
                <p class="text-sm text-text-secondary">
                    {{
                        workspace.schemaKind === 'schema'
                            ? t('im Schema :name', { name: schema })
                            : t('in der Datenbank :name', { name: schema })
                    }}
                </p>
            </div>
            <Link
                :href="schemaHref(workspace, schema)"
                class="ml-auto inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-[13px] font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
            >
                <X class="size-3.5" />{{ t('Abbrechen') }}
            </Link>
        </header>

        <div class="flex max-w-md flex-col gap-1.5">
            <label for="tabellen-name" class="text-[13px] font-medium">{{
                t('Name der Tabelle')
            }}</label>
            <Input
                id="tabellen-name"
                v-model="draft.name"
                autocomplete="off"
                spellcheck="false"
                class="font-mono"
                :aria-invalid="errors.name ? true : undefined"
            />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_26rem]">
            <TableEditor
                v-model:draft="draft"
                mode="create"
                :editor="editor"
                :targets="targets"
                :snapshot="snapshot"
                :primary-columns="[]"
                :errors="errors"
            />
            <SqlPanel
                :workspace="workspace"
                :plan="plan"
                :loading="loading"
                :failure="failure"
                :message="message"
                :errors="errors"
                :apply-label="t('Tabelle anlegen')"
                :processing="processing"
                :empty="t('Noch nichts zu erzeugen.')"
                @discard="discard"
                @apply="change.apply($event)"
            />
        </div>
    </div>
</template>
