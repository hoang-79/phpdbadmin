<script setup lang="ts">
import { computed, watch } from 'vue';
import ChangeDialog from '@/components/workspace/schema/ChangeDialog.vue';
import { useSchemaChange } from '@/composables/useSchemaChange';
import { t } from '@/lib/i18n';
import { databaseField, schemaUrls } from '@/lib/schema';
import type { SchemaPayload, Workspace } from '@/types';

/**
 * Datenbank bzw. Schema löschen (S-05) — aus dem Menü der Schema-Seite und direkt im Navigator. Die Vorschau zeigt das
 * SQL und was verloren geht; angewendet wird erst mit dem eingetippten Namen (auf Produktion dazu der Name der
 * Verbindung). `scope="database"` löscht bei PostgreSQL mit «alle Datenbanken» eine ganze Datenbank, `scope="schema"`
 * ein Schema (PostgreSQL) bzw. eine Datenbank (MySQL, MariaDB).
 */
const props = defineProps<{
    workspace: Workspace;
    name: string;
    scope: 'schema' | 'database';
}>();

const open = defineModel<boolean>('open', { required: true });

const urls = computed(() => schemaUrls(props.workspace));

const change = useSchemaChange({
    previewUrl: () => urls.value.preview,
    applyUrl: () => urls.value.apply,
    payload: (): SchemaPayload =>
        props.scope === 'database'
            ? { operation: 'drop-database', name: props.name }
            : {
                  operation: 'drop',
                  name: props.name,
                  ...databaseField(props.workspace),
              },
});
const { plan, loading, failure, errors, message, processing } = change;

// Beim Öffnen (und wenn ein anderer Name kommt) die Vorschau frisch laden.
watch(
    [open, () => props.name, () => props.scope],
    ([isOpen]) => {
        if (isOpen) {
            change.reset();
            void change.refresh();
        }
    },
    { immediate: true },
);

const title = computed(() =>
    props.scope === 'schema' && props.workspace.schemaKind === 'schema'
        ? t('Schema löschen')
        : t('Datenbank löschen'),
);

function apply(confirmation: {
    confirmName: string;
    confirmation: string;
}): void {
    change.apply(confirmation, () => {
        open.value = false;
    });
}
</script>

<template>
    <ChangeDialog
        v-model:open="open"
        :title="title"
        :description="name"
        :plan="plan"
        :loading="loading"
        :error="message"
        :failure="failure"
        :connection-name="
            workspace.connection.environment === 'production'
                ? workspace.connection.name
                : null
        "
        :apply-label="title"
        :processing="processing"
        :errors="errors"
        @apply="apply"
    />
</template>
