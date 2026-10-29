/**
 * Formatieren im SQL-Editor (Q-01): Schlüsselwörter gross, Hauptklauseln auf eigene Zeilen, AND/OR eingerückt.
 * Zeichenketten, Bezeichner, Kommentare und Dollar-Blöcke bleiben unberührt. Bewusst schlicht — keine neue
 * Abhängigkeit; was der Formatierer nicht kennt, lässt er stehen.
 */
const KEYWORDS = new Set(
    `select from where and or not in is null like ilike between exists as on join left right inner outer full cross
    group by order having limit offset union all distinct insert into values update set delete create table view
    index drop alter add column primary key foreign references default unique check case when then else end with
    returning asc desc nulls first last true false count sum avg min max coalesce cast interval show explain analyze
    begin commit rollback truncate if replace using natural lateral over partition window filter`
        .split(/\s+/)
        .filter(Boolean),
);

const BREAK_BEFORE = [
    'select',
    'from',
    'where',
    'group by',
    'order by',
    'having',
    'limit',
    'offset',
    'union all',
    'union',
    'insert into',
    'values',
    'update',
    'set',
    'delete from',
    'returning',
    'left outer join',
    'right outer join',
    'full outer join',
    'left join',
    'right join',
    'inner join',
    'cross join',
    'full join',
    'join',
];

type Token = {
    type: 'word' | 'text' | 'space' | 'comment' | 'other';
    value: string;
};

function tokenize(sql: string): Token[] {
    const pattern =
        /(--[^\n]*|#[^\n]*|\/\*[\s\S]*?\*\/)|('(?:[^'\\]|\\.|'')*'|"(?:[^"]|"")*"|`(?:[^`]|``)*`|\$([A-Za-z_]\w*)?\$[\s\S]*?\$\3\$)|([A-Za-z_][A-Za-z0-9_$]*)|(\s+)|([\s\S])/g;
    const tokens: Token[] = [];

    for (const match of sql.matchAll(pattern)) {
        if (match[1]) {
            tokens.push({ type: 'comment', value: match[1] });
        } else if (match[2]) {
            tokens.push({ type: 'text', value: match[2] });
        } else if (match[4]) {
            tokens.push({ type: 'word', value: match[4] });
        } else if (match[5]) {
            tokens.push({ type: 'space', value: match[5] });
        } else {
            tokens.push({ type: 'other', value: match[6] ?? '' });
        }
    }

    return tokens;
}

export function formatSql(sql: string): string {
    const tokens = tokenize(sql);
    let output = '';
    let depth = 0;

    for (let index = 0; index < tokens.length; index++) {
        const token = tokens[index];

        if (token.type === 'space') {
            if (
                !output.endsWith('\n') &&
                !output.endsWith(' ') &&
                output !== ''
            ) {
                output += ' ';
            }

            continue;
        }

        if (token.type === 'comment') {
            output +=
                (output === '' || output.endsWith('\n') ? '' : ' ') +
                token.value.trim() +
                '\n';

            continue;
        }

        if (token.type === 'word') {
            const lower = token.value.toLowerCase();
            const ahead = tokens
                .slice(index, index + 6)
                .filter((item) => item.type === 'word')
                .map((item) => item.value.toLowerCase());
            const clause = BREAK_BEFORE.find((candidate) =>
                candidate
                    .split(' ')
                    .every((word, offset) => ahead[offset] === word),
            );

            if (clause && depth === 0 && output.trim() !== '') {
                output = output.trimEnd() + '\n';
            } else if ((lower === 'and' || lower === 'or') && depth === 0) {
                output = output.trimEnd() + '\n  ';
            }

            output += KEYWORDS.has(lower)
                ? token.value.toUpperCase()
                : token.value;

            continue;
        }

        if (token.value === '(') {
            depth++;
        } else if (token.value === ')') {
            depth = Math.max(0, depth - 1);
        }

        if (token.value === ';') {
            output = output.trimEnd() + ';\n\n';
            depth = 0;

            continue;
        }

        if (token.value === ',' && depth === 0) {
            output = output.trimEnd() + ',\n  ';

            continue;
        }

        output += token.value;
    }

    return output.trim() + '\n';
}
