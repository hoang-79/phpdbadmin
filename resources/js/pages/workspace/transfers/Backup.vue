<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArchiveRestore,
    ArrowLeft,
    CircleAlert,
    CircleCheck,
    Database,
    HardDriveDownload,
    TriangleAlert,
    Wrench,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import TransferList from '@/components/transfers/TransferList.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTransferPolling } from '@/composables/useTransferPolling';
import { titlePrefix } from '@/lib/environment';
import { formatNumber, t } from '@/lib/i18n';
import { databaseQuery, transfersHref } from '@/lib/transfers';
import {
    restore as restoreRoute,
    status as statusRoute,
} from '@/routes/workspace/transfers';
import { store as storeBackup } from '@/routes/workspace/transfers/backup';
import type {
    BackupStatus,
    TransferMode,
    TransferSummary,
    Workspace,
} from '@/types';

/**
 * Sicherung und Wiederherstellung über die Werkzeuge des Servers (E-03): welche Werkzeuge da sind, Sicherung als
 * Auftrag mit Download, Wiederherstellen aus einer hochgeladenen Datei in eine bestehende Datenbank — nur mit Recht
 * «schreiben», nie über eine schreibgeschützte Verbindung, in Produktion mit dem Namen der Verbindung.
 */
const props = defineProps<{
    workspace: Workspace;
    status: BackupStatus;
    databases: string[];
    defaultDatabase: string | null;
    transfers: TransferSummary[];
    mode: TransferMode;
    canWrite: boolean;
    requiresConfirmation: boolean;
    maxUploadMegabytes: number;
}>();

const driver = computed(() => props.workspace.connection.driver);
const isPostgres = computed(() => driver.value === 'pgsql');
const isSqlite = computed(() => driver.value === 'sqlite');
const isMySql = computed(() => ['mysql', 'mariadb'].includes(driver.value));

const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${t('Sicherung und Wiederherstellung')} · ${props.workspace.connection.name}`,
);

const all = useTransferPolling(
    () =>
        statusRoute.url(
            props.workspace.connection.id,
            databaseQuery(props.workspace),
        ),
    () => props.transfers,
);
const transfers = computed(() =>
    all.value.filter(
        (transfer) => transfer.kind === 'backup' || transfer.kind === 'restore',
    ),
);

const backupForm = useForm({
    database: props.defaultDatabase ?? props.databases[0] ?? '',
    pg_format: 'custom' as 'custom' | 'plain',
    objects: true,
});

function submitBackup(): void {
    backupForm.post(
        storeBackup.url(
            props.workspace.connection.id,
            databaseQuery(props.workspace),
        ),
        { preserveScroll: true },
    );
}

const restoreForm = useForm({
    database: props.defaultDatabase ?? props.databases[0] ?? '',
    file: null as File | null,
    confirm: false,
    confirmation: '',
});

function chooseFile(event: Event): void {
    restoreForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submitRestore(): void {
    restoreForm.post(
        restoreRoute.url(
            props.workspace.connection.id,
            databaseQuery(props.workspace),
        ),
        {
            preserveScroll: true,
            onSuccess: () =>
                restoreForm.reset('file', 'confirm', 'confirmation'),
        },
    );
}

const accept = computed(() =>
    isSqlite.value
        ? '.sqlite,.sqlite3,.db'
        : isPostgres.value
          ? '.dump,.backup,.sql'
          : '.sql',
);

const backupHint = computed(() => {
    if (isSqlite.value) {
        return t(
            'Eine Kopie der Datei in einem gleichbleibenden Stand (VACUUM INTO) — ohne Werkzeug.',
        );
    }

    return isPostgres.value
        ? t(
              'pg_dump sichert die ganze Datenbank mit allen Schemas, ohne Eigentümer und Rechte.',
          )
        : t(
              'Eine Transaktion, Binärdaten hexadezimal; die Datei lässt sich in jede Datenbank einspielen.',
          );
});

const restoreHint = computed(() => {
    if (isSqlite.value) {
        return t(
            'Ersetzt den ganzen Inhalt der Datei — ganz oder gar nicht (sqlite3 .restore).',
        );
    }

    if (isPostgres.value) {
        return t(
            'In einer Transaktion: Bei einem Fehler bleibt die Datenbank, wie sie war. Vorhandene Objekte gleichen Namens werden ersetzt.',
        );
    }

    return t(
        'Anweisung für Anweisung: Tabellen gleichen Namens werden ersetzt; bei einem Fehler bleibt, was davor lief.',
    );
});

const selectClass =
    'h-9 w-full min-w-0 rounded-md border bg-background px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50';
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-5 px-4 py-5 sm:px-6">
        <div class="flex flex-col gap-1">
            <Link
                :href="transfersHref(workspace)"
                class="inline-flex items-center gap-1 self-start text-[13px] text-text-secondary hover:text-foreground"
                ><ArrowLeft class="size-3.5" />{{
                    t('Export und Import')
                }}</Link
            >
            <h1 class="text-xl font-semibold">
                {{ t('Sicherung und Wiederherstellung') }}
            </h1>
            <p class="text-sm text-text-secondary">
                {{
                    t(
                        'Mit den Werkzeugen des Servers — vollständig, mit Struktur, Daten und (wo der Server sie kennt) Routinen und Triggern.',
                    )
                }}
            </p>
        </div>

        <div
            class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,360px)]"
        >
            <div class="flex min-w-0 flex-col gap-5">
                <!-- Werkzeuge -->
                <section
                    class="flex flex-col gap-3 rounded-lg border p-4 sm:p-5"
                >
                    <h2
                        class="flex items-center gap-2 text-[15px] font-semibold"
                    >
                        <Wrench class="size-4 text-text-secondary" />{{
                            t('Werkzeuge')
                        }}
                    </h2>
                    <ul class="flex flex-col gap-1.5 text-sm">
                        <li
                            v-for="tool in [
                                ...status.backup,
                                ...status.restore,
                            ]"
                            :key="tool.name"
                            class="flex flex-wrap items-center gap-x-2 gap-y-0.5"
                        >
                            <CircleCheck
                                v-if="tool.found"
                                class="size-4 shrink-0 text-success"
                            />
                            <CircleAlert
                                v-else
                                class="size-4 shrink-0 text-warning"
                            />
                            <span class="font-mono">{{ tool.name }}</span>
                            <span
                                v-if="tool.found"
                                class="text-text-secondary"
                                >{{
                                    tool.version ?? t('Version unbekannt')
                                }}</span
                            >
                            <span
                                v-else
                                class="min-w-0 basis-full text-xs break-words text-text-secondary sm:basis-auto"
                                >{{
                                    t(
                                        'nicht gefunden — installieren oder den Pfad in :key eintragen',
                                        { key: tool.environmentKey },
                                    )
                                }}</span
                            >
                        </li>
                        <li
                            v-if="isSqlite"
                            class="flex items-center gap-2 text-text-secondary"
                        >
                            <CircleCheck class="size-4 shrink-0 text-success" />
                            {{ t('Sichern ohne Werkzeug (VACUUM INTO)') }}
                        </li>
                    </ul>
                    <Notice v-if="isMySql && !status.safeClient" variant="info">
                        {{
                            t(
                                'Der Client kennt keinen Schutz gegen Befehle aus der Datei (mysql ab 8.4 mit --skip-commands, mariadb ab 10.11.8 mit --sandbox). phpdbadmin spielt Sicherungen deshalb selbst ein, Anweisung für Anweisung.',
                            )
                        }}
                    </Notice>
                    <Notice
                        v-if="isPostgres && !status.plainRestore"
                        variant="info"
                    >
                        {{
                            t(
                                'Sicherungen im Textformat spielt phpdbadmin erst mit psql ab Version 18 (bzw. 17.6, 16.10, 15.14, 14.19) ein — das Custom-Format geht immer.',
                            )
                        }}
                    </Notice>
                </section>

                <!-- Sichern -->
                <form
                    class="flex flex-col gap-4 rounded-lg border p-4 sm:p-5"
                    novalidate
                    @submit.prevent="submitBackup"
                >
                    <h2
                        class="flex items-center gap-2 text-[15px] font-semibold"
                    >
                        <HardDriveDownload
                            class="size-4 text-text-secondary"
                        />{{ t('Sicherung erstellen') }}
                    </h2>

                    <div class="flex flex-col gap-1.5">
                        <Label for="backup-database" class="text-[13px]">{{
                            t('Datenbank')
                        }}</Label>
                        <select
                            id="backup-database"
                            v-model="backupForm.database"
                            :class="[selectClass, 'font-mono sm:w-80']"
                            :disabled="databases.length < 2"
                        >
                            <option
                                v-for="database in databases"
                                :key="database"
                                :value="database"
                            >
                                {{ database }}
                            </option>
                        </select>
                        <InputError :message="backupForm.errors.database" />
                    </div>

                    <fieldset v-if="isPostgres" class="flex flex-col gap-2">
                        <legend class="mb-1 text-[13px] font-medium">
                            {{ t('Format') }}
                        </legend>
                        <label class="flex items-start gap-2.5 text-sm">
                            <input
                                v-model="backupForm.pg_format"
                                type="radio"
                                value="custom"
                                class="mt-0.5 size-4 accent-primary"
                            />
                            <span class="flex flex-col gap-0.5">
                                <span>{{ t('Custom-Format (.dump)') }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    t('Gepackt, für pg_restore — empfohlen.')
                                }}</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2.5 text-sm">
                            <input
                                v-model="backupForm.pg_format"
                                type="radio"
                                value="plain"
                                class="mt-0.5 size-4 accent-primary"
                            />
                            <span class="flex flex-col gap-0.5">
                                <span>{{ t('Textformat (.sql)') }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    t('Lesbares SQL für psql.')
                                }}</span>
                            </span>
                        </label>
                    </fieldset>

                    <label
                        v-if="isMySql"
                        class="flex items-start gap-2.5 text-sm"
                    >
                        <input
                            v-model="backupForm.objects"
                            type="checkbox"
                            class="mt-0.5 size-4 accent-primary"
                        />
                        <span class="flex flex-col gap-0.5">
                            <span>{{
                                t('Mit Routinen, Triggern und Ereignissen')
                            }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                t(
                                    'Braucht die Rechte dafür; ein reines Lesekonto sichert besser ohne.',
                                )
                            }}</span>
                        </span>
                    </label>

                    <p class="text-xs text-muted-foreground">
                        {{ backupHint }}
                    </p>

                    <Notice v-if="!mode.background">
                        {{
                            t(
                                'Ohne Hintergrund-Worker läuft die Sicherung in der Anfrage — für Datenbanken bis :mb MB.',
                                { mb: formatNumber(mode.maxMegabytes) },
                            )
                        }}
                    </Notice>

                    <div class="flex border-t pt-4">
                        <Button
                            type="submit"
                            class="sm:ml-auto"
                            :disabled="
                                backupForm.processing ||
                                !status.canBackup ||
                                !backupForm.database
                            "
                        >
                            <Spinner v-if="backupForm.processing" />
                            <HardDriveDownload v-else />{{ t('Sichern') }}
                        </Button>
                    </div>
                </form>

                <!-- Wiederherstellen -->
                <section
                    class="flex flex-col gap-4 rounded-lg border p-4 sm:p-5"
                >
                    <h2
                        class="flex items-center gap-2 text-[15px] font-semibold"
                    >
                        <ArchiveRestore class="size-4 text-text-secondary" />{{
                            t('Wiederherstellen')
                        }}
                    </h2>

                    <Notice v-if="!canWrite" variant="warning">
                        {{
                            workspace.connection.readOnly
                                ? t(
                                      'Diese Verbindung ist schreibgeschützt — Wiederherstellen ist gesperrt.',
                                  )
                                : t(
                                      'Ihr Konto darf über diese Verbindung nicht schreiben — Wiederherstellen ist gesperrt.',
                                  )
                        }}
                    </Notice>

                    <form
                        v-else
                        class="flex flex-col gap-4"
                        novalidate
                        @submit.prevent="submitRestore"
                    >
                        <div class="flex flex-col gap-1.5">
                            <Label for="restore-file" class="text-[13px]">{{
                                t('Sicherung')
                            }}</Label>
                            <input
                                id="restore-file"
                                type="file"
                                :accept="accept"
                                class="block w-full min-w-0 text-sm file:mr-3 file:h-9 file:rounded-md file:border file:bg-background file:px-3 file:text-sm file:font-medium hover:file:bg-muted"
                                @change="chooseFile"
                            />
                            <InputError :message="restoreForm.errors.file" />
                            <p class="text-xs text-muted-foreground">
                                {{
                                    t('Höchstens :mb MB, nicht gepackt.', {
                                        mb: formatNumber(maxUploadMegabytes),
                                    })
                                }}
                            </p>
                        </div>

                        <div v-if="!isSqlite" class="flex flex-col gap-1.5">
                            <Label for="restore-database" class="text-[13px]">{{
                                t('In die Datenbank')
                            }}</Label>
                            <select
                                id="restore-database"
                                v-model="restoreForm.database"
                                :class="[selectClass, 'font-mono sm:w-80']"
                                :disabled="databases.length < 2"
                            >
                                <option
                                    v-for="database in databases"
                                    :key="database"
                                    :value="database"
                                >
                                    {{ database }}
                                </option>
                            </select>
                            <InputError
                                :message="restoreForm.errors.database"
                            />
                        </div>

                        <Notice variant="warning" :icon="TriangleAlert">
                            {{ restoreHint }}
                        </Notice>

                        <label class="flex items-start gap-2.5 text-sm">
                            <input
                                v-model="restoreForm.confirm"
                                type="checkbox"
                                class="mt-0.5 size-4 accent-primary"
                            />
                            <span>{{
                                t(
                                    'Ich weiss, dass die Wiederherstellung Tabellen gleichen Namens in :database ersetzt.',
                                    { database: restoreForm.database },
                                )
                            }}</span>
                        </label>
                        <InputError :message="restoreForm.errors.confirm" />

                        <div
                            v-if="requiresConfirmation"
                            class="flex flex-col gap-2 rounded-lg border border-destructive bg-destructive-soft p-3 text-sm"
                        >
                            <b
                                class="flex items-center gap-1.5 text-destructive"
                                ><TriangleAlert class="size-4" />{{
                                    t('Wiederherstellung auf Produktion')
                                }}</b
                            >
                            <Label for="restore-confirmation">{{
                                t(
                                    'Geben Sie zur Bestätigung den Namen der Verbindung ein: :name',
                                    { name: workspace.connection.name },
                                )
                            }}</Label>
                            <input
                                id="restore-confirmation"
                                v-model="restoreForm.confirmation"
                                type="text"
                                autocomplete="off"
                                class="h-9 rounded-md border bg-background px-2 font-mono"
                            />
                            <InputError
                                :message="restoreForm.errors.confirmation"
                            />
                        </div>

                        <div
                            class="flex flex-col-reverse gap-3 border-t pt-4 sm:flex-row sm:items-center"
                        >
                            <p
                                v-if="restoreForm.progress"
                                class="text-xs text-text-secondary tabular-nums"
                            >
                                {{
                                    t('Hochgeladen: :percent %', {
                                        percent:
                                            restoreForm.progress.percentage ??
                                            0,
                                    })
                                }}
                            </p>
                            <Button
                                type="submit"
                                :variant="
                                    requiresConfirmation
                                        ? 'destructive'
                                        : 'default'
                                "
                                class="sm:ml-auto"
                                :disabled="
                                    restoreForm.processing ||
                                    !status.canRestore ||
                                    !restoreForm.file ||
                                    !restoreForm.confirm ||
                                    (requiresConfirmation &&
                                        restoreForm.confirmation !==
                                            workspace.connection.name)
                                "
                            >
                                <Spinner v-if="restoreForm.processing" />
                                <ArchiveRestore v-else />{{
                                    t('Wiederherstellen')
                                }}
                            </Button>
                        </div>
                    </form>
                </section>
            </div>

            <div class="order-first min-w-0 lg:sticky lg:top-5 lg:order-none">
                <TransferList :transfers="transfers" />
                <p
                    class="mt-2 flex items-center gap-1.5 px-1 text-xs text-muted-foreground"
                >
                    <Database class="size-3.5" />{{
                        t(
                            'Nur Ihre eigenen Aufträge; Dateien werden nach 24 Stunden gelöscht.',
                        )
                    }}
                </p>
            </div>
        </div>
    </div>
</template>
