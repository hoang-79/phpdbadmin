// F-4 Feasibility: Vervollständigung von @codemirror/lang-sql ohne Browser prüfen. Aufruf: node tests/Feature/Feasibility/editor-completion-probe.mjs
import { EditorState } from '@codemirror/state';
import { CompletionContext } from '@codemirror/autocomplete';
import {
    MySQL,
    MariaSQL,
    PostgreSQL,
    SQLite,
    schemaCompletionSource,
    keywordCompletionSource,
    sql,
} from '@codemirror/lang-sql';

const schema = {
    customers: ['id', 'name', 'email', 'city'],
    orders: ['id', 'customer_id', 'status', 'total', 'note', 'created_at'],
};
for (let i = 1; i <= 1000; i++)
    schema[`probe_t${String(i).padStart(4, '0')}`] = [
        'id',
        'name',
        'created_at',
    ];

const dialects = { MySQL, MariaDB: MariaSQL, PostgreSQL, SQLite };
const cases = [
    ['Tabelle', 'SELECT * FROM ord'],
    ['Spalte über Alias', 'SELECT o. FROM orders o', 9],
    ['Spalte nach Tabelle.', 'SELECT customers. FROM customers', 17],
];
const result = {};
for (const [name, dialect] of Object.entries(dialects)) {
    const ext = sql({ dialect, schema });
    const source = schemaCompletionSource({ dialect, schema });
    const keywords = keywordCompletionSource(dialect, true);
    result[name] = {};
    for (const [label, doc, pos] of cases) {
        const state = EditorState.create({ doc, extensions: [ext] });
        const t0 = performance.now();
        const res = await source(
            new CompletionContext(state, pos ?? doc.length, true),
        );
        const ms = Math.round((performance.now() - t0) * 10) / 10;
        const labels = (res?.options ?? []).map((o) => o.label);
        result[name][label] = {
            treffer: labels
                .filter((l) => /^(orders|id|customer_id|name|email)$/.test(l))
                .slice(0, 6),
            anzahl: labels.length,
            ms,
        };
    }
    const kwState = EditorState.create({
        doc: 'SELECT * FROM t WHERE name IL',
        extensions: [ext],
    });
    const kw = await keywords(
        new CompletionContext(kwState, kwState.doc.length, true),
    );
    result[name]['Schlüsselwort ILIKE'] = (kw?.options ?? []).some(
        (o) => o.label.toUpperCase() === 'ILIKE',
    );
}
console.log(JSON.stringify(result, null, 1));
