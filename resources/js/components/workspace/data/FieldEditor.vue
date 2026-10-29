<script setup lang="ts">
import { CircleCheck, CircleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { EditValue } from '@/composables/useRowChanges';
import { t } from '@/lib/i18n';
import type { Cell, GridColumn } from '@/types';

/**
 * Ein Feld im Zeilen-Detail bearbeiten (D-05, Wireframe 7): NULL ist etwas anderes als ein leerer Text, JSON wird
 * geprüft und formatiert, enum/set als Auswahl, Wahrheitswerte als Haken, lange Texte im Textfeld.
 */
const props = defineProps<{
    column: GridColumn;
    value: Cell | EditValue;
}>();

const emit = defineEmits<{ update: [value: EditValue] }>();

function asText(value: Cell | EditValue): string {
    if (value === null || typeof value === 'boolean') {
        return '';
    }

    if (typeof value === 'object') {
        return 'text' in value ? value.text : '';
    }

    if (props.column.kind === 'json') {
        try {
            return JSON.stringify(JSON.parse(String(value)), null, 2);
        } catch {
            return String(value);
        }
    }

    return String(value);
}

const draft = ref(asText(props.value));
const isNull = computed(() => props.value === null);

watch(
    () => props.value,
    (value) => {
        if (
            value !== null &&
            asText(value) !== draft.value &&
            document.activeElement?.tagName !== 'TEXTAREA'
        ) {
            draft.value = asText(value);
        }
    },
);

const options = computed(() => {
    const match = props.column.type.match(/^(enum|set)\((.*)\)$/i);

    return match
        ? [...match[2].matchAll(/'((?:[^']|'')*)'/g)].map((option) =>
              option[1].replaceAll("''", "'"),
          )
        : null;
});

const jsonValid = computed(() => {
    if (props.column.kind !== 'json' || isNull.value) {
        return null;
    }

    try {
        JSON.parse(draft.value);

        return true;
    } catch {
        return false;
    }
});

const multiline = computed(
    () =>
        props.column.kind === 'json' ||
        draft.value.includes('\n') ||
        draft.value.length > 80,
);

function update(value: string): void {
    draft.value = value;
    emit('update', value);
}

function toggleNull(checked: boolean): void {
    emit(
        'update',
        checked ? null : props.column.kind === 'boolean' ? false : draft.value,
    );
}

function formatJson(): void {
    try {
        update(JSON.stringify(JSON.parse(draft.value), null, 2));
    } catch {
        // Ungültiges JSON bleibt, wie es ist.
    }
}
</script>

<template>
    <div class="flex flex-col gap-1">
        <div class="flex items-start gap-2">
            <label
                v-if="column.kind === 'boolean'"
                class="flex h-9 items-center gap-2 text-sm"
                :class="isNull && 'opacity-50'"
            >
                <input
                    type="checkbox"
                    class="size-4 accent-[var(--primary)]"
                    :checked="value === true"
                    :disabled="isNull"
                    @change="
                        emit(
                            'update',
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
                {{ value === true ? 'true' : 'false' }}
            </label>
            <select
                v-else-if="options"
                :value="isNull ? '' : draft"
                :disabled="isNull"
                class="h-9 min-w-0 flex-1 rounded-md border bg-background px-2 text-sm disabled:opacity-50"
                @change="update(($event.target as HTMLSelectElement).value)"
            >
                <option v-for="option in options" :key="option" :value="option">
                    {{ option }}
                </option>
            </select>
            <textarea
                v-else-if="multiline"
                :value="isNull ? '' : draft"
                :disabled="isNull"
                :placeholder="isNull ? 'NULL' : ''"
                rows="5"
                class="min-h-20 min-w-0 flex-1 rounded-md border bg-background px-2.5 py-1.5 font-mono text-sm disabled:italic disabled:opacity-60"
                @input="update(($event.target as HTMLTextAreaElement).value)"
            />
            <input
                v-else
                type="text"
                :value="isNull ? '' : draft"
                :disabled="isNull"
                :placeholder="isNull ? 'NULL' : ''"
                :class="[
                    'h-9 min-w-0 flex-1 rounded-md border bg-background px-2.5 text-sm disabled:italic disabled:opacity-60',
                    (column.kind === 'number' || column.kind === 'datetime') &&
                        'font-mono',
                ]"
                @input="update(($event.target as HTMLInputElement).value)"
            />
            <label
                v-if="column.nullable"
                class="flex h-9 shrink-0 items-center gap-1.5 rounded-md border px-2 text-[12px]"
            >
                <input
                    type="checkbox"
                    class="size-3.5 accent-[var(--primary)]"
                    :checked="isNull"
                    @change="
                        toggleNull(($event.target as HTMLInputElement).checked)
                    "
                />
                NULL
            </label>
        </div>
        <span
            v-if="isNull && column.nullable"
            class="text-[12px] text-text-secondary"
            >{{
                t(
                    'NULL ist etwas anderes als ein leerer Text. Häkchen weg, um Text einzugeben.',
                )
            }}</span
        >
        <span
            v-if="jsonValid !== null"
            class="flex items-center gap-1 text-[12px]"
        >
            <template v-if="jsonValid"
                ><CircleCheck class="size-3.5 text-success" />{{
                    t('gültiges JSON')
                }}
                ·
                <button
                    type="button"
                    class="text-primary underline"
                    @click="formatJson"
                >
                    {{ t('formatieren') }}
                </button></template
            >
            <template v-else
                ><CircleAlert class="size-3.5 text-destructive" />{{
                    t('kein gültiges JSON')
                }}</template
            >
        </span>
    </div>
</template>
