<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    DatabaseZap,
    FilePlus2,
    Lock,
    Plus,
    Trash2,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import ChangeDialog from '@/components/workspace/schema/ChangeDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useSchemaChange } from '@/composables/useSchemaChange';
import { getJson } from '@/lib/api';
import { t } from '@/lib/i18n';
import { structureBlock } from '@/lib/workspace';
import { databaseField, newTableHref, schemaUrls } from '@/lib/schema';
import { create as newConnection } from '@/routes/connections';
import type { SchemaPayload, Workspace } from '@/types';

/**
 * Oben auf der Schema-Seite (S-02, S-05): «Tabelle anlegen» und ein Menü, um eine Datenbank (MySQL, MariaDB) bzw. ein
 * Schema (PostgreSQL) anzulegen oder das gezeigte zu löschen; bei SQLite eine neue Datei in einem erlaubten Ordner.
 * Löschen nur mit dem eingetippten Namen. Ohne Recht «schreiben» oder bei Schreibschutz gesperrt, mit Grund.
 */
const props = defineProps<{
    workspace: Workspace;
}>();

type Operation = 'create' | 'drop';

const isSqlite = computed(() => props.workspace.connection.driver === 'sqlite');
const isSchema = computed(() => props.workspace.schemaKind === 'schema');
const operation = ref<Operation>('create');
const open = ref(false);
const name = ref('');
const folder = ref('');
const file = ref('');
const folders = ref<string[]>([]);
const urls = computed(() => schemaUrls(props.workspace));

const change = useSchemaChange({
    previewUrl: () => urls.value.preview,
    applyUrl: () => urls.value.apply,
    payload: (): SchemaPayload => {
        if (isSqlite.value) {
            return {
                operation: 'create',
                folder: folder.value,
                file: file.value.trim(),
            };
        }

        return {
            operation: operation.value,
            name: name.value.trim(),
            ...databaseField(props.workspace),
        };
    },
});
const { plan, loading, failure, errors, message, processing } = change;

const blocked = computed(() => structureBlock(props.workspace.connection));

async function start(next: Operation): Promise<void> {
    operation.value = next;
    name.value = next === 'drop' ? (props.workspace.schema ?? '') : '';
    file.value = '';
    change.reset();
    open.value = true;

    if (isSqlite.value) {
        const response = await getJson<{ folders: string[] }>(
            urls.value.folders,
        ).catch(() => ({ folders: [] }));
        folders.value = response.folders;
        folder.value = response.folders[0] ?? '';
    }

    if (next === 'drop') {
        void change.refresh();
    }
}

watchDebounced(
    [name, folder, file],
    () => {
        if (!open.value || operation.value === 'drop') {
            return;
        }

        if ((isSqlite.value ? file.value : name.value).trim() === '') {
            change.reset();

            return;
        }

        void change.refresh();
    },
    { debounce: 350 },
);

const dialog = computed(() => {
    if (isSqlite.value) {
        return { title: t('Neue SQLite-Datei'), apply: t('Datei anlegen') };
    }

    if (operation.value === 'create') {
        return isSchema.value
            ? { title: t('Schema anlegen'), apply: t('Schema anlegen') }
            : { title: t('Datenbank anlegen'), apply: t('Datenbank anlegen') };
    }

    return isSchema.value
        ? { title: t('Schema löschen'), apply: t('Schema löschen') }
        : { title: t('Datenbank löschen'), apply: t('Datenbank löschen') };
});

function apply(confirmation: {
    confirmName: string;
    confirmation: string;
}): void {
    change.apply(confirmation, () => {
        open.value = false;
    });
}

// Nach dem Anlegen einer SQLite-Datei: der Weg zur neuen Verbindung.
const page = usePage();
const createdFile = ref<string | null>(null);
watch(
    () => (page.flash as { sqliteFile?: string } | undefined)?.sqliteFile,
    (value) => {
        if (value) {
            createdFile.value = value;
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <template v-if="workspace.schema">
                <Button v-if="!blocked" as-child size="sm">
                    <Link :href="newTableHref(workspace, workspace.schema)">
                        <Plus />{{ t('Tabelle anlegen') }}
                    </Link>
                </Button>
                <Button v-else size="sm" disabled :title="blocked">
                    <Plus />{{ t('Tabelle anlegen') }}
                </Button>
            </template>

            <DropdownMenu>
                <DropdownMenuTrigger as-child :disabled="!!blocked">
                    <Button
                        variant="outline"
                        size="sm"
                        :title="blocked ?? undefined"
                    >
                        {{
                            isSqlite
                                ? t('Datei')
                                : isSchema
                                  ? t('Schema')
                                  : t('Datenbank')
                        }}<ChevronDown />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-60">
                    <DropdownMenuItem v-if="isSqlite" @select="start('create')">
                        <FilePlus2 />{{ t('Neue SQLite-Datei …') }}
                    </DropdownMenuItem>
                    <template v-else>
                        <DropdownMenuItem @select="start('create')">
                            <DatabaseZap />{{
                                isSchema
                                    ? t('Schema anlegen …')
                                    : t('Datenbank anlegen …')
                            }}
                        </DropdownMenuItem>
                        <template v-if="workspace.schema">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                @select="start('drop')"
                            >
                                <Trash2 />{{
                                    t(':name löschen …', {
                                        name: workspace.schema,
                                    })
                                }}
                            </DropdownMenuItem>
                        </template>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>

            <span
                v-if="blocked"
                class="inline-flex items-center gap-1.5 text-[13px] text-text-secondary"
            >
                <Lock class="size-3.5" />{{ blocked }}
            </span>
        </div>

        <Notice v-if="createdFile" variant="success">
            {{ t('Neue Datei: :file', { file: createdFile }) }}
            <Link :href="newConnection.url()" class="font-medium underline">{{
                t('Als Verbindung anlegen')
            }}</Link>
        </Notice>

        <ChangeDialog
            v-model:open="open"
            :title="dialog.title"
            :description="operation === 'drop' ? workspace.schema : null"
            :plan="plan"
            :loading="loading"
            :error="message"
            :failure="failure"
            :connection-name="
                workspace.connection.environment === 'production'
                    ? workspace.connection.name
                    : null
            "
            :apply-label="dialog.apply"
            :processing="processing"
            :errors="errors"
            @apply="apply"
        >
            <template v-if="isSqlite">
                <div class="flex flex-col gap-1.5">
                    <label
                        for="sqlite-ordner"
                        class="text-[13px] font-medium"
                        >{{ t('Ordner') }}</label
                    >
                    <select
                        id="sqlite-ordner"
                        v-model="folder"
                        class="h-9 rounded-md border bg-background px-2.5 font-mono text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option
                            v-for="path in folders"
                            :key="path"
                            :value="path"
                        >
                            {{ path }}
                        </option>
                    </select>
                    <InputError
                        :message="
                            errors.folder ??
                            (folders.length
                                ? undefined
                                : t(
                                      'Kein erlaubter Ordner ist beschreibbar (PHPDBADMIN_SQLITE_PATHS).',
                                  ))
                        "
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="sqlite-datei" class="text-[13px] font-medium">{{
                        t('Dateiname')
                    }}</label>
                    <Input
                        id="sqlite-datei"
                        v-model="file"
                        placeholder="neu.sqlite"
                        autocomplete="off"
                        spellcheck="false"
                        class="font-mono"
                        :aria-invalid="errors.file ? true : undefined"
                    />
                    <InputError :message="errors.file" />
                </div>
            </template>
            <div
                v-else-if="operation === 'create'"
                class="flex flex-col gap-1.5"
            >
                <label for="schema-name" class="text-[13px] font-medium">{{
                    t('Name')
                }}</label>
                <Input
                    id="schema-name"
                    v-model="name"
                    autocomplete="off"
                    spellcheck="false"
                    class="font-mono"
                    :aria-invalid="errors.name ? true : undefined"
                />
                <InputError :message="errors.name" />
            </div>
        </ChangeDialog>
    </div>
</template>
