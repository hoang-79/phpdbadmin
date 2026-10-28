<script setup lang="ts">
/**
 * Feasibility F-4: SQL-Editor mit CodeMirror 6 — Hervorhebung und Vervollständigung je Dialekt,
 * Schema mit 1'002 Tabellen. Nur lokal; misst selbst und zeigt die Zeiten als Text.
 */
import {
    MariaSQL,
    MySQL,
    PostgreSQL,
    SQLite,
    sql,
    type SQLDialect,
} from '@codemirror/lang-sql';
import { Compartment, EditorState } from '@codemirror/state';
import { Head } from '@inertiajs/vue3';
import { basicSetup, EditorView } from 'codemirror';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{ schema: Record<string, string[]> }>();

const dialects: Record<string, SQLDialect> = {
    MySQL,
    MariaDB: MariaSQL,
    PostgreSQL,
    SQLite,
};
const dialect = ref('MySQL');
const host = ref<HTMLElement | null>(null);
const mountMs = ref<number | null>(null);
const switchMs = ref<number | null>(null);
const language = new Compartment();
let view: EditorView | null = null;

function languageFor(name: string) {
    return sql({
        dialect: dialects[name],
        schema: props.schema,
        upperCaseKeywords: true,
    });
}

onMounted(() => {
    if (host.value === null) {
        return;
    }

    const start = performance.now();
    view = new EditorView({
        parent: host.value,
        state: EditorState.create({
            doc: "SELECT o.id, c.name, o.total\nFROM orders o\nJOIN customers c ON c.id = o.customer_id\nWHERE o.status = 'offen'\nORDER BY o.created_at DESC\nLIMIT 100;\n",
            extensions: [basicSetup, language.of(languageFor(dialect.value))],
        }),
    });
    mountMs.value = Math.round(performance.now() - start);
});

watch(dialect, (name) => {
    const start = performance.now();
    view?.dispatch({ effects: language.reconfigure(languageFor(name)) });
    switchMs.value = Math.round(performance.now() - start);
});

onBeforeUnmount(() => view?.destroy());
</script>

<template>
    <Head title="Probe SQL-Editor" />
    <main class="mx-auto max-w-4xl space-y-4 p-6">
        <h1 class="text-xl font-semibold">F-4 Probe: SQL-Editor</h1>
        <div class="flex flex-wrap items-center gap-2" aria-label="Dialekt">
            <button
                v-for="name in Object.keys(dialects)"
                :key="name"
                type="button"
                class="rounded-md border px-3 py-1 text-sm"
                :class="
                    dialect === name
                        ? 'border-slate-800 bg-slate-800 text-white'
                        : 'border-slate-300'
                "
                @click="dialect = name"
            >
                {{ name }}
            </button>
        </div>
        <div
            ref="host"
            class="rounded-md border border-slate-300 text-sm"
            data-probe="editor"
        />
        <dl class="grid grid-cols-2 gap-x-4 text-sm" data-probe="messung">
            <dt>Dialekt</dt>
            <dd>{{ dialect }}</dd>
            <dt>Tabellen im Schema</dt>
            <dd>{{ Object.keys(schema).length }}</dd>
            <dt>Editor aufgebaut in</dt>
            <dd>{{ mountMs }} ms</dd>
            <dt>Dialekt gewechselt in</dt>
            <dd>{{ switchMs ?? '—' }} ms</dd>
        </dl>
    </main>
</template>
