<script setup lang="ts">
import {
    ArrowRight,
    KeyRound,
    Link2,
    List,
    Plus,
    Table2,
    Trash2,
    Undo2,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import ColumnRow from '@/components/workspace/schema/ColumnRow.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import {
    isChanged,
    isRenamed,
    newColumn,
    newForeignKey,
    newIndex,
} from '@/lib/schema';
import type {
    ColumnDraft,
    ForeignKeyDraft,
    IndexDraft,
    SchemaEditorSettings,
    TableDraftState,
} from '@/types';

/**
 * Entwurf einer Tabelle (S-02 anlegen, S-03 ändern; Wireframe 9): Spalten, Indizes, Fremdschlüssel mit Ziel aus dem
 * Katalog und ON DELETE/UPDATE, Kommentar. Löscht man eine Spalte, fallen Indizes und Fremdschlüssel auf ihr mit weg —
 * sichtbar, damit die Vorschau keine Überraschung bringt.
 */
const props = defineProps<{
    mode: 'create' | 'alter';
    editor: SchemaEditorSettings;
    targets?: Record<string, string[]>;
    snapshot: Map<string, Omit<ColumnDraft, 'key'>>;
    primaryColumns: string[];
    errors: Record<string, string | undefined>;
}>();

const draft = defineModel<TableDraftState>('draft', { required: true });

/** Spaltennamen nach der Änderung — Auswahl für Indizes und Fremdschlüssel. */
const finalColumns = computed(() =>
    draft.value.columns
        .filter((column) => !column.drop && column.name.trim() !== '')
        .map((column) => column.name.trim()),
);

function state(
    column: ColumnDraft,
): 'new' | 'renamed' | 'changed' | 'dropped' | null {
    if (column.original === null) {
        return props.mode === 'alter' ? 'new' : null;
    }

    if (column.drop) {
        return 'dropped';
    }

    if (isRenamed(column)) {
        return 'renamed';
    }

    return isChanged(column, props.snapshot) ? 'changed' : null;
}

function addColumn(): void {
    draft.value.columns.push(newColumn());
}

function removeColumn(position: number): void {
    draft.value.columns.splice(position, 1);
}

/** Spalte löschen bzw. zurücknehmen; Indizes und Fremdschlüssel auf ihr gehen mit. */
function toggleDrop(column: ColumnDraft): void {
    column.drop = !column.drop;
    const name = column.original ?? column.name;

    for (const index of draft.value.indexes) {
        if (
            index.original !== null &&
            !index.primary &&
            index.columns.includes(name)
        ) {
            index.drop = column.drop;
        }
    }

    for (const foreignKey of draft.value.foreignKeys) {
        if (foreignKey.original !== null && foreignKey.columns.includes(name)) {
            foreignKey.drop = column.drop;
        }
    }
}

function addIndex(): void {
    draft.value.indexes.push(newIndex());
}

function addForeignKey(): void {
    const foreignKey = newForeignKey();
    foreignKey.table = props.editor.tables[0] ?? '';
    draft.value.foreignKeys.push(foreignKey);
}

function remove<T extends { key: string }>(list: T[], item: T): void {
    list.splice(list.indexOf(item), 1);
}

function toggleIndexColumn(
    index: IndexDraft,
    column: string,
    on: boolean,
): void {
    index.columns = on
        ? [...index.columns, column]
        : index.columns.filter((name) => name !== column);
}

/** Zielspalten: bei der Tabelle selbst die Spalten des Entwurfs, sonst aus dem Katalog. */
function targetColumns(foreignKey: ForeignKeyDraft): string[] {
    if (foreignKey.table === draft.value.name) {
        return finalColumns.value;
    }

    return props.targets?.[foreignKey.table] ?? [];
}

function chooseTarget(foreignKey: ForeignKeyDraft, table: string): void {
    foreignKey.table = table;
    const columns = targetColumns(foreignKey);
    foreignKey.references = [
        columns.includes('id') ? 'id' : (columns[0] ?? ''),
    ];
}

function setForeignColumn(foreignKey: ForeignKeyDraft, column: string): void {
    foreignKey.columns = column ? [column] : [];
}

function setReference(foreignKey: ForeignKeyDraft, column: string): void {
    foreignKey.references = column ? [column] : [];
}

const targetTables = computed(() => {
    const own = draft.value.name.trim();

    return own && !props.editor.tables.includes(own)
        ? [...props.editor.tables, own]
        : props.editor.tables;
});

const commentText = computed({
    get: () => draft.value.comment ?? '',
    set: (text: string) => {
        draft.value.comment = text === '' ? null : text;
    },
});

const error = (key: string): string | undefined => props.errors[key];
const fieldClass =
    'h-8 w-full min-w-0 rounded-md border bg-background px-2 font-mono text-[13px] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<template>
    <div class="flex flex-col gap-4">
        <datalist id="vorgabe-ausdruecke">
            <option value="CURRENT_TIMESTAMP" />
        </datalist>

        <!-- Spalten -->
        <section
            class="rounded-lg border bg-background"
            :aria-label="t('Spalten')"
        >
            <div class="flex flex-wrap items-center gap-2 border-b px-4 py-3">
                <h2 class="flex items-center gap-2 text-[15px] font-semibold">
                    <Table2 class="size-4" />{{ t('Spalten') }}
                    <span class="font-normal text-muted-foreground">{{
                        finalColumns.length
                    }}</span>
                </h2>
                <div class="ml-auto flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addColumn"
                    >
                        <Plus />{{ t('Spalte') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addIndex"
                    >
                        <Plus />{{ t('Index') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addForeignKey"
                    >
                        <Plus />{{ t('Fremdschlüssel') }}
                    </Button>
                </div>
            </div>

            <div
                class="hidden grid-cols-[minmax(7rem,1.2fr)_minmax(8rem,1.1fr)_minmax(5.5rem,0.8fr)_3rem_minmax(8rem,1fr)_minmax(6rem,1fr)_6.5rem] gap-x-3 border-b bg-muted px-4 py-2 text-[13px] font-medium text-text-secondary md:grid"
                aria-hidden="true"
            >
                <span>{{ t('Name') }}</span>
                <span>{{ t('Typ') }}</span>
                <span>{{ t('Länge') }}</span>
                <span>NULL</span>
                <span>{{ t('Vorgabe') }}</span>
                <span>{{
                    mode === 'create'
                        ? t('Schlüssel und Kommentar')
                        : editor.comments
                          ? t('Kommentar')
                          : ''
                }}</span>
                <span></span>
            </div>

            <ColumnRow
                v-for="(column, position) in draft.columns"
                :key="column.key"
                v-model:column="draft.columns[position]"
                :position="position"
                :types="editor.types"
                :mode="mode"
                :state="state(column)"
                :is-primary="primaryColumns.includes(column.original ?? '')"
                :comments="editor.comments"
                :errors="errors"
                @remove="removeColumn(position)"
                @toggle-drop="toggleDrop(column)"
            />
            <p
                v-if="!draft.columns.length"
                class="px-4 py-3 text-sm text-text-secondary"
            >
                {{ t('Noch keine Spalte.') }}
            </p>
            <InputError
                class="px-4 pb-3"
                :message="error('columns') ?? error('primary')"
            />
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Indizes -->
            <section
                class="rounded-lg border bg-background"
                :aria-label="t('Indizes')"
            >
                <h2
                    class="flex items-center gap-2 border-b px-4 py-3 text-[15px] font-semibold"
                >
                    <List class="size-4" />{{ t('Indizes') }}
                </h2>
                <ul class="divide-y">
                    <li
                        v-for="(index, position) in draft.indexes"
                        :key="index.key"
                        :class="[
                            'flex flex-col gap-2 px-4 py-2.5',
                            index.original === null && 'bg-cell-new',
                            index.drop && 'bg-cell-deleted',
                        ]"
                    >
                        <template v-if="index.original !== null">
                            <div class="flex items-center gap-2">
                                <KeyRound
                                    v-if="index.primary"
                                    class="size-3.5 shrink-0 text-primary"
                                />
                                <span
                                    :class="[
                                        'truncate font-mono text-sm font-medium',
                                        index.drop && 'line-through',
                                    ]"
                                    :title="index.name ?? ''"
                                    >{{ index.name }}</span
                                >
                                <span
                                    class="ml-auto shrink-0 rounded-full border px-2 py-px text-xs text-text-secondary"
                                    >{{
                                        index.primary
                                            ? t('Primärschlüssel')
                                            : index.unique
                                              ? t('Eindeutig')
                                              : t('Index')
                                    }}</span
                                >
                                <button
                                    v-if="!index.primary"
                                    type="button"
                                    class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-label="
                                        index.drop
                                            ? t('Löschen zurücknehmen')
                                            : t('Index löschen')
                                    "
                                    :title="
                                        index.drop
                                            ? t('Löschen zurücknehmen')
                                            : t('Index löschen')
                                    "
                                    @click="index.drop = !index.drop"
                                >
                                    <Undo2 v-if="index.drop" class="size-3.5" />
                                    <Trash2 v-else class="size-3.5" />
                                </button>
                            </div>
                            <span
                                class="font-mono text-[13px] text-text-secondary"
                                >{{ index.columns.join(', ') }}</span
                            >
                            <InputError
                                :message="error(`indexes.${position}.original`)"
                            />
                        </template>
                        <template v-else>
                            <div class="flex items-center gap-2">
                                <Input
                                    :model-value="index.name ?? ''"
                                    :placeholder="t('Name (sonst automatisch)')"
                                    :aria-label="t('Name des Index')"
                                    class="h-8 font-mono text-[13px]"
                                    @update:model-value="
                                        index.name = String($event) || null
                                    "
                                />
                                <label
                                    class="flex shrink-0 items-center gap-1.5 text-[13px]"
                                >
                                    <input
                                        v-model="index.unique"
                                        type="checkbox"
                                        class="size-4 accent-primary"
                                    />{{ t('eindeutig') }}
                                </label>
                                <button
                                    type="button"
                                    class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-label="t('Index entfernen')"
                                    :title="t('Index entfernen')"
                                    @click="remove(draft.indexes, index)"
                                >
                                    <X class="size-3.5" />
                                </button>
                            </div>
                            <fieldset class="flex flex-wrap gap-x-3 gap-y-1">
                                <legend class="sr-only">
                                    {{ t('Spalten des Index') }}
                                </legend>
                                <label
                                    v-for="name in finalColumns"
                                    :key="name"
                                    class="flex items-center gap-1.5 font-mono text-[13px]"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="index.columns.includes(name)"
                                        class="size-4 accent-primary"
                                        @change="
                                            toggleIndexColumn(
                                                index,
                                                name,
                                                (
                                                    $event.target as HTMLInputElement
                                                ).checked,
                                            )
                                        "
                                    />{{ name }}
                                </label>
                            </fieldset>
                            <InputError
                                :message="
                                    error(`indexes.${position}.columns`) ??
                                    error(`indexes.${position}.name`)
                                "
                            />
                        </template>
                    </li>
                    <li
                        v-if="!draft.indexes.length"
                        class="px-4 py-3 text-sm text-text-secondary"
                    >
                        {{ t('Keine Indizes.') }}
                    </li>
                </ul>
                <InputError class="px-4 pb-3" :message="error('indexes')" />
            </section>

            <!-- Fremdschlüssel -->
            <section
                class="rounded-lg border bg-background"
                :aria-label="t('Fremdschlüssel')"
            >
                <h2
                    class="flex items-center gap-2 border-b px-4 py-3 text-[15px] font-semibold"
                >
                    <Link2 class="size-4" />{{ t('Fremdschlüssel') }}
                </h2>
                <ul class="divide-y">
                    <li
                        v-for="(foreignKey, position) in draft.foreignKeys"
                        :key="foreignKey.key"
                        :class="[
                            'flex flex-col gap-2 px-4 py-2.5 text-[13px]',
                            foreignKey.original === null && 'bg-cell-new',
                            foreignKey.drop && 'bg-cell-deleted',
                        ]"
                    >
                        <template v-if="foreignKey.original !== null">
                            <div class="flex items-center gap-2">
                                <span
                                    :class="[
                                        'min-w-0 flex-1 truncate font-mono',
                                        foreignKey.drop && 'line-through',
                                    ]"
                                    >{{ foreignKey.columns.join(', ') }}
                                    <ArrowRight class="inline size-3.5" />
                                    {{ foreignKey.table }}.{{
                                        foreignKey.references.join(', ')
                                    }}</span
                                >
                                <button
                                    type="button"
                                    class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-label="
                                        foreignKey.drop
                                            ? t('Löschen zurücknehmen')
                                            : t('Fremdschlüssel löschen')
                                    "
                                    :title="
                                        foreignKey.drop
                                            ? t('Löschen zurücknehmen')
                                            : t('Fremdschlüssel löschen')
                                    "
                                    @click="foreignKey.drop = !foreignKey.drop"
                                >
                                    <Undo2
                                        v-if="foreignKey.drop"
                                        class="size-3.5"
                                    />
                                    <Trash2 v-else class="size-3.5" />
                                </button>
                            </div>
                            <span class="text-text-secondary">{{
                                t(
                                    'beim Ändern :update · beim Löschen :delete',
                                    {
                                        update: foreignKey.onUpdate.toUpperCase(),
                                        delete: foreignKey.onDelete.toUpperCase(),
                                    },
                                )
                            }}</span>
                        </template>
                        <template v-else>
                            <div
                                class="grid grid-cols-[1fr_auto_1fr_auto] items-center gap-2"
                            >
                                <select
                                    :value="foreignKey.columns[0] ?? ''"
                                    :class="fieldClass"
                                    :aria-label="t('Spalte dieser Tabelle')"
                                    @change="
                                        setForeignColumn(
                                            foreignKey,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option value="">
                                        {{ t('Spalte …') }}
                                    </option>
                                    <option
                                        v-for="name in finalColumns"
                                        :key="name"
                                        :value="name"
                                    >
                                        {{ name }}
                                    </option>
                                </select>
                                <ArrowRight
                                    class="size-3.5 text-muted-foreground"
                                />
                                <select
                                    :value="foreignKey.table"
                                    :class="fieldClass"
                                    :aria-label="t('Zieltabelle')"
                                    @change="
                                        chooseTarget(
                                            foreignKey,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option value="">
                                        {{ t('Tabelle …') }}
                                    </option>
                                    <option
                                        v-for="table in targetTables"
                                        :key="table"
                                        :value="table"
                                    >
                                        {{ table }}
                                    </option>
                                </select>
                                <button
                                    type="button"
                                    class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-label="t('Fremdschlüssel entfernen')"
                                    :title="t('Fremdschlüssel entfernen')"
                                    @click="
                                        remove(draft.foreignKeys, foreignKey)
                                    "
                                >
                                    <X class="size-3.5" />
                                </button>
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <label class="flex flex-col gap-1">
                                    <span class="text-xs text-text-secondary">{{
                                        t('Zielspalte')
                                    }}</span>
                                    <select
                                        :value="foreignKey.references[0] ?? ''"
                                        :class="fieldClass"
                                        @change="
                                            setReference(
                                                foreignKey,
                                                (
                                                    $event.target as HTMLSelectElement
                                                ).value,
                                            )
                                        "
                                    >
                                        <option
                                            v-for="name in targetColumns(
                                                foreignKey,
                                            )"
                                            :key="name"
                                            :value="name"
                                        >
                                            {{ name }}
                                        </option>
                                    </select>
                                </label>
                                <label class="flex flex-col gap-1">
                                    <span class="text-xs text-text-secondary"
                                        >ON DELETE</span
                                    >
                                    <select
                                        v-model="foreignKey.onDelete"
                                        :class="fieldClass"
                                    >
                                        <option
                                            v-for="rule in editor.rules"
                                            :key="rule"
                                            :value="rule"
                                        >
                                            {{ rule.toUpperCase() }}
                                        </option>
                                    </select>
                                </label>
                                <label class="flex flex-col gap-1">
                                    <span class="text-xs text-text-secondary"
                                        >ON UPDATE</span
                                    >
                                    <select
                                        v-model="foreignKey.onUpdate"
                                        :class="fieldClass"
                                    >
                                        <option
                                            v-for="rule in editor.rules"
                                            :key="rule"
                                            :value="rule"
                                        >
                                            {{ rule.toUpperCase() }}
                                        </option>
                                    </select>
                                </label>
                            </div>
                            <InputError
                                :message="
                                    error(`foreignKeys.${position}.columns`) ??
                                    error(`foreignKeys.${position}.table`) ??
                                    error(
                                        `foreignKeys.${position}.references`,
                                    ) ??
                                    error(`foreignKeys.${position}.name`)
                                "
                            />
                        </template>
                    </li>
                    <li
                        v-if="!draft.foreignKeys.length"
                        class="px-4 py-3 text-sm text-text-secondary"
                    >
                        {{ t('Diese Tabelle verweist auf keine andere.') }}
                    </li>
                </ul>
                <InputError class="px-4 pb-3" :message="error('foreignKeys')" />
            </section>
        </div>

        <div v-if="editor.comments" class="flex flex-col gap-1.5">
            <label for="tabellen-kommentar" class="text-[13px] font-medium">{{
                t('Kommentar zur Tabelle')
            }}</label>
            <Input
                id="tabellen-kommentar"
                v-model="commentText"
                autocomplete="off"
                :aria-invalid="error('comment') ? true : undefined"
            />
            <InputError :message="error('comment')" />
        </div>
    </div>
</template>
