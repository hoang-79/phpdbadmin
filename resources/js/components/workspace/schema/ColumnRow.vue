<script setup lang="ts">
import { KeyRound, Pencil, Plus, Trash2, Undo2, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import type { ColumnDraft, ColumnTypeOption, DefaultKind } from '@/types';

/**
 * Eine Spalte im Entwurf (S-02, S-03): Name, Typ aus der Liste des Treibers oder eigener Typ, Länge bzw.
 * Genauigkeit, NULL, Vorgabe, beim Anlegen Primärschlüssel und Auto-Increment, Kommentar. Am Desktop eine Zeile im
 * Raster, auf dem Telefon eine Karte mit Beschriftungen. Neue Spalten sind grün, geänderte blau, gelöschte rot und
 * durchgestrichen (Wireframe 9).
 */
const props = defineProps<{
    position: number;
    types: ColumnTypeOption[];
    mode: 'create' | 'alter';
    state: 'new' | 'renamed' | 'changed' | 'dropped' | null;
    isPrimary: boolean;
    comments: boolean;
    errors: Record<string, string | undefined>;
}>();

const column = defineModel<ColumnDraft>('column', { required: true });
const emit = defineEmits<{ remove: []; toggleDrop: [] }>();

const option = computed(() =>
    props.types.find((type) => type.key === column.value.type),
);
const dropped = computed(() => props.state === 'dropped');
const id = (field: string): string => `spalte-${props.position}-${field}`;
const error = (field: string): string | undefined =>
    props.errors[`columns.${props.position}.${field}`];

const valuesText = computed({
    get: () => column.value.values.join(', '),
    set: (text: string) => {
        column.value.values = text
            .split(',')
            .map((value) => value.trim())
            .filter((value) => value !== '');
    },
});

function chooseType(key: string): void {
    const next = props.types.find((type) => type.key === key);
    column.value.type = key;
    column.value.length =
        next?.params === 'length' ? (column.value.length ?? 255) : null;
    column.value.precision =
        next?.params === 'precision' ? (column.value.precision ?? 10) : null;
    column.value.scale =
        next?.params === 'precision' ? (column.value.scale ?? 2) : null;

    if (next?.params !== 'values') {
        column.value.values = [];
    }

    if (key === 'custom') {
        column.value.custom ??= '';
    }

    if (!next?.autoIncrement) {
        column.value.autoIncrement = false;
    }
}

function chooseDefault(kind: DefaultKind): void {
    column.value.default = {
        kind,
        value:
            kind === 'expression'
                ? (column.value.default.value ?? 'CURRENT_TIMESTAMP')
                : kind === 'value'
                  ? (column.value.default.value ?? '')
                  : null,
    };
}

function toggleAutoIncrement(on: boolean): void {
    column.value.autoIncrement = on;

    if (on) {
        column.value.nullable = false;
        column.value.primary = true;
        column.value.default = { kind: 'none', value: null };
    }
}

function number(value: string): number | null {
    return value === '' ? null : Number(value);
}

const stateLabel = computed(
    () =>
        ({
            new: t('neu'),
            renamed: t('umbenannt'),
            changed: t('geändert'),
            dropped: t('wird gelöscht'),
        })[props.state ?? 'new'],
);
const fieldClass =
    'h-8 w-full min-w-0 rounded-md border bg-background px-2 font-mono text-[13px] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-60';
</script>

<template>
    <div
        :class="[
            'grid grid-cols-2 gap-x-3 gap-y-2 border-b px-4 py-3 last:border-b-0 md:grid-cols-[minmax(7rem,1.2fr)_minmax(8rem,1.1fr)_minmax(5.5rem,0.8fr)_3rem_minmax(8rem,1fr)_minmax(6rem,1fr)_6.5rem] md:items-start md:gap-y-1 md:py-2',
            state === 'new' && 'bg-cell-new',
            (state === 'renamed' || state === 'changed') && 'bg-cell-changed',
            dropped && 'bg-cell-deleted',
        ]"
    >
        <!-- Name -->
        <div class="col-span-2 flex flex-col gap-1 md:col-span-1">
            <label
                :for="id('name')"
                class="text-xs text-text-secondary md:sr-only"
                >{{ t('Name') }}</label
            >
            <div class="flex items-center gap-1.5">
                <KeyRound
                    v-if="isPrimary || column.primary"
                    class="size-3.5 shrink-0 text-primary"
                    :aria-label="t('Primärschlüssel')"
                />
                <Input
                    :id="id('name')"
                    v-model="column.name"
                    :disabled="dropped"
                    autocomplete="off"
                    spellcheck="false"
                    :class="[
                        'h-8 font-mono text-[13px]',
                        dropped && 'line-through',
                    ]"
                    :aria-invalid="error('name') ? true : undefined"
                />
            </div>
            <span
                v-if="column.original && column.original !== column.name"
                class="text-xs text-text-secondary"
                >{{ t('war :name', { name: column.original }) }}</span
            >
            <InputError :message="error('name') ?? error('original')" />
        </div>

        <!-- Typ -->
        <div class="flex flex-col gap-1">
            <label
                :for="id('type')"
                class="text-xs text-text-secondary md:sr-only"
                >{{ t('Typ') }}</label
            >
            <select
                :id="id('type')"
                :value="column.type"
                :disabled="dropped"
                :class="fieldClass"
                @change="chooseType(($event.target as HTMLSelectElement).value)"
            >
                <option v-for="type in types" :key="type.key" :value="type.key">
                    {{ type.label }}
                </option>
            </select>
            <Input
                v-if="column.type === 'custom'"
                :id="id('custom')"
                :model-value="column.custom ?? ''"
                :disabled="dropped"
                :placeholder="t('z. B. numeric(12,4)')"
                :aria-label="t('Eigener Typ')"
                autocomplete="off"
                spellcheck="false"
                class="h-8 font-mono text-[13px]"
                :aria-invalid="error('custom') ? true : undefined"
                @update:model-value="column.custom = String($event)"
            />
            <InputError :message="error('type') ?? error('custom')" />
        </div>

        <!-- Länge, Genauigkeit oder Auswahl -->
        <div class="flex flex-col gap-1">
            <span class="text-xs text-text-secondary md:sr-only">{{
                t('Länge')
            }}</span>
            <Input
                v-if="option?.params === 'length'"
                :id="id('length')"
                type="number"
                min="1"
                :model-value="column.length ?? ''"
                :disabled="dropped"
                :aria-label="t('Länge')"
                class="h-8 font-mono text-[13px]"
                @update:model-value="column.length = number(String($event))"
            />
            <div
                v-else-if="option?.params === 'precision'"
                class="flex items-center gap-1"
            >
                <Input
                    type="number"
                    min="1"
                    :model-value="column.precision ?? ''"
                    :disabled="dropped"
                    :aria-label="t('Genauigkeit')"
                    class="h-8 px-1.5 font-mono text-[13px]"
                    @update:model-value="
                        column.precision = number(String($event))
                    "
                />
                <span class="text-muted-foreground">,</span>
                <Input
                    type="number"
                    min="0"
                    :model-value="column.scale ?? ''"
                    :disabled="dropped"
                    :aria-label="t('Nachkommastellen')"
                    class="h-8 px-1.5 font-mono text-[13px]"
                    @update:model-value="column.scale = number(String($event))"
                />
            </div>
            <Input
                v-else-if="option?.params === 'values'"
                v-model="valuesText"
                :disabled="dropped"
                :placeholder="t('offen, bezahlt')"
                :aria-label="t('Werte, durch Komma getrennt')"
                class="h-8 font-mono text-[13px]"
            />
            <span
                v-else
                class="hidden h-8 items-center text-muted-foreground md:flex"
                >—</span
            >
            <InputError
                :message="
                    error('length') ?? error('precision') ?? error('values')
                "
            />
        </div>

        <!-- NULL -->
        <div class="flex flex-col gap-1">
            <label
                :for="id('nullable')"
                class="text-xs text-text-secondary md:sr-only"
                >NULL</label
            >
            <span class="flex h-8 items-center">
                <input
                    :id="id('nullable')"
                    v-model="column.nullable"
                    type="checkbox"
                    :disabled="dropped || column.autoIncrement"
                    class="size-4 accent-primary"
                    :aria-label="t('NULL erlaubt')"
                />
            </span>
        </div>

        <!-- Vorgabe -->
        <div class="flex flex-col gap-1">
            <label
                :for="id('default')"
                class="text-xs text-text-secondary md:sr-only"
                >{{ t('Vorgabe') }}</label
            >
            <select
                :id="id('default')"
                :value="column.default.kind"
                :disabled="dropped || column.autoIncrement"
                :class="fieldClass"
                @change="
                    chooseDefault(
                        ($event.target as HTMLSelectElement)
                            .value as DefaultKind,
                    )
                "
            >
                <option value="none">{{ t('keine') }}</option>
                <option v-if="column.nullable" value="null">NULL</option>
                <option value="value">{{ t('Wert') }}</option>
                <option value="expression">{{ t('Ausdruck') }}</option>
            </select>
            <Input
                v-if="
                    column.default.kind === 'value' ||
                    column.default.kind === 'expression'
                "
                :model-value="column.default.value ?? ''"
                :disabled="dropped"
                :list="
                    column.default.kind === 'expression'
                        ? 'vorgabe-ausdruecke'
                        : undefined
                "
                :aria-label="
                    column.default.kind === 'value'
                        ? t('Wert der Vorgabe')
                        : t('Ausdruck der Vorgabe')
                "
                autocomplete="off"
                spellcheck="false"
                class="h-8 font-mono text-[13px]"
                @update:model-value="column.default.value = String($event)"
            />
            <InputError :message="error('default')" />
        </div>

        <!-- Schlüssel, Auto-Increment, Kommentar -->
        <div class="col-span-2 flex flex-col gap-1 md:col-span-1">
            <template v-if="mode === 'create'">
                <label class="flex items-center gap-1.5 text-[13px]">
                    <input
                        v-model="column.primary"
                        type="checkbox"
                        :disabled="column.autoIncrement"
                        class="size-4 accent-primary"
                    />{{ t('Primärschlüssel') }}
                </label>
                <label
                    v-if="option?.autoIncrement"
                    class="flex items-center gap-1.5 text-[13px]"
                >
                    <input
                        type="checkbox"
                        :checked="column.autoIncrement"
                        class="size-4 accent-primary"
                        @change="
                            toggleAutoIncrement(
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />Auto-Increment
                </label>
                <InputError :message="error('autoIncrement')" />
            </template>
            <span
                v-else-if="column.autoIncrement"
                class="flex h-8 items-center font-mono text-[13px] text-text-secondary"
                >auto_increment</span
            >
            <Input
                v-if="comments"
                :id="id('comment')"
                :model-value="column.comment ?? ''"
                :disabled="dropped"
                :placeholder="t('Kommentar')"
                :aria-label="t('Kommentar')"
                class="h-8 text-[13px]"
                @update:model-value="column.comment = String($event) || null"
            />
            <InputError :message="error('comment')" />
        </div>

        <!-- Zustand und Aktion -->
        <div
            class="col-span-2 flex items-center justify-end gap-1.5 md:col-span-1 md:h-8"
        >
            <span
                v-if="state"
                :class="[
                    'inline-flex items-center gap-1 rounded-full border bg-background px-2 py-px text-xs whitespace-nowrap',
                    state === 'new' && 'border-success text-success',
                    (state === 'renamed' || state === 'changed') &&
                        'border-primary text-primary',
                    state === 'dropped' &&
                        'border-destructive text-destructive',
                ]"
            >
                <Plus v-if="state === 'new'" class="size-3" />
                <Pencil v-else-if="state !== 'dropped'" class="size-3" />
                <Trash2 v-else class="size-3" />{{ stateLabel }}
            </span>
            <button
                v-if="column.original === null"
                type="button"
                class="inline-flex size-8 shrink-0 items-center justify-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="t('Spalte entfernen')"
                :title="t('Spalte entfernen')"
                @click="emit('remove')"
            >
                <X class="size-4" />
            </button>
            <button
                v-else-if="!isPrimary"
                type="button"
                class="inline-flex size-8 shrink-0 items-center justify-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                :aria-label="
                    dropped ? t('Löschen zurücknehmen') : t('Spalte löschen')
                "
                :title="
                    dropped ? t('Löschen zurücknehmen') : t('Spalte löschen')
                "
                @click="emit('toggleDrop')"
            >
                <Undo2 v-if="dropped" class="size-4" />
                <Trash2 v-else class="size-4" />
            </button>
        </div>
    </div>
</template>
