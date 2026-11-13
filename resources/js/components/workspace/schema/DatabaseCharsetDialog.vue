<script setup lang="ts">
import { watchDebounced } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ChangeDialog from '@/components/workspace/schema/ChangeDialog.vue';
import CollationSelect from '@/components/workspace/schema/CollationSelect.vue';
import { useSchemaChange } from '@/composables/useSchemaChange';
import { getJson } from '@/lib/api';
import { t } from '@/lib/i18n';
import { databaseField, schemaUrls } from '@/lib/schema';
import { options as optionsRoute } from '@/routes/workspace/schemas';
import type { OptionChoices, Workspace } from '@/types';

/**
 * Zeichensatz und Sortierung einer Datenbank (S-12, MySQL und MariaDB) — als Vorgabe für neue Tabellen, auf Wunsch
 * auch alle bestehenden umgewandelt. Die Vorschau zählt die Tabellen, die dabei neu aufgebaut werden.
 */
const props = defineProps<{
    workspace: Workspace;
    schema: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const current = ref<{ charset: string; collation: string } | null>(null);
const choices = ref<OptionChoices | null>(null);
const loadError = ref<string | null>(null);
const collation = ref('');
const convertTables = ref(false);
const urls = computed(() => schemaUrls(props.workspace));

const change = useSchemaChange({
    previewUrl: () => urls.value.preview,
    applyUrl: () => urls.value.apply,
    payload: () => ({
        operation: 'charset',
        name: props.schema,
        collation: collation.value,
        convert_tables: convertTables.value,
        ...databaseField(props.workspace),
    }),
});
const { plan, loading, failure, errors, message, processing } = change;

async function load(): Promise<void> {
    change.reset();
    current.value = null;
    loadError.value = null;

    try {
        const response = await getJson<{
            current: { charset: string; collation: string };
            choices: OptionChoices;
        }>(
            optionsRoute.url(props.workspace.connection.id, {
                query: { name: props.schema },
            }),
        );
        current.value = response.current;
        choices.value = response.choices;
        collation.value = response.current.collation;
        convertTables.value = false;
    } catch {
        loadError.value = t(
            'Zeichensatz und Sortierung der Datenbank liessen sich nicht lesen.',
        );
    }
}

watch(open, (value) => {
    if (value) {
        void load();
    }
});

watchDebounced(
    [collation, convertTables],
    () => {
        if (!open.value || current.value === null) {
            return;
        }

        if (
            collation.value === current.value.collation &&
            !convertTables.value
        ) {
            change.reset();

            return;
        }

        void change.refresh();
    },
    { debounce: 350 },
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
        :title="t('Zeichensatz und Sortierung')"
        :description="schema"
        :plan="plan"
        :loading="loading"
        :error="message ?? loadError"
        :failure="failure"
        :connection-name="
            workspace.connection.environment === 'production'
                ? workspace.connection.name
                : null
        "
        :apply-label="t('Ändern')"
        :processing="processing"
        :errors="errors"
        @apply="apply"
    >
        <div v-if="current && choices" class="flex flex-col gap-1.5">
            <label for="datenbank-sortierung" class="text-[13px] font-medium">{{
                t('Zeichensatz und Sortierung')
            }}</label>
            <CollationSelect
                id="datenbank-sortierung"
                v-model="collation"
                :collations="choices.collations"
                :invalid="!!errors.collation"
            />
            <InputError :message="errors.collation ?? errors.name" />
            <label class="mt-1 flex items-start gap-2 text-sm">
                <input
                    v-model="convertTables"
                    type="checkbox"
                    class="mt-1 accent-primary"
                />
                <span
                    >{{ t('Auch alle bestehenden Tabellen umwandeln') }}
                    <span class="block text-[12px] text-text-secondary">{{
                        t(
                            'Sonst gilt die Sortierung nur für neue Tabellen. Jede Tabelle wird dabei neu aufgebaut.',
                        )
                    }}</span></span
                >
            </label>
        </div>
        <p
            v-else-if="!loadError"
            class="text-[13px] text-text-secondary"
            role="status"
        >
            {{ t('Wird gelesen …') }}
        </p>
    </ChangeDialog>
</template>
