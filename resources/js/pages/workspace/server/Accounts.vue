<script setup lang="ts">
import { router, useForm, useHttp } from '@inertiajs/vue3';
import {
    Eye,
    EyeOff,
    KeyRound,
    Search,
    ShieldMinus,
    ShieldPlus,
    Trash2,
    UserPlus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import ConfirmChange from '@/components/server/ConfirmChange.vue';
import ServerHeader from '@/components/server/ServerHeader.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatNumber, t } from '@/lib/i18n';
import { apply, preview } from '@/routes/workspace/server/accounts';
import type {
    ChangePreview,
    ServerAccount,
    ServerMeta,
    Workspace,
} from '@/types';

/**
 * Datenbank-Benutzer, Rollen und Rechte (W-04): Liste mit Rechten; anlegen, löschen, erteilen, entziehen — immer
 * zuerst die SQL-Vorschau (Passwort maskiert), ausgeführt erst nach der Bestätigung.
 */
const props = defineProps<{
    workspace: Workspace;
    server: ServerMeta;
    accounts: ServerAccount[] | null;
    accountsProblem: string | null;
    levels: Record<string, string[]>;
    hasHosts: boolean;
    databases: string[];
    schemas: string[] | null;
    tables?: string[];
}>();

type Action = 'create' | 'drop' | 'grant' | 'revoke';

const search = ref('');
const editing = ref<Action | null>(null);
const confirming = ref(false);
const showPassword = ref(false);
const loadingTables = ref(false);

const form = useForm({
    action: 'create' as Action,
    name: '',
    host: '%',
    password: '',
    level: '',
    database: '',
    schema: '',
    table: '',
    privileges: [] as string[],
    confirmation: '',
});
/** Alle Felder gehen mit; was zur Aktion nicht gehört, lässt der Server weg (exclude_unless). */
type Payload = {
    action: Action;
    name: string;
    host: string;
    password: string;
    level: string;
    privileges: string[];
    database: string;
    schema: string;
    table: string;
};

const http = useHttp<Payload, ChangePreview>({
    action: 'create',
    name: '',
    host: '',
    password: '',
    level: '',
    privileges: [],
    database: '',
    schema: '',
    table: '',
});
const planned = ref<ChangePreview | null>(null);

const isPostgres = computed(
    () => props.workspace.connection.driver === 'pgsql',
);
const errors = computed<Record<string, string | undefined>>(() => ({
    ...(http.errors as Record<string, string | undefined>),
    ...(form.errors as Record<string, string | undefined>),
}));

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();

    return (props.accounts ?? []).filter(
        (account) =>
            term === '' ||
            [account.name, account.host, ...account.grants].some((value) =>
                value?.toLowerCase().includes(term),
            ),
    );
});

const levelLabels = computed<Record<string, string>>(() => ({
    server: t('Server'),
    database: t('Datenbank'),
    schema: t('Schema'),
    table: t('Tabelle'),
}));

const attributeLabels = computed<Record<string, string>>(() => ({
    locked: t('gesperrt'),
    expired: t('Passwort abgelaufen'),
    role: t('Rolle'),
    superuser: t('Superuser'),
    login: t('darf sich anmelden'),
    createdb: t('legt Datenbanken an'),
    createrole: t('legt Rollen an'),
    replication: t('Replikation'),
    bypassrls: t('umgeht Zeilenrechte'),
}));

const needsDatabase = computed(
    () =>
        form.level === 'database' ||
        (!isPostgres.value && form.level === 'table'),
);
const needsSchema = computed(
    () =>
        isPostgres.value && (form.level === 'schema' || form.level === 'table'),
);
const needsTable = computed(() => form.level === 'table');
const choices = computed(() => props.levels[form.level] ?? []);

const editTitle = computed(
    () =>
        ({
            create: t('Konto anlegen'),
            drop: t('Konto löschen'),
            grant: t('Rechte erteilen'),
            revoke: t('Rechte entziehen'),
        })[form.action],
);

function label(account: { name: string; host: string | null }): string {
    return account.host === null
        ? account.name
        : `${account.name}@${account.host}`;
}

function start(action: Action, account: ServerAccount | null = null): void {
    form.reset();
    form.clearErrors();
    http.clearErrors();
    form.action = action;
    form.name = account?.name ?? '';
    form.host = account?.host ?? '%';
    form.level = Object.keys(props.levels)[0] ?? '';
    form.database = props.databases[0] ?? '';
    form.schema = props.schemas?.[0] ?? '';
    showPassword.value = false;

    if (action === 'drop') {
        void showPreview();

        return;
    }

    editing.value = action;
}

function chooseLevel(level: string): void {
    form.level = level;
    form.privileges = [];
    form.table = '';

    if (level === 'table') {
        loadTables();
    }
}

/** Tabellen der gewählten Datenbank (MySQL, MariaDB) bzw. des Schemas (PostgreSQL) nachladen. */
function loadTables(): void {
    const container = isPostgres.value ? form.schema : form.database;
    form.table = '';

    if (!container) {
        return;
    }

    router.reload({
        only: ['tables'],
        data: { tables_in: container },
        preserveUrl: true,
        onStart: () => {
            loadingTables.value = true;
        },
        onFinish: () => {
            loadingTables.value = false;
        },
    });
}

function togglePrivilege(privilege: string, checked: boolean): void {
    form.privileges = checked
        ? [...form.privileges, privilege]
        : form.privileges.filter((chosen) => chosen !== privilege);
}

function payload(): Payload {
    const changesPrivileges =
        form.action === 'grant' || form.action === 'revoke';

    return {
        action: form.action,
        name: form.name,
        host: props.hasHosts ? form.host : '',
        password: form.action === 'create' ? form.password : '',
        level: changesPrivileges ? form.level : '',
        privileges: changesPrivileges ? form.privileges : [],
        // PostgreSQL: Schema und Tabelle liegen in der geöffneten Datenbank.
        database: needsDatabase.value
            ? form.database
            : (props.workspace.database ?? ''),
        schema: needsSchema.value ? form.schema : '',
        table: needsTable.value ? form.table : '',
    };
}

async function showPreview(): Promise<void> {
    http.clearErrors();
    planned.value = null;
    // Löschen hat kein Formular: der Bestätigungsdialog zeigt gleich, dass die Vorschau entsteht.
    confirming.value = editing.value === null;

    try {
        const response = await http
            .transform(() => payload())
            .post(preview.url(props.workspace.connection.id));

        if (response) {
            planned.value = response;
            editing.value = null;
            confirming.value = true;
        } else {
            // Prüfmeldungen gehören ans Formular (beim Löschen auf die Seite).
            confirming.value = false;
        }
    } catch {
        confirming.value = false;
    }
}

function run(confirmation: string): void {
    form.confirmation = confirmation;
    form.transform(() => ({ ...payload(), confirmation })).post(
        apply.url(props.workspace.connection.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                confirming.value = false;
                planned.value = null;
                form.reset();
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
const editOpen = computed({
    get: () => editing.value !== null,
    set: (open: boolean) => {
        if (!open) {
            editing.value = null;
        }
    },
});

const blocked = computed(() =>
    props.server.canChange
        ? undefined
        : props.server.blocked === 'read_only'
          ? t('Die Verbindung ist schreibgeschützt.')
          : t('Dafür braucht es das Recht «verwalten».'),
);
const dropError = computed(() =>
    form.action === 'drop' ? (errors.value.name ?? null) : null,
);
// Was beim Ausführen scheitert (Server, Prüfung gegen den Katalog), steht im Bestätigungsdialog.
const applyError = computed(
    () =>
        Object.entries(form.errors as Record<string, string | undefined>).find(
            ([field]) => field !== 'confirmation',
        )?.[1] ?? null,
);
const current = computed(() => ({
    name: form.name,
    host: props.hasHosts ? form.host : null,
}));
</script>

<template>
    <div class="flex flex-col gap-5 px-4 py-5 sm:px-6">
        <ServerHeader
            :workspace="workspace"
            :server="server"
            :title="t('Konten und Rechte')"
        />

        <Notice v-if="accounts === null">
            <b class="font-semibold">{{
                t('SQLite kennt keine Konten und Rechte.')
            }}</b>
            {{
                t(
                    'Wer die Datei lesen darf, liest alles. Schützen Sie die Datei über die Rechte im Dateisystem.',
                )
            }}
        </Notice>

        <template v-else>
            <Notice v-if="accountsProblem" variant="warning">
                <b class="font-semibold">{{
                    t('Dieses Konto darf die Konten des Servers nicht lesen.')
                }}</b>
                <span class="mt-1 block font-mono text-xs wrap-break-word">{{
                    accountsProblem
                }}</span>
            </Notice>

            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full sm:w-72">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        :placeholder="t('Konto oder Recht suchen')"
                        :aria-label="t('Konto oder Recht suchen')"
                        class="pl-8"
                    />
                </div>
                <span class="text-xs text-text-secondary tabular-nums">{{
                    t(':shown von :count', {
                        shown: formatNumber(visible.length),
                        count: formatNumber(accounts.length),
                    })
                }}</span>
                <Button
                    class="w-full sm:ml-auto sm:w-auto"
                    :disabled="blocked !== undefined"
                    :title="blocked"
                    @click="start('create')"
                >
                    <UserPlus />{{ t('Konto anlegen') }}
                </Button>
            </div>

            <ul class="grid gap-3 xl:grid-cols-2">
                <li
                    v-for="account in visible"
                    :key="label(account)"
                    :class="[
                        'flex min-w-0 flex-col gap-2 rounded-lg border px-4 py-3',
                        account.own && 'border-primary',
                    ]"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <KeyRound class="size-4 shrink-0 text-text-secondary" />
                        <span class="font-mono font-semibold wrap-break-word">{{
                            account.name
                        }}</span>
                        <span
                            v-if="account.host !== null"
                            class="font-mono text-sm text-text-secondary"
                            >@{{ account.host }}</span
                        >
                        <span
                            v-if="account.own"
                            class="rounded-full border border-primary px-2 py-px text-xs font-medium text-primary"
                            >{{ t('phpdbadmin verbindet sich damit') }}</span
                        >
                        <span
                            v-for="attribute in account.attributes"
                            :key="attribute"
                            :class="[
                                'rounded-full border px-2 py-px text-xs',
                                attribute === 'superuser'
                                    ? 'border-warning text-warning'
                                    : 'text-text-secondary',
                            ]"
                            >{{ attributeLabels[attribute] ?? attribute }}</span
                        >
                    </div>
                    <ul
                        v-if="account.grants.length"
                        class="flex flex-col gap-1 rounded-md bg-muted px-2.5 py-2 font-mono text-xs leading-[18px]"
                    >
                        <li
                            v-for="grant in account.grants"
                            :key="grant"
                            class="wrap-break-word"
                        >
                            {{ grant }}
                        </li>
                    </ul>
                    <p v-else class="text-xs text-text-secondary">
                        {{ t('Keine Rechte ausser der Anmeldung.') }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="blocked !== undefined"
                            :title="blocked"
                            @click="start('grant', account)"
                        >
                            <ShieldPlus />{{ t('Rechte erteilen') }}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="blocked !== undefined"
                            :title="blocked"
                            @click="start('revoke', account)"
                        >
                            <ShieldMinus />{{ t('Rechte entziehen') }}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="text-destructive"
                            :disabled="blocked !== undefined || account.own"
                            :title="
                                account.own
                                    ? t(
                                          'Mit diesem Konto ist phpdbadmin verbunden.',
                                      )
                                    : blocked
                            "
                            @click="start('drop', account)"
                        >
                            <Trash2 />{{ t('Löschen') }}
                        </Button>
                    </div>
                </li>
            </ul>
            <p
                v-if="!visible.length"
                class="rounded-lg border border-dashed px-4 py-8 text-center text-sm text-text-secondary"
            >
                {{ t('Kein Konto passt zur Suche.') }}
            </p>
            <Notice v-if="dropError" variant="danger">{{ dropError }}</Notice>
        </template>

        <Dialog v-model:open="editOpen">
            <DialogContent class="flex max-h-[90svh] flex-col sm:max-w-xl">
                <DialogTitle>{{ editTitle }}</DialogTitle>
                <DialogDescription v-if="form.action !== 'create'">
                    <code class="font-mono">{{ label(current) }}</code>
                </DialogDescription>
                <DialogDescription v-else>
                    {{
                        t(
                            'Zuerst sehen Sie die Anweisung, dann bestätigen Sie.',
                        )
                    }}
                </DialogDescription>

                <form
                    class="flex min-h-0 flex-col gap-4 overflow-y-auto"
                    @submit.prevent="showPreview"
                >
                    <template v-if="form.action === 'create'">
                        <div class="grid gap-4 sm:grid-cols-[2fr_1fr]">
                            <div class="flex flex-col gap-1.5">
                                <Label for="account-name" class="text-[13px]">{{
                                    t('Kontoname')
                                }}</Label>
                                <Input
                                    id="account-name"
                                    v-model="form.name"
                                    autocomplete="off"
                                    spellcheck="false"
                                    class="font-mono"
                                    required
                                    :aria-invalid="
                                        errors.name ? true : undefined
                                    "
                                />
                                <InputError :message="errors.name" />
                            </div>
                            <div v-if="hasHosts" class="flex flex-col gap-1.5">
                                <Label for="account-host" class="text-[13px]">{{
                                    t('Host')
                                }}</Label>
                                <Input
                                    id="account-host"
                                    v-model="form.host"
                                    autocomplete="off"
                                    spellcheck="false"
                                    class="font-mono"
                                    required
                                    :aria-invalid="
                                        errors.host ? true : undefined
                                    "
                                />
                                <InputError :message="errors.host" />
                            </div>
                        </div>
                        <p
                            v-if="hasHosts"
                            class="-mt-2 text-xs text-text-secondary"
                        >
                            {{
                                t(
                                    '% heisst «von überall», localhost nur vom Server selbst.',
                                )
                            }}
                        </p>
                        <div class="flex flex-col gap-1.5">
                            <Label for="account-password" class="text-[13px]">{{
                                t('Passwort')
                            }}</Label>
                            <div class="relative">
                                <Input
                                    id="account-password"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    autocomplete="new-password"
                                    class="pr-10 font-mono"
                                    required
                                    minlength="8"
                                    :aria-invalid="
                                        errors.password ? true : undefined
                                    "
                                />
                                <button
                                    type="button"
                                    class="absolute top-1/2 right-1 inline-grid size-8 -translate-y-1/2 place-items-center rounded-md text-text-secondary outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-label="
                                        showPassword
                                            ? t('Passwort verbergen')
                                            : t('Passwort zeigen')
                                    "
                                    @click="showPassword = !showPassword"
                                >
                                    <EyeOff
                                        v-if="showPassword"
                                        class="size-4"
                                    />
                                    <Eye v-else class="size-4" />
                                </button>
                            </div>
                            <InputError :message="errors.password" />
                            <span class="text-xs text-text-secondary">{{
                                t(
                                    'Mindestens 8 Zeichen. Es erscheint weder in der Vorschau noch im Protokoll.',
                                )
                            }}</span>
                        </div>
                    </template>

                    <template v-else>
                        <fieldset class="flex flex-col gap-1.5">
                            <legend class="mb-1.5 text-[13px] font-medium">
                                {{ t('Ebene') }}
                            </legend>
                            <div
                                class="flex flex-wrap gap-1 rounded-lg border bg-muted p-0.5"
                                role="radiogroup"
                                :aria-label="t('Ebene')"
                            >
                                <button
                                    v-for="level in Object.keys(levels)"
                                    :key="level"
                                    type="button"
                                    role="radio"
                                    :aria-checked="form.level === level"
                                    :class="[
                                        'flex-1 rounded-md px-3 py-1 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                        form.level === level
                                            ? 'bg-background font-semibold shadow-xs'
                                            : 'text-text-secondary hover:text-foreground',
                                    ]"
                                    @click="chooseLevel(level)"
                                >
                                    {{ levelLabels[level] ?? level }}
                                </button>
                            </div>
                            <InputError :message="errors.level" />
                            <span
                                v-if="isPostgres && form.level === 'server'"
                                class="text-xs text-text-secondary"
                                >{{
                                    t(
                                        'PostgreSQL vergibt Rechte auf dem ganzen Server über vordefinierte Rollen.',
                                    )
                                }}</span
                            >
                            <span
                                v-if="isPostgres && needsSchema"
                                class="text-xs text-text-secondary"
                                >{{
                                    t('In der Datenbank :database.', {
                                        database: workspace.databaseLabel ?? '',
                                    })
                                }}</span
                            >
                        </fieldset>

                        <div
                            v-if="needsDatabase || needsSchema || needsTable"
                            class="grid gap-4 sm:grid-cols-2"
                        >
                            <div
                                v-if="needsDatabase"
                                class="flex flex-col gap-1.5"
                            >
                                <Label
                                    for="grant-database"
                                    class="text-[13px]"
                                    >{{ t('Datenbank') }}</Label
                                >
                                <select
                                    id="grant-database"
                                    v-model="form.database"
                                    class="h-9 rounded-md border bg-background px-2.5 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    @change="needsTable && loadTables()"
                                >
                                    <option
                                        v-for="database in databases"
                                        :key="database"
                                        :value="database"
                                    >
                                        {{ database }}
                                    </option>
                                </select>
                                <InputError :message="errors.database" />
                            </div>
                            <div
                                v-if="needsSchema"
                                class="flex flex-col gap-1.5"
                            >
                                <Label for="grant-schema" class="text-[13px]">{{
                                    t('Schema')
                                }}</Label>
                                <select
                                    id="grant-schema"
                                    v-model="form.schema"
                                    class="h-9 rounded-md border bg-background px-2.5 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    @change="needsTable && loadTables()"
                                >
                                    <option
                                        v-for="schema in schemas ?? []"
                                        :key="schema"
                                        :value="schema"
                                    >
                                        {{ schema }}
                                    </option>
                                </select>
                                <InputError :message="errors.schema" />
                            </div>
                            <div
                                v-if="needsTable"
                                class="flex flex-col gap-1.5"
                            >
                                <Label for="grant-table" class="text-[13px]">{{
                                    t('Tabelle')
                                }}</Label>
                                <select
                                    id="grant-table"
                                    v-model="form.table"
                                    :disabled="loadingTables"
                                    class="h-9 rounded-md border bg-background px-2.5 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                >
                                    <option value="" disabled>
                                        {{
                                            loadingTables
                                                ? t('Tabellen werden geladen …')
                                                : t('Tabelle wählen')
                                        }}
                                    </option>
                                    <option
                                        v-for="table in tables ?? []"
                                        :key="table"
                                        :value="table"
                                    >
                                        {{ table }}
                                    </option>
                                </select>
                                <InputError :message="errors.table" />
                            </div>
                        </div>

                        <fieldset class="flex flex-col gap-1.5">
                            <legend class="mb-1.5 text-[13px] font-medium">
                                {{ t('Rechte') }}
                            </legend>
                            <div
                                class="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3"
                            >
                                <label
                                    v-for="privilege in choices"
                                    :key="privilege"
                                    class="flex items-center gap-2 font-mono text-xs"
                                >
                                    <Checkbox
                                        :model-value="
                                            form.privileges.includes(privilege)
                                        "
                                        @update:model-value="
                                            (checked) =>
                                                togglePrivilege(
                                                    privilege,
                                                    checked === true,
                                                )
                                        "
                                    />{{ privilege }}
                                </label>
                            </div>
                            <InputError :message="errors.privileges" />
                            <span
                                v-if="isPostgres && form.level === 'schema'"
                                class="text-xs text-text-secondary"
                                >{{
                                    t(
                                        'USAGE und CREATE gelten dem Schema, die übrigen allen Tabellen darin.',
                                    )
                                }}</span
                            >
                        </fieldset>
                    </template>

                    <InputError :message="errors.server" />

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="outline">{{
                                t('Abbrechen')
                            }}</Button>
                        </DialogClose>
                        <Button type="submit" :disabled="http.processing">
                            <Spinner v-if="http.processing" />{{
                                t('Vorschau zeigen')
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <ConfirmChange
            v-model:open="confirmOpen"
            :title="editTitle"
            :description="planned?.target ?? null"
            :statements="planned?.statements ?? []"
            :notes="planned?.notes ?? []"
            :confirm-name="server.confirmName"
            :confirm-label="editTitle"
            :destructive="form.action === 'drop' || form.action === 'revoke'"
            :loading="http.processing"
            :processing="form.processing"
            :error="applyError"
            :confirmation-error="errors.confirmation ?? null"
            @confirm="run"
        />
    </div>
</template>
