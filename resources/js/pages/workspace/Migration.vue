<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Copy, Download, FileCode } from '@lucide/vue';
import { computed } from 'vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import ObjectHeader from '@/components/workspace/ObjectHeader.vue';
import { copyText, downloadText } from '@/lib/download';
import { titlePrefix } from '@/lib/environment';
import { t } from '@/lib/i18n';
import { structureHref } from '@/lib/workspace';
import type { ObjectStructure, Workspace } from '@/types';

/**
 * Laravel-Migration aus einer Tabelle (S-09): die Datei zum Kopieren oder Herunterladen. Sie legt dieselbe Tabelle
 * auf MySQL, MariaDB, PostgreSQL oder SQLite an; was Laravel nicht kennt, steht als Hinweis darüber und im Code.
 */
const props = defineProps<{
    workspace: Workspace;
    object: ObjectStructure['object'];
    migration: { fileName: string; code: string; notes: string[] };
}>();

const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${t('Laravel-Migration')} · ${props.object.name} · ${props.workspace.connection.name}`,
);
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col">
        <ObjectHeader
            :workspace="workspace"
            :object="object"
            active="structure"
        />

        <section
            class="flex flex-col gap-4 px-4 py-4 sm:px-6"
            :aria-labelledby="'migration-title'"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    :href="structureHref(workspace, object.name)"
                    class="inline-flex items-center gap-1 text-[13px] text-text-secondary hover:text-foreground"
                    ><ArrowLeft class="size-3.5" />{{ t('Struktur') }}</Link
                >
            </div>
            <div class="flex flex-wrap items-start gap-3">
                <div class="flex min-w-0 flex-col gap-1">
                    <h2
                        id="migration-title"
                        class="flex items-center gap-2 text-[15px] font-semibold"
                    >
                        <FileCode class="size-4" />{{ t('Laravel-Migration') }}
                    </h2>
                    <p class="max-w-2xl text-[13px] text-text-secondary">
                        {{
                            t(
                                'Für den Ordner database/migrations eines Laravel-Projekts. Legt dieselbe Tabelle auf MySQL, MariaDB, PostgreSQL oder SQLite an — Spalten, Indizes und Fremdschlüssel.',
                            )
                        }}
                    </p>
                    <code
                        class="font-mono text-xs break-all text-text-secondary"
                        >{{ migration.fileName }}</code
                    >
                </div>
                <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="
                            copyText(migration.code, t('Migration kopiert.'))
                        "
                        ><Copy />{{ t('Kopieren') }}</Button
                    >
                    <Button
                        size="sm"
                        @click="
                            downloadText(
                                migration.fileName,
                                migration.code,
                                'text/x-php',
                            )
                        "
                        ><Download />{{ t('Herunterladen') }}</Button
                    >
                </div>
            </div>

            <Notice
                v-for="note in migration.notes"
                :key="note"
                variant="warning"
                >{{ note }}</Notice
            >

            <pre
                class="rounded-md border bg-muted px-3 py-2.5 font-mono text-xs leading-[18px] break-all whitespace-pre-wrap"
                tabindex="0"
                :aria-label="t('Laravel-Migration')"
                >{{ migration.code }}</pre>
        </section>
    </div>
</template>
