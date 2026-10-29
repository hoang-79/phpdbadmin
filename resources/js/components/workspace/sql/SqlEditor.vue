<script setup lang="ts">
import { MariaSQL, MySQL, PostgreSQL, SQLite, sql } from '@codemirror/lang-sql';
import type { SQLDialect } from '@codemirror/lang-sql';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { Compartment, EditorState, Prec } from '@codemirror/state';
import { EditorView, keymap, placeholder } from '@codemirror/view';
import { tags } from '@lezer/highlight';
import { basicSetup } from 'codemirror';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { t } from '@/lib/i18n';
import type { DatabaseDriver } from '@/types';

/**
 * SQL-Editor (Q-01, ADR-005): CodeMirror 6 mit dem Dialekt des Treibers, Vervollständigung aus dem Schema und Farben
 * aus dem Stylesheet, damit hell und dunkel lesbar bleiben. ⌘↵ führt die Anweisung an der Schreibmarke (oder die
 * Auswahl) aus, ⇧⌘↵ alles.
 */
const props = defineProps<{
    modelValue: string;
    driver: DatabaseDriver;
    schema: Record<string, string[]> | null;
    defaultSchema: string | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
    run: [mode: 'statement' | 'all'];
}>();

const host = ref<HTMLElement | null>(null);
const language = new Compartment();
let view: EditorView | null = null;

const dialects: Record<DatabaseDriver, SQLDialect> = {
    mysql: MySQL,
    mariadb: MariaSQL,
    pgsql: PostgreSQL,
    sqlite: SQLite,
};

const highlight = HighlightStyle.define([
    { tag: tags.keyword, color: 'var(--primary)', fontWeight: '600' },
    { tag: [tags.string, tags.special(tags.string)], color: 'var(--success)' },
    { tag: [tags.number, tags.bool, tags.null], color: 'var(--warning)' },
    {
        tag: [tags.lineComment, tags.blockComment],
        color: 'var(--muted-foreground)',
        fontStyle: 'italic',
    },
    {
        tag: [tags.typeName, tags.standard(tags.name)],
        color: 'var(--text-secondary)',
    },
    { tag: tags.operator, color: 'var(--text-secondary)' },
]);

const theme = EditorView.theme({
    '&': {
        height: '100%',
        fontSize: '14px',
        backgroundColor: 'var(--background)',
        color: 'var(--foreground)',
    },
    '.cm-scroller': { fontFamily: 'var(--font-mono)', lineHeight: '1.55' },
    '.cm-content': { caretColor: 'var(--foreground)' },
    '.cm-cursor': { borderLeftColor: 'var(--foreground)' },
    '.cm-gutters': {
        backgroundColor: 'var(--muted)',
        color: 'var(--muted-foreground)',
        border: 'none',
    },
    '.cm-activeLine': {
        backgroundColor: 'color-mix(in oklab, var(--accent) 55%, transparent)',
    },
    '.cm-activeLineGutter': { backgroundColor: 'var(--accent)' },
    '&.cm-focused': { outline: 'none' },
    '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, ::selection':
        {
            backgroundColor:
                'color-mix(in oklab, var(--primary) 28%, transparent) !important',
        },
    '.cm-tooltip': {
        backgroundColor: 'var(--popover)',
        color: 'var(--popover-foreground)',
        border: '1px solid var(--border)',
        borderRadius: '6px',
    },
    '.cm-tooltip-autocomplete > ul > li[aria-selected]': {
        backgroundColor: 'var(--accent)',
        color: 'var(--foreground)',
    },
    '.cm-placeholder': { color: 'var(--muted-foreground)' },
});

function languageFor() {
    return sql({
        dialect: dialects[props.driver],
        schema: props.schema ?? undefined,
        defaultSchema: props.defaultSchema ?? undefined,
        upperCaseKeywords: true,
    });
}

/** Byte-Position der Schreibmarke (der Server zerlegt in UTF-8). */
function cursorByte(): number {
    if (!view) {
        return 0;
    }

    const position = view.state.selection.main.head;

    return new TextEncoder().encode(view.state.doc.sliceString(0, position))
        .length;
}

function selection(): string {
    if (!view) {
        return '';
    }

    const range = view.state.selection.main;

    return view.state.doc.sliceString(range.from, range.to);
}

function focus(): void {
    view?.focus();
}

defineExpose({ cursorByte, selection, focus });

onMounted(() => {
    if (!host.value) {
        return;
    }

    view = new EditorView({
        parent: host.value,
        state: EditorState.create({
            doc: props.modelValue,
            extensions: [
                basicSetup,
                theme,
                Prec.high(syntaxHighlighting(highlight)),
                language.of(languageFor()),
                placeholder(
                    t(
                        'SQL eingeben — ⌘↵ führt die Anweisung an der Schreibmarke aus',
                    ),
                ),
                EditorView.contentAttributes.of({
                    'aria-label': t('SQL-Editor'),
                }),
                Prec.highest(
                    keymap.of([
                        {
                            key: 'Mod-Enter',
                            run: () => {
                                emit('run', 'statement');

                                return true;
                            },
                        },
                        {
                            key: 'Shift-Mod-Enter',
                            run: () => {
                                emit('run', 'all');

                                return true;
                            },
                        },
                    ]),
                ),
                EditorView.updateListener.of((update) => {
                    if (update.docChanged) {
                        emit('update:modelValue', update.state.doc.toString());
                    }
                }),
            ],
        }),
    });
});

watch(
    () => props.modelValue,
    (value) => {
        if (view && value !== view.state.doc.toString()) {
            view.dispatch({
                changes: { from: 0, to: view.state.doc.length, insert: value },
            });
        }
    },
);

watch(
    () => [props.schema, props.driver, props.defaultSchema],
    () => view?.dispatch({ effects: language.reconfigure(languageFor()) }),
);

onBeforeUnmount(() => view?.destroy());
</script>

<template>
    <div ref="host" class="h-full min-h-0 overflow-hidden" />
</template>
