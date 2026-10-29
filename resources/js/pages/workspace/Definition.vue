<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import {
    Clock,
    Copy,
    Eye,
    Hash,
    Pencil,
    Shapes,
    SquareFunction,
    SquareTerminal,
    Trash2,
    Zap,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import ConfirmChange from '@/components/server/ConfirmChange.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import ObjectHeader from '@/components/workspace/ObjectHeader.vue';
import SqlEditor from '@/components/workspace/sql/SqlEditor.vue';
import { useOpenTabs } from '@/composables/useOpenTabs';
import { titlePrefix } from '@/lib/environment';
import { t } from '@/lib/i18n';
import {
    definitionCreateHref,
    definitionHref,
    structureHref,
    withDb,
} from '@/lib/workspace';
import { sql as sqlRoute } from '@/routes/workspace';
import {
    apply as applyRoute,
    preview as previewRoute,
} from '@/routes/workspace/definition';
import type {
    ObjectChangeMeta,
    ObjectChangePreview,
    ObjectDefinitionView,
    ObjectKindKey,
    ObjectReference,
    ObjectStructure,
    Workspace,
} from '@/types';

/**
 * Ein Objekt neben den Tabellen (S-06): Sicht, Routine, Trigger, Ereignis, Sequenz oder Typ. Definition zum Ansehen
 * und Kopieren, «Im Editor öffnen», Ändern und Löschen mit Vorschau und Bestätigung; ohne Objekt die Seite zum
 * Anlegen mit einer Vorlage je Treiber.
 */
const props = defineProps<{
    workspace: Workspace;
    kind: ObjectKindKey;
    kinds: ObjectKindKey[];
    definition: ObjectDefinitionView | null;
    template: string | null;
    object: ObjectStructure['object'] | null;
    changes: ObjectChangeMeta;
}>();

type Action = 'create' | 'alter' | 'drop';
type Payload = {
    action: Action;
    name: string;
    qualifier: string;
    definition: string;
};

const creating = computed(() => props.definition === null);
const editing = ref(props.definition === null);
const text = ref(props.definition?.editable ?? props.template ?? '');
const pending = ref<Action>('alter');
const planned = ref<ObjectChangePreview | null>(null);
const confirming = ref(false);
const failure = ref<string | null>(null);

const http = useHttp<Payload, ObjectChangePreview>({
    action: 'alter',
    name: '',
    qualifier: '',
    definition: '',
});
const form = useForm({
    action: 'alter',
    name: '',
    qualifier: '',
    definition: '',
    confirmation: '',
});

const kindLabels = computed<Record<ObjectKindKey, string>>(() => ({
    view: t('Sicht'),
    function: t('Funktion'),
    procedure: t('Prozedur'),
    trigger: t('Trigger'),
    event: t('Ereignis'),
    sequence: t('Sequenz'),
    type: t('Typ'),
}));

const newTitles = computed<Record<ObjectKindKey, string>>(() => ({
    view: t('Neue Sicht'),
    function: t('Neue Funktion'),
    procedure: t('Neue Prozedur'),
    trigger: t('Neuer Trigger'),
    event: t('Neues Ereignis'),
    sequence: t('Neue Sequenz'),
    type: t('Neuer Typ'),
}));

const icons: Record<ObjectKindKey, unknown> = {
    view: Eye,
    function: SquareFunction,
    procedure: SquareFunction,
    trigger: Zap,
    event: Clock,
    sequence: Hash,
    type: Shapes,
};

const factLabels = computed<Record<string, string>>(() => ({
    arguments: t('Parameter'),
    returns: t('Rückgabe'),
    language: t('Sprache'),
    volatility: t('Verhalten'),
    deterministic: t('Deterministisch'),
    data_access: t('Datenzugriff'),
    security: t('Sicherheit'),
    owner: t('Besitzer'),
    definer: t('Angelegt von'),
    comment: t('Kommentar'),
    table: t('Tabelle'),
    timing: t('Zeitpunkt'),
    event: t('Auslöser'),
    order: t('Reihenfolge'),
    function: t('Funktion'),
    status: t('Status'),
    schedule: t('Zeitplan'),
    starts: t('Beginn'),
    ends: t('Ende'),
    on_completion: t('Nach dem Ende'),
    updatable: t('Änderbar'),
    check_option: 'CHECK OPTION',
    current: t('Letzter Wert'),
    data_type: t('Datentyp'),
    increment: t('Schritt'),
    minimum: t('Kleinster Wert'),
    maximum: t('Grösster Wert'),
    start: t('Startwert'),
    cache: t('Vorrat'),
    cycle: t('Am Ende'),
    owned_by: t('Gehört zu'),
    variant: t('Art'),
    base: t('Grundtyp'),
}));

const variantLabels = computed<Record<string, string>>(() => ({
    enum: t('Aufzählung'),
    composite: t('Zusammengesetzt'),
    domain: t('Domäne'),
    range: t('Bereich'),
}));

// Tabelle und Funktion eines Triggers (bzw. Tabelle einer Sequenz) führen zu ihrer Seite.
const facts = computed(() =>
    (props.definition?.facts ?? []).map((fact) => {
        const reference =
            fact.key === 'table' || fact.key === 'owned_by'
                ? props.definition?.references.find(
                      (item) => item.kind === 'table',
                  )
                : fact.key === 'function'
                  ? props.definition?.references.find(
                        (item) => item.kind === 'function',
                    )
                  : undefined;

        return {
            key: fact.key,
            label: factLabels.value[fact.key] ?? fact.key,
            value:
                fact.key === 'variant'
                    ? (variantLabels.value[fact.value] ?? fact.value)
                    : fact.value,
            href: reference ? referenceHref(reference) : null,
        };
    }),
);

const heading = computed(() =>
    props.definition ? props.definition.name : newTitles.value[props.kind],
);
const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${heading.value} · ${props.workspace.connection.name}`,
);

const { tabs: openTabs } = useOpenTabs(props.workspace.connection.id);
const editorHref = computed(() =>
    sqlRoute.url(props.workspace.connection.id, {
        query: {
            tab:
                Math.max(
                    0,
                    ...openTabs.value
                        .filter((tab) => tab.kind === 'query')
                        .map((tab) => Number(tab.title)),
                ) + 1,
            ...(props.workspace.schema
                ? { schema: props.workspace.schema }
                : {}),
            ...(props.workspace.database
                ? { db: props.workspace.database }
                : {}),
            vorlage: props.definition?.ddl ?? '',
        },
    }),
);

const routeArgs = computed<[string, string, string]>(() => [
    props.workspace.connection.id,
    encodeURIComponent(props.workspace.schema ?? ''),
    props.kind,
]);

const blockedText = computed(() =>
    props.changes.blocked === 'read_only'
        ? t(
              'Diese Verbindung ist schreibgeschützt — hier lässt sich nur ansehen. Heben Sie den Schutz auf Zeit auf, um zu ändern.',
          )
        : t(
              'Ihr Konto darf an dieser Verbindung nicht schreiben — hier lässt sich nur ansehen.',
          ),
);

const editHint = computed(() => {
    if (creating.value) {
        return props.changes.transactional
            ? t(
                  'Läuft in einer Transaktion — scheitert sie, bleibt alles, wie es war.',
              )
            : t('Strukturänderungen laufen hier ohne Transaktion.');
    }

    return props.changes.replacesInPlace
        ? t(
              'Mit OR REPLACE wird an Ort und Stelle ersetzt; ohne wird das Objekt gelöscht und neu angelegt.',
          )
        : props.changes.transactional
          ? t(
                'Ein CREATE löscht das Objekt und legt es neu an — in einer Transaktion. ALTER ändert an Ort und Stelle.',
            )
          : t(
                'Ein CREATE löscht das Objekt und legt es neu an — nicht rückrollbar; scheitert das Neuanlegen, wird die bisherige Definition wiederhergestellt.',
            );
});

const confirmTitle = computed(
    () =>
        ({
            create: t('Objekt anlegen'),
            alter: t('Objekt ändern'),
            drop: t('Objekt löschen'),
        })[pending.value],
);
const confirmLabel = computed(
    () =>
        ({
            create: t('Anlegen'),
            alter: t('Ändern'),
            drop: t('Löschen'),
        })[pending.value],
);

const errors = computed<Record<string, string | undefined>>(() => ({
    ...(http.errors as Record<string, string | undefined>),
    ...(form.errors as Record<string, string | undefined>),
}));
const fieldError = computed(
    () => errors.value.definition ?? errors.value.name ?? null,
);
const applyError = computed(
    () => (form.errors as Record<string, string | undefined>).change ?? null,
);

function payload(action: Action): Payload {
    return {
        action,
        name: props.definition?.name ?? '',
        qualifier: props.definition?.qualifier ?? '',
        definition: action === 'drop' ? '' : text.value,
    };
}

async function showPreview(action: Action): Promise<void> {
    http.clearErrors();
    form.clearErrors();
    failure.value = null;
    planned.value = null;
    pending.value = action;
    confirming.value = true;

    try {
        const response = await http
            .transform(() => payload(action))
            .post(previewRoute.url(routeArgs.value, withDb(props.workspace)));

        if (response) {
            planned.value = response;
        } else {
            confirming.value = false;
        }
    } catch {
        confirming.value = false;

        if (Object.keys(http.errors).length === 0) {
            failure.value = t(
                'Die Vorschau ging nicht durch — laden Sie die Seite neu und versuchen Sie es noch einmal.',
            );
        }
    }
}

function apply(confirmation: string): void {
    form.transform(() => ({ ...payload(pending.value), confirmation })).post(
        applyRoute.url(routeArgs.value, withDb(props.workspace)),
        {
            preserveScroll: true,
            onSuccess: () => {
                confirming.value = false;
                planned.value = null;
            },
            onError: (problems) => {
                if (problems.definition || problems.name) {
                    confirming.value = false;
                }
            },
        },
    );
}

const confirmOpen = computed({
    get: () => confirming.value,
    set: (open: boolean) => {
        if (!open) {
            confirming.value = false;
            planned.value = null;
        }
    },
});

function startEditing(): void {
    text.value = props.definition?.editable ?? '';
    editing.value = true;
}

function stopEditing(): void {
    editing.value = false;
    http.clearErrors();
    form.clearErrors();
}

async function copyDefinition(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.definition?.ddl ?? '');
        toast.success(t('Definition kopiert.'));
    } catch {
        toast.error(
            t('Kopieren ging nicht — markieren Sie den Text von Hand.'),
        );
    }
}

function referenceHref(reference: ObjectReference): string {
    return reference.kind === 'table'
        ? structureHref(props.workspace, reference.name, reference.schema)
        : definitionHref(
              props.workspace,
              'function',
              reference.name,
              reference.qualifier,
              reference.schema,
          );
}

function overloadHref(qualifier: string): string {
    return definitionHref(
        props.workspace,
        props.kind,
        props.definition?.name ?? '',
        qualifier,
    );
}
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col">
        <ObjectHeader
            v-if="object && definition"
            :workspace="workspace"
            :object="object"
            active="definition"
        />
        <div
            v-else
            class="flex flex-col gap-1.5 border-b px-4 pt-4 pb-3 sm:px-6"
        >
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1.5">
                <h1
                    class="flex min-w-0 items-center gap-2 font-mono text-xl font-semibold"
                >
                    <component
                        :is="icons[kind]"
                        class="size-5 shrink-0 text-text-secondary"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 wrap-break-word">{{ heading }}</span>
                </h1>
                <p class="text-sm text-text-secondary">
                    {{ kindLabels[kind] }} ·
                    {{
                        workspace.schemaKind === 'schema'
                            ? t('Schema')
                            : t('Datenbank')
                    }}
                    <span class="font-mono">{{ workspace.schema }}</span>
                </p>
            </div>
            <nav
                v-if="creating && kinds.length > 1"
                class="flex flex-wrap gap-1.5 pt-1"
                :aria-label="t('Art des neuen Objekts')"
            >
                <Link
                    v-for="option in kinds"
                    :key="option"
                    :href="definitionCreateHref(workspace, option)"
                    :class="[
                        'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        option === kind
                            ? 'border-primary bg-accent font-medium'
                            : 'hover:bg-muted',
                    ]"
                    :aria-current="option === kind ? 'page' : undefined"
                >
                    <component :is="icons[option]" class="size-3.5" />{{
                        kindLabels[option]
                    }}
                </Link>
            </nav>
        </div>

        <div class="flex flex-col gap-5 px-4 py-4 sm:px-6">
            <Notice v-if="!changes.canChange" variant="info">{{
                blockedText
            }}</Notice>

            <dl
                v-if="facts.length"
                class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]"
            >
                <template v-for="fact in facts" :key="fact.key">
                    <dt class="text-text-secondary">{{ fact.label }}</dt>
                    <dd class="min-w-0 font-mono wrap-break-word">
                        <Link
                            v-if="fact.href"
                            :href="fact.href"
                            class="text-primary underline-offset-2 hover:underline"
                            >{{ fact.value }}</Link
                        >
                        <template v-else>{{ fact.value }}</template>
                    </dd>
                </template>
            </dl>

            <section
                v-if="definition && definition.overloads.length > 1"
                class="flex flex-col gap-1.5 text-sm"
            >
                <h2 class="font-semibold">
                    {{
                        kind === 'trigger'
                            ? t('Trigger mit diesem Namen an anderen Tabellen')
                            : t('Weitere Routinen mit diesem Namen')
                    }}
                </h2>
                <ul class="flex flex-wrap gap-1.5">
                    <li
                        v-for="overload in definition.overloads"
                        :key="overload"
                    >
                        <Link
                            :href="overloadHref(overload)"
                            :class="[
                                'inline-flex rounded-full border px-2.5 py-0.5 font-mono text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                overload === definition.qualifier
                                    ? 'border-primary bg-accent'
                                    : 'hover:bg-muted',
                            ]"
                            :aria-current="
                                overload === definition.qualifier
                                    ? 'page'
                                    : undefined
                            "
                            >{{
                                kind === 'trigger'
                                    ? overload
                                    : `${definition.name}(${overload})`
                            }}</Link
                        >
                    </li>
                </ul>
            </section>

            <section
                v-if="
                    definition &&
                    (definition.columns.length || definition.values.length)
                "
                class="flex flex-col gap-1.5 text-sm"
            >
                <h2 class="font-semibold">
                    {{ definition.values.length ? t('Werte') : t('Spalten') }}
                </h2>
                <ul class="flex flex-wrap gap-1.5">
                    <li
                        v-for="value in definition.values"
                        :key="`v-${value}`"
                        class="rounded-full border px-2.5 py-0.5 font-mono text-[13px]"
                    >
                        {{ value }}
                    </li>
                    <li
                        v-for="column in definition.columns"
                        :key="`c-${column.name}`"
                        class="rounded-md border px-2.5 py-0.5 text-[13px]"
                    >
                        <span class="font-mono">{{ column.name }}</span>
                        <span class="ml-1.5 font-mono text-text-secondary">{{
                            column.type
                        }}</span>
                    </li>
                </ul>
            </section>

            <section
                v-if="definition && !editing"
                class="flex flex-col gap-2"
                :aria-label="t('Definition')"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="mr-auto font-semibold">{{ t('Definition') }}</h2>
                    <Button size="sm" variant="outline" @click="copyDefinition">
                        <Copy />{{ t('Kopieren') }}
                    </Button>
                    <Button size="sm" variant="outline" as-child>
                        <Link :href="editorHref"
                            ><SquareTerminal />{{ t('Im Editor öffnen') }}</Link
                        >
                    </Button>
                    <Button
                        v-if="changes.canChange && definition.editable !== null"
                        size="sm"
                        @click="startEditing"
                    >
                        <Pencil />{{ t('Ändern') }}
                    </Button>
                    <Button
                        v-if="changes.canChange"
                        size="sm"
                        variant="outline"
                        class="text-destructive"
                        :disabled="http.processing"
                        @click="showPreview('drop')"
                    >
                        <Trash2 />{{ t('Löschen …') }}
                    </Button>
                </div>
                <pre
                    class="rounded-md border bg-muted px-3 py-2 font-mono text-[13px] leading-5 wrap-break-word whitespace-pre-wrap"
                    tabindex="0"
                    >{{ definition.ddl }}</pre>
                <p
                    v-if="changes.canChange && definition.editable === null"
                    class="text-[13px] text-text-secondary"
                >
                    {{
                        t(
                            'Die vollständige Definition ist für dieses Konto nicht lesbar — ändern lässt sie sich hier deshalb nicht.',
                        )
                    }}
                </p>
                <InputError :message="errors.change ?? failure ?? undefined" />
            </section>

            <form
                v-if="editing && changes.canChange"
                class="flex flex-col gap-2"
                @submit.prevent="showPreview(creating ? 'create' : 'alter')"
            >
                <h2 class="font-semibold">
                    {{
                        creating
                            ? t('Definition des neuen Objekts')
                            : t('Definition ändern')
                    }}
                </h2>
                <p class="text-[13px] text-text-secondary">{{ editHint }}</p>
                <div
                    class="h-80 min-h-48 overflow-hidden rounded-md border sm:h-96"
                >
                    <SqlEditor
                        v-model="text"
                        :driver="workspace.connection.driver"
                        :schema="null"
                        :default-schema="workspace.schema"
                        @run="showPreview(creating ? 'create' : 'alter')"
                    />
                </div>
                <InputError
                    :message="
                        fieldError ?? errors.change ?? failure ?? undefined
                    "
                />
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="submit"
                        class="w-full sm:w-auto"
                        :disabled="http.processing || !text.trim()"
                    >
                        <Spinner v-if="http.processing" />{{
                            t('Vorschau zeigen')
                        }}
                    </Button>
                    <kbd
                        class="hidden rounded border bg-muted px-1.5 font-mono text-[11px] leading-5 text-muted-foreground sm:inline"
                        >⌘↵</kbd
                    >
                    <Button
                        v-if="!creating"
                        type="button"
                        variant="outline"
                        class="w-full sm:w-auto"
                        @click="stopEditing"
                    >
                        {{ t('Abbrechen') }}
                    </Button>
                    <Button
                        v-else
                        type="button"
                        variant="outline"
                        class="w-full sm:w-auto"
                        @click="text = template ?? ''"
                    >
                        {{ t('Vorlage wiederherstellen') }}
                    </Button>
                </div>
            </form>
        </div>

        <ConfirmChange
            v-model:open="confirmOpen"
            :title="confirmTitle"
            :description="planned?.target ?? null"
            :statements="planned?.statements ?? []"
            :notes="planned?.notes ?? []"
            :confirm-name="changes.confirmName"
            :confirm-label="confirmLabel"
            :destructive="pending === 'drop'"
            :loading="http.processing"
            :processing="form.processing"
            :error="applyError"
            :confirmation-error="errors.confirmation ?? null"
            @confirm="apply"
        />
    </div>
</template>
