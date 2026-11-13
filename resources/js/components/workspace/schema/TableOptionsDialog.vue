<script setup lang="ts">
import { watchDebounced } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ChangeDialog from '@/components/workspace/schema/ChangeDialog.vue';
import CollationSelect from '@/components/workspace/schema/CollationSelect.vue';
import { Input } from '@/components/ui/input';
import { useSchemaChange } from '@/composables/useSchemaChange';
import { getJson } from '@/lib/api';
import { t } from '@/lib/i18n';
import { actionUrls, databaseField } from '@/lib/schema';
import { options as optionsRoute } from '@/routes/workspace/object';
import type { OptionChoices, Workspace } from '@/types';

/**
 * Tabellenoptionen (S-10, MySQL und MariaDB): Engine, Sortierung — als Vorgabe für neue Spalten oder mit Umwandeln
 * aller Textspalten — und der nächste Wert des Zählers. Die Vorschau zeigt nur, was sich ändert.
 */
const props = defineProps<{
    workspace: Workspace;
    table: string;
}>();

const open = defineModel<boolean>('open', { required: true });

type Current = {
    engine: string | null;
    collation: string | null;
    charset: string | null;
    autoIncrement: number | null;
};

const current = ref<Current | null>(null);
const choices = ref<OptionChoices | null>(null);
const loadError = ref<string | null>(null);
const engine = ref('');
const collation = ref('');
const convert = ref(false);
/** Das Zahlenfeld liefert je nach Eingabe Text oder Zahl — verglichen wird immer als Text. */
const autoIncrement = ref<string | number>('');
const counter = computed(() => String(autoIncrement.value ?? '').trim());

const urls = computed(() => actionUrls(props.workspace, props.table));

const change = useSchemaChange({
    previewUrl: () => urls.value.preview,
    applyUrl: () => urls.value.apply,
    payload: () => ({
        operation: 'options',
        engine: engine.value || null,
        collation: collation.value || null,
        convert: convert.value,
        auto_increment:
            counter.value === '' ||
            Number(counter.value) === current.value?.autoIncrement
                ? null
                : Number(counter.value),
        ...databaseField(props.workspace),
    }),
});
const { plan, loading, failure, errors, message, processing } = change;

const changed = computed(
    () =>
        current.value !== null &&
        (engine.value !== (current.value.engine ?? '') ||
            collation.value !== (current.value.collation ?? '') ||
            convert.value ||
            (counter.value !== '' &&
                Number(counter.value) !== current.value.autoIncrement)),
);

async function load(): Promise<void> {
    change.reset();
    current.value = null;
    loadError.value = null;

    try {
        const response = await getJson<{
            current: Current;
            choices: OptionChoices;
        }>(
            optionsRoute.url(
                [
                    props.workspace.connection.id,
                    encodeURIComponent(props.workspace.schema ?? ''),
                    encodeURIComponent(props.table),
                ],
                props.workspace.database
                    ? { query: { db: props.workspace.database } }
                    : undefined,
            ),
        );
        current.value = response.current;
        choices.value = response.choices;
        engine.value = response.current.engine ?? '';
        collation.value = response.current.collation ?? '';
        convert.value = false;
        autoIncrement.value =
            response.current.autoIncrement !== null
                ? String(response.current.autoIncrement)
                : '';
    } catch {
        loadError.value = t(
            'Die Optionen der Tabelle liessen sich nicht lesen.',
        );
    }
}

watch(open, (value) => {
    if (value) {
        void load();
    }
});

watchDebounced(
    [engine, collation, convert, autoIncrement],
    () => {
        if (!open.value || current.value === null) {
            return;
        }

        if (!changed.value) {
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
        :title="t('Tabellenoptionen')"
        :description="table"
        :plan="plan"
        :loading="loading"
        :error="message ?? loadError"
        :failure="failure"
        :connection-name="
            workspace.connection.environment === 'production'
                ? workspace.connection.name
                : null
        "
        :apply-label="t('Optionen ändern')"
        :processing="processing"
        :errors="errors"
        @apply="apply"
    >
        <div v-if="current && choices" class="grid gap-3 sm:grid-cols-2">
            <div class="flex flex-col gap-1.5">
                <label for="tabelle-engine" class="text-[13px] font-medium">{{
                    t('Engine')
                }}</label>
                <select
                    id="tabelle-engine"
                    v-model="engine"
                    class="h-9 rounded-md border bg-background px-2 font-mono text-sm"
                    :aria-invalid="errors.engine ? true : undefined"
                >
                    <option
                        v-for="name in choices.engines"
                        :key="name"
                        :value="name"
                    >
                        {{ name }}
                    </option>
                </select>
                <InputError :message="errors.engine" />
            </div>
            <div
                v-if="current.autoIncrement !== null"
                class="flex flex-col gap-1.5"
            >
                <label for="tabelle-zaehler" class="text-[13px] font-medium">{{
                    t('Nächster Wert des Zählers')
                }}</label>
                <Input
                    id="tabelle-zaehler"
                    v-model="autoIncrement"
                    type="number"
                    min="1"
                    class="font-mono"
                    :aria-invalid="errors.auto_increment ? true : undefined"
                />
                <InputError :message="errors.auto_increment" />
            </div>
            <div class="flex flex-col gap-1.5 sm:col-span-2">
                <label
                    for="tabelle-sortierung"
                    class="text-[13px] font-medium"
                    >{{ t('Zeichensatz und Sortierung') }}</label
                >
                <CollationSelect
                    id="tabelle-sortierung"
                    v-model="collation"
                    :collations="choices.collations"
                    :invalid="!!errors.collation"
                />
                <InputError :message="errors.collation" />
                <label class="mt-1 flex items-start gap-2 text-sm">
                    <input
                        v-model="convert"
                        type="checkbox"
                        class="mt-1 accent-primary"
                    />
                    <span
                        >{{ t('Auch die bestehenden Spalten umwandeln') }}
                        <span class="block text-[12px] text-text-secondary">{{
                            t(
                                'CONVERT TO — sonst gilt die Sortierung nur für neue Spalten.',
                            )
                        }}</span></span
                    >
                </label>
            </div>
            <InputError class="sm:col-span-2" :message="errors.options" />
        </div>
        <p
            v-else-if="!loadError"
            class="text-[13px] text-text-secondary"
            role="status"
        >
            {{ t('Optionen werden gelesen …') }}
        </p>
    </ChangeDialog>
</template>
