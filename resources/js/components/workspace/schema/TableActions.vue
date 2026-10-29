<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ChevronDown,
    Copy,
    Eraser,
    Lock,
    PencilLine,
    TextCursorInput,
    Trash2,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { t } from '@/lib/i18n';
import { actionUrls, alterHref, databaseField } from '@/lib/schema';
import type { Workspace } from '@/types';

/**
 * Oben auf der Struktur-Seite (S-03, S-04): «Tabelle ändern» und ein Menü mit Umbenennen, Kopieren, Leeren und
 * Löschen — jede Aktion mit Vorschau im Dialog; Leeren und Löschen nur mit dem Namen der Tabelle. Ohne Recht
 * «schreiben» oder bei Schreibschutz bleiben die Knöpfe sichtbar, aber gesperrt, mit Grund.
 */
const props = defineProps<{
    workspace: Workspace;
    table: string;
}>();

type Operation = 'rename' | 'copy' | 'truncate' | 'drop';

const operation = ref<Operation>('rename');
const open = ref(false);
const to = ref('');
const withData = ref(true);
const urls = computed(() => actionUrls(props.workspace, props.table));

const change = useSchemaChange({
    previewUrl: () => urls.value.preview,
    applyUrl: () => urls.value.apply,
    payload: () => ({
        operation: operation.value,
        to: to.value.trim(),
        with_data: withData.value,
        ...databaseField(props.workspace),
    }),
});
const { plan, loading, failure, errors, message, processing } = change;

const blocked = computed(() =>
    props.workspace.connection.canWrite
        ? null
        : props.workspace.connection.readOnly
          ? t('Schreibgeschützt — Strukturänderungen sind gesperrt.')
          : t('Dafür braucht es das Recht «schreiben».'),
);

function start(next: Operation): void {
    operation.value = next;
    to.value =
        next === 'rename'
            ? props.table
            : next === 'copy'
              ? `${props.table}_kopie`
              : '';
    withData.value = true;
    change.reset();
    open.value = true;

    if (next === 'truncate' || next === 'drop' || next === 'copy') {
        void change.refresh();
    }
}

watchDebounced(
    [to, withData],
    () => {
        if (
            open.value &&
            (operation.value === 'rename' || operation.value === 'copy')
        ) {
            if (
                operation.value === 'rename' &&
                to.value.trim() === props.table
            ) {
                change.reset();

                return;
            }

            void change.refresh();
        }
    },
    { debounce: 350 },
);

const dialog = computed(
    () =>
        ({
            rename: { title: t('Tabelle umbenennen'), apply: t('Umbenennen') },
            copy: { title: t('Tabelle kopieren'), apply: t('Kopieren') },
            truncate: {
                title: t('Tabelle leeren'),
                apply: t('Alle Zeilen löschen'),
            },
            drop: { title: t('Tabelle löschen'), apply: t('Tabelle löschen') },
        })[operation.value],
);

function apply(confirmation: {
    confirmName: string;
    confirmation: string;
}): void {
    change.apply(confirmation, () => {
        open.value = false;
    });
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Button v-if="!blocked" as-child size="sm">
            <Link :href="alterHref(workspace, table)">
                <PencilLine />{{ t('Tabelle ändern') }}
            </Link>
        </Button>
        <Button v-else size="sm" disabled :title="blocked">
            <PencilLine />{{ t('Tabelle ändern') }}
        </Button>

        <DropdownMenu>
            <DropdownMenuTrigger as-child :disabled="!!blocked">
                <Button
                    variant="outline"
                    size="sm"
                    :title="blocked ?? undefined"
                >
                    {{ t('Weitere Aktionen') }}<ChevronDown />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-56">
                <DropdownMenuItem @select="start('rename')">
                    <TextCursorInput />{{ t('Umbenennen …') }}
                </DropdownMenuItem>
                <DropdownMenuItem @select="start('copy')">
                    <Copy />{{ t('Kopieren …') }}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    variant="destructive"
                    @select="start('truncate')"
                >
                    <Eraser />{{ t('Leeren …') }}
                </DropdownMenuItem>
                <DropdownMenuItem variant="destructive" @select="start('drop')">
                    <Trash2 />{{ t('Löschen …') }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>

        <span
            v-if="blocked"
            class="inline-flex items-center gap-1.5 text-[13px] text-text-secondary"
        >
            <Lock class="size-3.5" />{{ blocked }}
        </span>

        <ChangeDialog
            v-model:open="open"
            :title="dialog.title"
            :description="table"
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
            <div
                v-if="operation === 'rename' || operation === 'copy'"
                class="flex flex-col gap-1.5"
            >
                <label
                    for="tabelle-neuer-name"
                    class="text-[13px] font-medium"
                    >{{
                        operation === 'rename'
                            ? t('Neuer Name')
                            : t('Name der Kopie')
                    }}</label
                >
                <Input
                    id="tabelle-neuer-name"
                    v-model="to"
                    autocomplete="off"
                    spellcheck="false"
                    class="font-mono"
                    :aria-invalid="errors.to ? true : undefined"
                />
                <InputError :message="errors.to" />
            </div>
            <fieldset
                v-if="operation === 'copy'"
                class="flex flex-wrap gap-4 text-sm"
            >
                <legend class="sr-only">{{ t('Was kopieren?') }}</legend>
                <label class="flex items-center gap-1.5">
                    <input
                        v-model="withData"
                        type="radio"
                        :value="false"
                        class="accent-primary"
                    />{{ t('nur die Struktur') }}
                </label>
                <label class="flex items-center gap-1.5">
                    <input
                        v-model="withData"
                        type="radio"
                        :value="true"
                        class="accent-primary"
                    />{{ t('Struktur und Daten') }}
                </label>
            </fieldset>
        </ChangeDialog>
    </div>
</template>
