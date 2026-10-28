<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { Check, ChevronRight, Database, Eye, EyeOff, Zap } from '@lucide/vue';
import { computed, ref } from 'vue';
import EnvironmentMark from '@/components/connections/EnvironmentMark.vue';
import TestPanel from '@/components/connections/TestPanel.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { home } from '@/routes';
import { store, test, update } from '@/routes/connections';
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
    groups: string[];
}>();

const form = useForm({
    name: props.connection?.name ?? '',
    group: props.connection?.group ?? '',
    driver: (props.connection?.driver ?? 'mariadb') as DatabaseDriver,
    host: props.connection?.host ?? '127.0.0.1',
    port: props.connection?.port?.toString() ?? '',
    database: props.connection?.database ?? '',
    username: props.connection?.username ?? '',
    password: '',
    store_password: props.connection?.storePassword ?? true,
    sslmode: props.connection?.sslmode ?? 'disable',
    sqlite_path: props.connection?.sqlitePath ?? '',
    environment: (props.connection?.environment ??
        'local') as ConnectionEnvironment,
    read_only: props.connection?.readOnly ?? false,
});

type TestData = ReturnType<typeof form.data> & { connection: string | null };

const http = useHttp<TestData, ConnectionTestResult>({
    ...form.data(),
    connection: props.connection?.id ?? null,
});
const result = ref<ConnectionTestResult | null>(null);
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

    if (value === 'pgsql' && form.database === '') {
        form.database = 'postgres';
    }
}

function chooseEnvironment(value: ConnectionEnvironment): void {
    form.environment = value;
    form.read_only =
        props.environments.find((option) => option.value === value)
            ?.readOnlyByDefault ?? false;
}

async function runTest(): Promise<void> {
    http.clearErrors();

    try {
        result.value = await http
            .transform(() => ({
                ...form.data(),
                connection: props.connection?.id ?? null,
            }))
            .post(test.url());
    } catch {
        result.value = null;
    }
}

function submit(): void {
    if (props.connection) {
        form.put(update.url(props.connection.id));
    } else {
        form.post(store.url());
    }
}
</script>

<template>
    <Head
        :title="connection ? 'Verbindung bearbeiten' : 'Verbindung anlegen'"
    />

    <form
        class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6 sm:px-8"
        novalidate
        @submit.prevent="submit"
    >
        <div class="flex flex-col gap-1">
            <nav
                class="flex items-center gap-1 text-xs text-text-secondary"
                aria-label="Pfad"
            >
                <Link :href="home()" class="hover:text-foreground"
                    >Verbindungen</Link
                >
                <ChevronRight class="size-3.5" />
                <span>{{ connection ? connection.name : 'Neu' }}</span>
            </nav>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold">
                    {{
                        connection
                            ? 'Verbindung bearbeiten'
                            : 'Verbindung anlegen'
                    }}
                </h1>
                <div class="ml-auto hidden gap-2 sm:flex">
                    <Button variant="ghost" as-child>
                        <Link :href="home()">Abbrechen</Link>
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="http.processing"
                        @click="runTest"
                    >
                        <Spinner v-if="http.processing" /><Zap v-else />
                        Verbindung testen
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />Speichern
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
                        Treiber
                    </legend>
                    <div
                        class="grid grid-cols-2 gap-2 sm:grid-cols-4"
                        role="radiogroup"
                        aria-label="Treiber"
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
                            <span class="text-xs text-muted-foreground"
                                >ab {{ option.minimumVersion }}</span
                            >
                        </button>
                    </div>
                    <InputError :message="errors.driver" />
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="name" class="text-[13px]">Name</Label>
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
                        <Label for="group" class="text-[13px]">Gruppe</Label>
                        <Input
                            id="group"
                            v-model="form.group"
                            list="connection-groups"
                            maxlength="255"
                            placeholder="z. B. Lokal (Herd)"
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
                        Umgebung
                    </legend>
                    <div class="flex flex-wrap items-center gap-3">
                        <div
                            class="inline-flex flex-wrap overflow-hidden rounded-md border"
                            role="radiogroup"
                            aria-label="Umgebung"
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
                            >Produktion: rote Kennzeichnung, Schreibschutz
                            eingeschaltet.</span
                        >
                    </div>
                </fieldset>

                <template v-if="isServer">
                    <div class="grid grid-cols-[minmax(0,1fr)_120px] gap-4">
                        <div class="flex min-w-0 flex-col gap-1.5">
                            <Label for="host" class="text-[13px]">Host</Label>
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
                                Erlaubt in dieser Installation:
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
                            <Label for="port" class="text-[13px]">Port</Label>
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
                            <Label for="username" class="text-[13px]"
                                >Benutzer</Label
                            >
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
                            <Label for="password" class="text-[13px]"
                                >Passwort</Label
                            >
                            <div class="relative">
                                <Input
                                    id="password"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    class="pr-9"
                                    autocomplete="new-password"
                                    :placeholder="
                                        connection?.hasPassword
                                            ? 'gespeichert — leer lassen zum Behalten'
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
                                            ? 'Passwort verbergen'
                                            : 'Passwort zeigen'
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
                            <span
                                >Passwort nicht speichern — in jeder Sitzung
                                fragen</span
                            >
                            <span class="text-xs text-muted-foreground"
                                >Gespeicherte Passwörter liegen verschlüsselt in
                                der App-Datenbank und erreichen nie den
                                Browser.</span
                            >
                        </span>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="database" class="text-[13px]"
                                >Datenbank<span
                                    v-if="form.driver !== 'pgsql'"
                                    class="font-normal text-muted-foreground"
                                >
                                    (optional)</span
                                ></Label
                            >
                            <Input
                                id="database"
                                v-model="form.database"
                                class="font-mono"
                                autocomplete="off"
                                spellcheck="false"
                                :aria-invalid="
                                    errors.database ? true : undefined
                                "
                            />
                            <InputError :message="errors.database" />
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="sslmode" class="text-[13px]">SSL</Label>
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
                    <Label for="sqlite_path" class="text-[13px]">Datei</Label>
                    <Input
                        id="sqlite_path"
                        v-model="form.sqlite_path"
                        class="font-mono"
                        required
                        spellcheck="false"
                        placeholder="~/Datenbanken/notizen.sqlite"
                        :aria-invalid="errors.sqlite_path ? true : undefined"
                        aria-describedby="sqlite-hint"
                    />
                    <InputError :message="errors.sqlite_path" />
                    <p id="sqlite-hint" class="text-xs text-muted-foreground">
                        Nur Dateien in erlaubten Ordnern:
                        <code
                            v-for="(folder, index) in sqliteFolders"
                            :key="folder"
                            >{{ folder
                            }}{{
                                index < sqliteFolders.length - 1 ? ', ' : ''
                            }}</code
                        >. Andere Pfade, <code>..</code> und Verknüpfungen nach
                        draussen lehnt phpdbadmin ab.
                    </p>
                </div>

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
                        <b class="font-semibold">Schreibgeschützt</b>
                        <span class="text-xs text-muted-foreground"
                            >Nur lesende Anweisungen — in mehreren Schichten
                            durchgesetzt. Für Produktion vorgegeben.</span
                        >
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
                <Spinner v-if="form.processing" />Speichern
            </Button>
            <Button
                type="button"
                variant="outline"
                :disabled="http.processing"
                @click="runTest"
            >
                <Spinner v-if="http.processing" /><Zap v-else />
                Verbindung testen
            </Button>
        </div>
    </form>
</template>
