<script setup lang="ts">
import { Head, Link, router, useForm, useHttp } from '@inertiajs/vue3';
import {
    Check,
    ChevronRight,
    Database,
    Eye,
    EyeOff,
    KeyRound,
    Zap,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EnvironmentMark from '@/components/connections/EnvironmentMark.vue';
import TestPanel from '@/components/connections/TestPanel.vue';
import InputError from '@/components/InputError.vue';
import Interpolate from '@/components/Interpolate.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { home } from '@/routes';
import { store, test, update } from '@/routes/connections';
import { destroy as forgetHostKey } from '@/routes/connections/ssh-host-key';
import type {
    ConnectionEnvironment,
    ConnectionTestResult,
    DatabaseDriver,
    DriverOption,
    EditableConnection,
    EnvironmentOption,
} from '@/types';

/** Verbindung anlegen und bearbeiten (V-01 bis V-05, Wireframe 04). */
const props = defineProps<{
    connection: EditableConnection | null;
    drivers: DriverOption[];
    environments: EnvironmentOption[];
    allowedHosts: string[];
    sqliteFolders: string[];
    sshKeyFolders: string[];
    groups: string[];
}>();

const form = useForm({
    name: props.connection?.name ?? '',
    group: props.connection?.group ?? '',
    driver: (props.connection?.driver ?? 'mariadb') as DatabaseDriver,
    host: props.connection?.host ?? '127.0.0.1',
    port: props.connection?.port?.toString() ?? '',
    database: props.connection?.database ?? '',
    // Neue Verbindungen zeigen zuerst alle Datenbanken (bequem z. B. für Herd); beim Bearbeiten wie gespeichert.
    all_databases: props.connection?.allDatabases ?? true,
    username: props.connection?.username ?? '',
    password: '',
    store_password: props.connection?.storePassword ?? true,
    sslmode: props.connection?.sslmode ?? 'disable',
    sqlite_path: props.connection?.sqlitePath ?? '',
    environment: (props.connection?.environment ??
        'local') as ConnectionEnvironment,
    read_only: props.connection?.readOnly ?? false,
    // SSH-Tunnel (V-08): Schlüssel und Passphrase sind beim Bearbeiten leer — leer heisst «behalten».
    ssh: props.connection?.ssh.enabled ?? false,
    ssh_host: props.connection?.ssh.host ?? '',
    ssh_port: props.connection?.ssh.port?.toString() ?? '',
    ssh_username: props.connection?.ssh.username ?? '',
    ssh_key: '',
    ssh_key_path: props.connection?.ssh.keyPath ?? '',
    ssh_passphrase: '',
});

/** Schlüssel einfügen oder als Datei auf dem Server — nur eines davon geht an den Server. */
const keySource = ref<'paste' | 'path'>(
    props.connection?.ssh.keyPath ? 'path' : 'paste',
);

type TestData = ReturnType<typeof form.data> & { connection: string | null };

const http = useHttp<TestData, ConnectionTestResult>({
    ...form.data(),
    connection: props.connection?.id ?? null,
});
const result = ref<ConnectionTestResult | null>(null);

const hostKey = computed(
    () => result.value?.hostKey ?? props.connection?.ssh.hostKey ?? null,
);
const showPassword = ref(false);

const driver = computed(() =>
    props.drivers.find((option) => option.value === form.driver)!,
);
const isServer = computed(() => form.driver !== 'sqlite');
const errors = computed<Record<string, string | undefined>>(() => ({
    ...http.errors,
    ...form.errors,
}));

function chooseDriver(value: DatabaseDriver): void {
    form.driver = value;
    const modes = props.drivers.find(
        (option) => option.value === value,
    )!.sslModes;

    if (!modes.some((mode) => mode.value === form.sslmode)) {
        form.sslmode = modes[0]?.value ?? 'disable';
    }

    if (value === 'pgsql' && form.database === '' && !form.all_databases) {
        form.database = 'postgres';
    }
}

// «Alle Datenbanken anzeigen»: Feld leeren und sperren; ohne Haken braucht es einen Namen.
function toggleAllDatabases(checked: boolean): void {
    form.all_databases = checked;

    if (checked) {
        form.database = '';
    } else if (form.driver === 'pgsql') {
        form.database = 'postgres';
    }
}

function chooseEnvironment(value: ConnectionEnvironment): void {
    form.environment = value;
    form.read_only =
        props.environments.find((option) => option.value === value)
            ?.readOnlyByDefault ?? false;
}

/** Nur die gewählte Quelle des Schlüssels geht mit; ohne Tunnel keine SSH-Angaben. */
function payload(): ReturnType<typeof form.data> {
    const data = form.data();

    if (!data.ssh || data.driver === 'sqlite') {
        return {
            ...data,
            ssh: false,
            ssh_key: '',
            ssh_key_path: '',
            ssh_passphrase: '',
        };
    }

    return keySource.value === 'paste'
        ? { ...data, ssh_key_path: '' }
        : { ...data, ssh_key: '' };
}

async function runTest(): Promise<void> {
    http.clearErrors();

    try {
        result.value = await http
            .transform(() => ({
                ...payload(),
                connection: props.connection?.id ?? null,
            }))
            .post(test.url());
    } catch {
        result.value = null;
    }
}

function submit(): void {
    form.transform(() => payload());

    if (props.connection) {
        form.put(update.url(props.connection.id));
    } else {
        form.post(store.url());
    }
}

function forgetKey(): void {
    if (
        props.connection &&
        window.confirm(
            t(
                'Host-Schlüssel vergessen? Beim nächsten Verbinden nimmt phpdbadmin den Schlüssel an, den der Server dann zeigt.',
            ),
        )
    ) {
        router.delete(forgetHostKey.url(props.connection.id), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head
        :title="
            connection ? t('Verbindung bearbeiten') : t('Verbindung anlegen')
        "
    />

    <form
        class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6 sm:px-8"
        novalidate
        @submit.prevent="submit"
    >
        <div class="flex flex-col gap-1">
            <nav
                class="flex items-center gap-1 text-xs text-text-secondary"
                :aria-label="t('Pfad')"
            >
                <Link :href="home()" class="hover:text-foreground">{{
                    t('Verbindungen')
                }}</Link>
                <ChevronRight class="size-3.5" />
                <span>{{ connection ? connection.name : t('Neu') }}</span>
            </nav>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold">
                    {{
                        connection
                            ? t('Verbindung bearbeiten')
                            : t('Verbindung anlegen')
                    }}
                </h1>
                <div class="ml-auto hidden gap-2 sm:flex">
                    <Button variant="ghost" as-child>
                        <Link :href="home()">{{ t('Abbrechen') }}</Link>
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="http.processing"
                        @click="runTest"
                    >
                        <Spinner v-if="http.processing" /><Zap v-else />
                        {{ t('Verbindung testen') }}
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />{{ t('Speichern') }}
                    </Button>
                </div>
            </div>
        </div>

        <div
            class="grid gap-6 lg:grid-cols-[minmax(0,720px)_minmax(0,1fr)] lg:gap-8"
        >
            <div class="flex min-w-0 flex-col gap-6">
                <fieldset class="flex flex-col gap-1.5">
                    <legend class="mb-1.5 text-[13px] font-medium">
                        {{ t('Treiber') }}
                    </legend>
                    <div
                        class="grid grid-cols-2 gap-2 sm:grid-cols-4"
                        role="radiogroup"
                        :aria-label="t('Treiber')"
                    >
                        <button
                            v-for="option in drivers"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.driver === option.value"
                            :class="[
                                'relative flex min-w-0 flex-col gap-1 rounded-lg border p-3 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                form.driver === option.value
                                    ? 'border-2 border-primary bg-accent p-[11px]'
                                    : 'hover:bg-muted',
                            ]"
                            @click="chooseDriver(option.value)"
                        >
                            <span
                                v-if="form.driver === option.value"
                                class="absolute top-2.5 right-2.5 grid size-[18px] place-items-center rounded-full bg-primary text-primary-foreground"
                                ><Check class="size-3" :stroke-width="3"
                            /></span>
                            <span class="flex items-center gap-2 font-semibold"
                                ><Database class="size-4" />{{
                                    option.label
                                }}</span
                            >
                            <span class="text-xs text-muted-foreground">{{
                                t('ab :version', {
                                    version: option.minimumVersion,
                                })
                            }}</span>
                        </button>
                    </div>
                    <InputError :message="errors.driver" />
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="name" class="text-[13px]">{{
                            t('Name')
                        }}</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            required
                            maxlength="255"
                            :aria-invalid="errors.name ? true : undefined"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="group" class="text-[13px]">{{
                            t('Gruppe')
                        }}</Label>
                        <Input
                            id="group"
                            v-model="form.group"
                            list="connection-groups"
                            maxlength="255"
                            :placeholder="t('z. B. Lokal (Herd)')"
                        />
                        <datalist id="connection-groups">
                            <option
                                v-for="group in groups"
                                :key="group"
                                :value="group"
                            />
                        </datalist>
                        <InputError :message="errors.group" />
                    </div>
                </div>

                <fieldset class="flex flex-col gap-1.5">
                    <legend class="mb-1.5 text-[13px] font-medium">
                        {{ t('Umgebung') }}
                    </legend>
                    <div class="flex flex-wrap items-center gap-3">
                        <div
                            class="inline-flex flex-wrap overflow-hidden rounded-md border"
                            role="radiogroup"
                            :aria-label="t('Umgebung')"
                        >
                            <button
                                v-for="option in environments"
                                :key="option.value"
                                type="button"
                                role="radio"
                                :aria-checked="
                                    form.environment === option.value
                                "
                                :class="[
                                    'border-r px-3 py-[7px] last:border-r-0',
                                    form.environment === option.value
                                        ? option.value === 'production'
                                            ? 'bg-destructive-soft shadow-[inset_0_-2px_0_var(--env-production)]'
                                            : option.value === 'test'
                                              ? 'bg-muted shadow-[inset_0_-2px_0_var(--env-test)]'
                                              : 'bg-muted shadow-[inset_0_-2px_0_var(--env-local)]'
                                        : 'hover:bg-muted',
                                ]"
                                @click="chooseEnvironment(option.value)"
                            >
                                <EnvironmentMark :environment="option.value" />
                            </button>
                        </div>
                        <span
                            v-if="form.environment === 'production'"
                            class="max-w-xs text-xs text-muted-foreground"
                            >{{
                                t(
                                    'Produktion: rote Kennzeichnung, Schreibschutz eingeschaltet.',
                                )
                            }}</span
                        >
                    </div>
                </fieldset>

                <template v-if="isServer">
                    <div class="grid grid-cols-[minmax(0,1fr)_120px] gap-4">
                        <div class="flex min-w-0 flex-col gap-1.5">
                            <Label for="host" class="text-[13px]">{{
                                form.ssh
                                    ? t('Host aus Sicht des SSH-Servers')
                                    : t('Host')
                            }}</Label>
                            <Input
                                id="host"
                                v-model="form.host"
                                class="font-mono"
                                required
                                autocomplete="off"
                                spellcheck="false"
                                :aria-invalid="errors.host ? true : undefined"
                                aria-describedby="host-hint"
                            />
                            <InputError :message="errors.host" />
                            <p
                                id="host-hint"
                                class="text-xs text-muted-foreground"
                            >
                                {{ t('Erlaubt in dieser Installation:') }}
                                <code
                                    v-for="(entry, index) in allowedHosts"
                                    :key="entry"
                                    >{{ entry
                                    }}{{
                                        index < allowedHosts.length - 1
                                            ? ', '
                                            : ''
                                    }}</code
                                >
                            </p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="port" class="text-[13px]">{{
                                t('Port')
                            }}</Label>
                            <Input
                                id="port"
                                v-model="form.port"
                                class="font-mono"
                                inputmode="numeric"
                                :placeholder="driver.defaultPort?.toString()"
                                :aria-invalid="errors.port ? true : undefined"
                            />
                            <InputError :message="errors.port" />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="username" class="text-[13px]">{{
                                t('Benutzer')
                            }}</Label>
                            <Input
                                id="username"
                                v-model="form.username"
                                class="font-mono"
                                required
                                autocomplete="off"
                                spellcheck="false"
                                :aria-invalid="
                                    errors.username ? true : undefined
                                "
                            />
                            <InputError :message="errors.username" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="password" class="text-[13px]">{{
                                t('Passwort')
                            }}</Label>
                            <div class="relative">
                                <Input
                                    id="password"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    class="pr-9"
                                    autocomplete="new-password"
                                    :placeholder="
                                        connection?.hasPassword
                                            ? t(
                                                  'gespeichert — leer lassen zum Behalten',
                                              )
                                            : ''
                                    "
                                    :aria-invalid="
                                        errors.password ? true : undefined
                                    "
                                />
                                <button
                                    type="button"
                                    class="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
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
                        </div>
                    </div>

                    <label class="flex items-start gap-2.5 text-sm">
                        <input
                            type="checkbox"
                            class="mt-0.5 size-4 accent-primary"
                            :checked="!form.store_password"
                            @change="
                                form.store_password = !(
                                    $event.target as HTMLInputElement
                                ).checked
                            "
                        />
                        <span class="flex flex-col gap-1">
                            <span>{{
                                t(
                                    'Passwort nicht speichern — in jeder Sitzung fragen',
                                )
                            }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                t(
                                    'Gespeicherte Passwörter liegen verschlüsselt in der App-Datenbank und erreichen nie den Browser.',
                                )
                            }}</span>
                        </span>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="database" class="text-[13px]">{{
                                t('Datenbank')
                            }}</Label>
                            <Input
                                id="database"
                                v-model="form.database"
                                class="font-mono"
                                autocomplete="off"
                                spellcheck="false"
                                :disabled="form.all_databases"
                                :placeholder="
                                    form.all_databases
                                        ? t('alle Datenbanken')
                                        : ''
                                "
                                :required="!form.all_databases"
                                :aria-invalid="
                                    errors.database ? true : undefined
                                "
                                aria-describedby="all-databases-hint"
                            />
                            <label class="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    class="mt-0.5 size-4 accent-primary"
                                    :checked="form.all_databases"
                                    @change="
                                        toggleAllDatabases(
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                                <span class="flex flex-col gap-0.5">
                                    <span>{{
                                        t('Alle Datenbanken anzeigen')
                                    }}</span>
                                    <span
                                        id="all-databases-hint"
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            form.driver === 'pgsql'
                                                ? t(
                                                      'Verbindet zuerst mit «postgres» und listet alle Datenbanken des Servers.',
                                                  )
                                                : t(
                                                      'Der Navigator zeigt jede Datenbank, die das Konto sieht.',
                                                  )
                                        }}</span
                                    >
                                </span>
                            </label>
                            <InputError :message="errors.database" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="sslmode" class="text-[13px]">{{
                                t('SSL')
                            }}</Label>
                            <select
                                id="sslmode"
                                v-model="form.sslmode"
                                class="h-9 rounded-md border bg-background px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            >
                                <option
                                    v-for="mode in driver.sslModes"
                                    :key="mode.value"
                                    :value="mode.value"
                                >
                                    {{ mode.label }}
                                </option>
                            </select>
                            <InputError :message="errors.sslmode" />
                        </div>
                    </div>
                </template>

                <div v-else class="flex flex-col gap-1.5">
                    <Label for="sqlite_path" class="text-[13px]">{{
                        t('Datei')
                    }}</Label>
                    <Input
                        id="sqlite_path"
                        v-model="form.sqlite_path"
                        class="font-mono"
                        required
                        spellcheck="false"
                        :placeholder="`${sqliteFolders[0] ?? '~'}/notizen.sqlite`"
                        :aria-invalid="errors.sqlite_path ? true : undefined"
                        aria-describedby="sqlite-hint"
                    />
                    <InputError :message="errors.sqlite_path" />
                    <p id="sqlite-hint" class="text-xs text-muted-foreground">
                        <Interpolate
                            :text="
                                t(
                                    'Nur Dateien in erlaubten Ordnern: :folders. Andere Pfade, :parent und Verknüpfungen nach draussen lehnt phpdbadmin ab.',
                                )
                            "
                        >
                            <template #folders
                                ><code
                                    v-for="(folder, index) in sqliteFolders"
                                    :key="folder"
                                    >{{ folder
                                    }}{{
                                        index < sqliteFolders.length - 1
                                            ? ', '
                                            : ''
                                    }}</code
                                ></template
                            >
                            <template #parent><code>..</code></template>
                        </Interpolate>
                    </p>
                </div>

                <fieldset
                    v-if="isServer"
                    class="flex min-w-0 flex-col gap-4 rounded-lg border p-4"
                >
                    <legend class="px-1 text-[13px] font-medium">
                        {{ t('SSH-Tunnel') }}
                    </legend>
                    <label class="flex items-start gap-2.5 text-sm">
                        <input
                            v-model="form.ssh"
                            type="checkbox"
                            class="mt-0.5 size-4 accent-primary"
                        />
                        <span class="flex flex-col gap-0.5">
                            <span>{{
                                t('Über einen SSH-Tunnel verbinden')
                            }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                t(
                                    'Host und Port oben gelten dann aus Sicht des SSH-Servers — oft 127.0.0.1. Der SSH-Server muss in dieser Installation erlaubt sein.',
                                )
                            }}</span>
                        </span>
                    </label>

                    <template v-if="form.ssh">
                        <div class="grid grid-cols-[minmax(0,1fr)_120px] gap-4">
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <Label for="ssh_host" class="text-[13px]">{{
                                    t('SSH-Host')
                                }}</Label>
                                <Input
                                    id="ssh_host"
                                    v-model="form.ssh_host"
                                    class="font-mono"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :aria-invalid="
                                        errors.ssh_host ? true : undefined
                                    "
                                />
                                <InputError :message="errors.ssh_host" />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <Label for="ssh_port" class="text-[13px]">{{
                                    t('SSH-Port')
                                }}</Label>
                                <Input
                                    id="ssh_port"
                                    v-model="form.ssh_port"
                                    class="font-mono"
                                    inputmode="numeric"
                                    placeholder="22"
                                    :aria-invalid="
                                        errors.ssh_port ? true : undefined
                                    "
                                />
                                <InputError :message="errors.ssh_port" />
                            </div>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="ssh_username" class="text-[13px]">{{
                                t('SSH-Benutzer')
                            }}</Label>
                            <Input
                                id="ssh_username"
                                v-model="form.ssh_username"
                                class="font-mono"
                                autocomplete="off"
                                spellcheck="false"
                                :aria-invalid="
                                    errors.ssh_username ? true : undefined
                                "
                            />
                            <InputError :message="errors.ssh_username" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <span class="text-[13px] font-medium">{{
                                t('Privater Schlüssel')
                            }}</span>
                            <div
                                class="inline-flex w-fit flex-wrap overflow-hidden rounded-md border"
                                role="radiogroup"
                                :aria-label="t('Privater Schlüssel')"
                            >
                                <button
                                    type="button"
                                    role="radio"
                                    :aria-checked="keySource === 'paste'"
                                    :class="[
                                        'border-r px-3 py-[7px] text-sm',
                                        keySource === 'paste'
                                            ? 'bg-muted font-medium shadow-[inset_0_-2px_0_var(--primary)]'
                                            : 'hover:bg-muted',
                                    ]"
                                    @click="keySource = 'paste'"
                                >
                                    {{ t('Einfügen') }}
                                </button>
                                <button
                                    type="button"
                                    role="radio"
                                    :aria-checked="keySource === 'path'"
                                    :class="[
                                        'px-3 py-[7px] text-sm',
                                        keySource === 'path'
                                            ? 'bg-muted font-medium shadow-[inset_0_-2px_0_var(--primary)]'
                                            : 'hover:bg-muted',
                                    ]"
                                    @click="keySource = 'path'"
                                >
                                    {{ t('Datei auf dem Server') }}
                                </button>
                            </div>
                            <template v-if="keySource === 'paste'">
                                <textarea
                                    id="ssh_key"
                                    v-model="form.ssh_key"
                                    rows="4"
                                    class="min-h-24 w-full rounded-md border bg-background px-2.5 py-2 font-mono text-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :aria-label="t('Privater Schlüssel')"
                                    :placeholder="
                                        connection?.ssh.hasKey
                                            ? t(
                                                  'gespeichert — leer lassen zum Behalten',
                                              )
                                            : '-----BEGIN OPENSSH PRIVATE KEY-----'
                                    "
                                    :aria-invalid="
                                        errors.ssh_key ? true : undefined
                                    "
                                    aria-describedby="ssh-key-hint"
                                />
                                <p
                                    id="ssh-key-hint"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{
                                        t(
                                            'Liegt verschlüsselt in der App-Datenbank und erreicht nie wieder den Browser. Für ssh entsteht nur kurz eine Datei, die nach der Anmeldung gelöscht wird.',
                                        )
                                    }}
                                </p>
                                <InputError :message="errors.ssh_key" />
                            </template>
                            <template v-else>
                                <Input
                                    id="ssh_key_path"
                                    v-model="form.ssh_key_path"
                                    class="font-mono"
                                    spellcheck="false"
                                    :aria-label="t('Schlüsseldatei')"
                                    :placeholder="`${sshKeyFolders[0] ?? '~/.ssh'}/id_ed25519`"
                                    :aria-invalid="
                                        errors.ssh_key_path ? true : undefined
                                    "
                                    aria-describedby="ssh-key-path-hint"
                                />
                                <p
                                    id="ssh-key-path-hint"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ t('Nur Dateien in erlaubten Ordnern:') }}
                                    <code
                                        v-for="(folder, index) in sshKeyFolders"
                                        :key="folder"
                                        >{{ folder
                                        }}{{
                                            index < sshKeyFolders.length - 1
                                                ? ', '
                                                : ''
                                        }}</code
                                    >
                                </p>
                                <InputError :message="errors.ssh_key_path" />
                            </template>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="ssh_passphrase" class="text-[13px]">{{
                                t('Passphrase')
                            }}</Label>
                            <Input
                                id="ssh_passphrase"
                                v-model="form.ssh_passphrase"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="
                                    connection?.ssh.hasPassphrase
                                        ? t(
                                              'gespeichert — leer lassen zum Behalten',
                                          )
                                        : t('nur, wenn der Schlüssel eine hat')
                                "
                                :aria-invalid="
                                    errors.ssh_passphrase ? true : undefined
                                "
                            />
                            <InputError :message="errors.ssh_passphrase" />
                        </div>

                        <div
                            class="flex flex-col gap-1.5 rounded-md bg-muted px-3 py-2.5 text-[13px]"
                            aria-live="polite"
                        >
                            <span class="flex items-center gap-1.5 font-medium"
                                ><KeyRound class="size-3.5" />{{
                                    t('Host-Schlüssel des SSH-Servers')
                                }}</span
                            >
                            <code
                                v-if="hostKey"
                                class="font-mono text-xs break-all"
                                >{{ hostKey }}</code
                            >
                            <span v-else class="text-xs text-text-secondary">{{
                                t(
                                    'Noch keiner gespeichert. phpdbadmin nimmt ihn beim ersten Verbinden an und prüft ihn danach — vergleichen Sie den Fingerabdruck nach dem Test mit dem des Servers.',
                                )
                            }}</span>
                            <Button
                                v-if="connection?.ssh.hostKey"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="w-fit"
                                @click="forgetKey"
                                >{{ t('Host-Schlüssel vergessen') }}</Button
                            >
                        </div>
                    </template>
                </fieldset>

                <label class="flex items-start gap-3 text-sm">
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="form.read_only"
                        :class="[
                            'mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full border-2 border-transparent transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            form.read_only ? 'bg-primary' : 'bg-surface-strong',
                        ]"
                        @click="form.read_only = !form.read_only"
                    >
                        <span
                            :class="[
                                'size-4 rounded-full bg-background shadow transition-transform',
                                form.read_only
                                    ? 'translate-x-4'
                                    : 'translate-x-0',
                            ]"
                        />
                    </button>
                    <span class="flex flex-col gap-1">
                        <b class="font-semibold">{{ t('Schreibgeschützt') }}</b>
                        <span class="text-xs text-muted-foreground">{{
                            t(
                                'Nur lesende Anweisungen — in mehreren Schichten durchgesetzt. Für Produktion vorgegeben.',
                            )
                        }}</span>
                    </span>
                </label>
            </div>

            <TestPanel
                :result="result"
                :testing="http.processing"
                :production="form.environment === 'production'"
            />
        </div>

        <div class="flex flex-col gap-2 sm:hidden">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />{{ t('Speichern') }}
            </Button>
            <Button
                type="button"
                variant="outline"
                :disabled="http.processing"
                @click="runTest"
            >
                <Spinner v-if="http.processing" /><Zap v-else />
                {{ t('Verbindung testen') }}
            </Button>
        </div>
    </form>
</template>
