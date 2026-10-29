<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Code,
    Copy,
    Info,
    KeyRound,
    Link2,
    List,
    ShieldCheck,
    Table2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ObjectHeader from '@/components/workspace/ObjectHeader.vue';
import TableActions from '@/components/workspace/schema/TableActions.vue';
import { titlePrefix } from '@/lib/environment';
import { t } from '@/lib/i18n';
import { structureHref } from '@/lib/workspace';
import type { ObjectStructure, StructureColumn, Workspace } from '@/types';

/**
 * Struktur ansehen (S-01, Wireframe 08): Spalten mit Typ im Original und daneben der Art, die in allen vier
 * Datenbanken gleich heisst (O-04); Indizes, Fremdschlüssel in beide Richtungen, Checks, Trigger und DDL.
 * Auf dem Telefon Karten statt der breiten Tabelle.
 */
const props = defineProps<{
    workspace: Workspace;
    structure: ObjectStructure;
}>();

const isView = computed(() => props.structure.object.kind === 'view');
const ddlOpen = ref(false);

const sections = computed(() => [
    {
        id: 'spalten',
        label: t('Spalten'),
        count: props.structure.columns.length,
    },
    ...(isView.value
        ? []
        : [
              {
                  id: 'indizes',
                  label: t('Indizes'),
                  count: props.structure.indexes.length,
              },
              {
                  id: 'fremdschluessel',
                  label: t('Fremdschlüssel'),
                  count: props.structure.foreignKeys.length,
              },
              {
                  id: 'checks',
                  label: t('Checks'),
                  count: props.structure.checks.length,
              },
              {
                  id: 'trigger',
                  label: t('Trigger'),
                  count: props.structure.triggers.length,
              },
          ]),
    {
        id: 'ddl',
        label: isView.value ? t('Definition') : t('DDL'),
        count: null,
    },
]);

const title = computed(
    () =>
        `${titlePrefix(props.workspace.connection.environment)}${props.structure.object.name} · ${props.workspace.connection.name}`,
);

/** Vorgabe einer Spalte so, wie sie im Wireframe steht: auto_increment, berechnet, NULL oder der Wert. */
function defaultOf(column: StructureColumn): {
    text: string;
    muted: boolean;
} {
    if (column.generated) {
        return {
            text: t('berechnet: :expression', {
                expression: column.generated,
            }),
            muted: false,
        };
    }

    if (column.autoIncrement) {
        return { text: 'auto_increment', muted: false };
    }

    if (column.default !== null) {
        return { text: column.default, muted: false };
    }

    return column.nullable
        ? { text: 'NULL', muted: true }
        : { text: '—', muted: true };
}

function action(rule: string): string {
    return rule.toUpperCase();
}

async function copyDdl(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.structure.ddl);
        toast.success(t('DDL kopiert.'));
    } catch {
        toast.error(
            t('Kopieren ging nicht — markieren Sie den Text von Hand.'),
        );
    }
}
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col">
        <ObjectHeader
            :workspace="workspace"
            :object="structure.object"
            active="structure"
        />

        <div class="flex flex-col gap-4 px-4 py-4 sm:px-6">
            <TableActions
                v-if="!isView"
                :workspace="workspace"
                :table="structure.object.name"
            />

            <div class="flex flex-wrap items-center gap-2">
                <a
                    v-for="section in sections"
                    :key="section.id"
                    :href="`#${section.id}`"
                    class="inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-[13px] outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {{ section.label }}
                    <span
                        v-if="section.count !== null"
                        class="text-muted-foreground tabular-nums"
                        >{{ section.count }}</span
                    >
                </a>
                <span
                    class="ml-auto hidden items-center gap-1.5 text-[13px] text-text-secondary lg:inline-flex"
                >
                    <Info class="size-3.5" />{{
                        t(
                            'Typ im Original, daneben die Art — gleich für alle vier Datenbanken',
                        )
                    }}
                </span>
            </div>

            <!-- Spalten -->
            <section
                id="spalten"
                class="scroll-mt-4 rounded-lg border bg-background"
                :aria-label="t('Spalten')"
            >
                <h2
                    class="flex items-center gap-2 border-b px-4 py-3 text-[15px] font-semibold"
                >
                    <Table2 class="size-4" />{{ t('Spalten') }}
                    <span class="font-normal text-muted-foreground">{{
                        structure.columns.length
                    }}</span>
                </h2>

                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b bg-muted text-left text-[13px] text-text-secondary"
                            >
                                <th class="w-10 px-4 py-2 font-medium">#</th>
                                <th class="px-2 py-2 font-medium">
                                    {{ t('Name') }}
                                </th>
                                <th class="px-2 py-2 font-medium">
                                    {{
                                        t('Typ (:driver)', {
                                            driver: workspace.connection
                                                .driverLabel,
                                        })
                                    }}
                                </th>
                                <th class="px-2 py-2 font-medium">
                                    {{ t('Art') }}
                                </th>
                                <th class="px-2 py-2 font-medium">NULL</th>
                                <th class="px-2 py-2 font-medium">
                                    {{ t('Vorgabe') }}
                                </th>
                                <th class="px-2 py-2 font-medium">
                                    {{ t('Kollation') }}
                                </th>
                                <th class="px-2 py-2 pr-4 font-medium">
                                    {{ t('Kommentar') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="column in structure.columns"
                                :key="column.name"
                                class="border-b align-top last:border-b-0"
                            >
                                <td
                                    class="px-4 py-2 text-muted-foreground tabular-nums"
                                >
                                    {{ column.position }}
                                </td>
                                <td class="px-2 py-2">
                                    <span
                                        class="inline-flex flex-wrap items-center gap-x-1.5 font-mono font-medium"
                                    >
                                        {{ column.name }}
                                        <KeyRound
                                            v-if="column.primary"
                                            class="size-3.5 text-primary"
                                            :aria-label="t('Primärschlüssel')"
                                        />
                                        <Link
                                            v-if="column.references"
                                            :href="
                                                structureHref(
                                                    workspace,
                                                    column.references.table,
                                                )
                                            "
                                            class="inline-flex items-center gap-0.5 text-[13px] text-primary hover:underline"
                                            ><ArrowRight class="size-3.5" />{{
                                                column.references.table
                                            }}<template
                                                v-if="column.references.column"
                                                >.{{
                                                    column.references.column
                                                }}</template
                                            ></Link
                                        >
                                    </span>
                                </td>
                                <td
                                    class="max-w-72 px-2 py-2 font-mono text-[13px] break-words"
                                >
                                    {{ column.type }}
                                </td>
                                <td class="px-2 py-2">
                                    <span
                                        class="rounded-sm bg-muted px-1.5 py-px text-xs whitespace-nowrap text-text-secondary"
                                        >{{ column.kindLabel }}</span
                                    >
                                </td>
                                <td class="px-2 py-2">
                                    {{ column.nullable ? t('ja') : t('nein') }}
                                </td>
                                <td
                                    :class="[
                                        'max-w-56 px-2 py-2 font-mono text-[13px] break-words',
                                        defaultOf(column).muted &&
                                            'text-muted-foreground italic',
                                    ]"
                                >
                                    {{ defaultOf(column).text }}
                                </td>
                                <td
                                    class="px-2 py-2 text-[13px] text-text-secondary"
                                >
                                    {{ column.collation ?? '—' }}
                                </td>
                                <td
                                    class="px-2 py-2 pr-4 text-[13px] text-text-secondary"
                                >
                                    {{ column.comment ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ul class="divide-y md:hidden">
                    <li
                        v-for="column in structure.columns"
                        :key="column.name"
                        class="flex flex-col gap-0.5 px-4 py-3"
                    >
                        <div class="flex items-start gap-2">
                            <span
                                class="inline-flex min-w-0 flex-wrap items-center gap-x-1.5 font-mono font-medium"
                            >
                                {{ column.name }}
                                <KeyRound
                                    v-if="column.primary"
                                    class="size-3.5 text-primary"
                                    :aria-label="t('Primärschlüssel')"
                                />
                                <Link
                                    v-if="column.references"
                                    :href="
                                        structureHref(
                                            workspace,
                                            column.references.table,
                                        )
                                    "
                                    class="inline-flex items-center gap-0.5 text-primary"
                                    ><ArrowRight class="size-3.5" />{{
                                        column.references.table
                                    }}<template v-if="column.references.column"
                                        >.{{
                                            column.references.column
                                        }}</template
                                    ></Link
                                >
                            </span>
                            <span
                                class="ml-auto shrink-0 rounded-sm bg-muted px-1.5 py-px text-xs text-text-secondary"
                                >{{ column.kindLabel }}</span
                            >
                        </div>
                        <span class="font-mono text-[13px] break-all">{{
                            column.type
                        }}</span>
                        <span class="text-[13px] text-text-secondary">
                            {{
                                column.nullable
                                    ? t('NULL erlaubt')
                                    : t('nicht NULL')
                            }}<template v-if="!defaultOf(column).muted">
                                · {{ t('Vorgabe') }}
                                <code>{{ defaultOf(column).text }}</code>
                            </template>
                            <template v-if="column.comment">
                                · «{{ column.comment }}»</template
                            >
                        </span>
                    </li>
                </ul>
            </section>

            <div
                class="grid gap-4 md:grid-cols-2"
                :class="!isView && 'xl:grid-cols-4'"
            >
                <template v-if="!isView">
                    <!-- Indizes -->
                    <section
                        id="indizes"
                        class="scroll-mt-4 rounded-lg border bg-background"
                    >
                        <h2
                            class="flex items-center gap-2 border-b px-4 py-3 text-[15px] font-semibold"
                        >
                            <List class="size-4" />{{ t('Indizes') }}
                            <span class="font-normal text-muted-foreground">{{
                                structure.indexes.length
                            }}</span>
                        </h2>
                        <ul class="divide-y">
                            <li
                                v-for="index in structure.indexes"
                                :key="index.name"
                                class="flex flex-col gap-1 px-4 py-2.5"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="truncate font-mono text-sm font-medium"
                                        :title="index.name"
                                        >{{ index.name }}</span
                                    >
                                    <span
                                        class="ml-auto shrink-0 rounded-full border px-2 py-px text-xs text-text-secondary"
                                        >{{
                                            index.primary
                                                ? t('Primärschlüssel')
                                                : index.unique
                                                  ? t('Eindeutig')
                                                  : t('Index')
                                        }}</span
                                    >
                                </div>
                                <span
                                    class="font-mono text-[13px] text-text-secondary"
                                    >{{ index.columns.join(', ') }}</span
                                >
                            </li>
                            <li
                                v-if="!structure.indexes.length"
                                class="px-4 py-3 text-sm text-text-secondary"
                            >
                                {{ t('Keine Indizes.') }}
                            </li>
                        </ul>
                    </section>

                    <!-- Fremdschlüssel in beide Richtungen -->
                    <section
                        id="fremdschluessel"
                        class="scroll-mt-4 rounded-lg border bg-background"
                    >
                        <h2
                            class="flex items-center gap-2 border-b px-4 py-3 text-[15px] font-semibold"
                        >
                            <Link2 class="size-4" />{{ t('Fremdschlüssel') }}
                            <span class="font-normal text-muted-foreground">{{
                                structure.foreignKeys.length
                            }}</span>
                        </h2>
                        <ul class="divide-y">
                            <li
                                v-for="foreignKey in structure.foreignKeys"
                                :key="foreignKey.name"
                                class="flex flex-col gap-1 px-4 py-2.5 text-[13px]"
                            >
                                <span
                                    class="truncate font-mono text-sm font-medium"
                                    :title="foreignKey.name"
                                    >{{ foreignKey.name || '—' }}</span
                                >
                                <span class="font-mono text-text-secondary">
                                    {{ foreignKey.columns.join(', ') }}
                                    <ArrowRight class="inline size-3.5" />
                                    <Link
                                        :href="
                                            structureHref(
                                                workspace,
                                                foreignKey.foreignTable,
                                                foreignKey.foreignSchema ??
                                                    workspace.schema,
                                            )
                                        "
                                        class="text-primary underline"
                                        >{{ foreignKey.foreignTable }}.{{
                                            foreignKey.foreignColumns.join(', ')
                                        }}</Link
                                    >
                                </span>
                                <span class="text-text-secondary">
                                    {{
                                        t(
                                            'beim Ändern :update · beim Löschen :delete',
                                            {
                                                update: action(
                                                    foreignKey.onUpdate,
                                                ),
                                                delete: action(
                                                    foreignKey.onDelete,
                                                ),
                                            },
                                        )
                                    }}
                                </span>
                            </li>
                            <li
                                v-if="!structure.foreignKeys.length"
                                class="px-4 py-3 text-sm text-text-secondary"
                            >
                                {{
                                    t(
                                        'Diese Tabelle verweist auf keine andere.',
                                    )
                                }}
                            </li>
                        </ul>
                        <div
                            v-if="structure.referencedBy.length"
                            class="border-t bg-muted px-4 py-2.5 text-[13px]"
                        >
                            <span class="font-medium">{{
                                t('Verwiesen von')
                            }}</span>
                            <ul class="mt-1 flex flex-col gap-0.5">
                                <li
                                    v-for="reference in structure.referencedBy"
                                    :key="`${reference.table}.${reference.name}`"
                                >
                                    <Link
                                        :href="
                                            structureHref(
                                                workspace,
                                                reference.table,
                                            )
                                        "
                                        class="font-mono text-primary underline"
                                        >{{ reference.table }}</Link
                                    >
                                    <span class="text-text-secondary">
                                        ({{ reference.columns.join(', ') }} ·
                                        {{ action(reference.onDelete) }})</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </section>

                    <!-- Checks und Trigger -->
                    <section
                        id="checks"
                        class="scroll-mt-4 rounded-lg border bg-background"
                    >
                        <h2
                            id="trigger"
                            class="flex scroll-mt-4 items-center gap-2 border-b px-4 py-3 text-[15px] font-semibold"
                        >
                            <ShieldCheck class="size-4" />{{
                                t('Checks und Trigger')
                            }}
                            <span class="font-normal text-muted-foreground">{{
                                structure.checks.length +
                                structure.triggers.length
                            }}</span>
                        </h2>
                        <ul class="divide-y">
                            <li
                                v-for="check in structure.checks"
                                :key="`check-${check.name}`"
                                class="flex flex-col gap-1 px-4 py-2.5"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="truncate font-mono text-sm font-medium"
                                        :title="check.name"
                                        >{{ check.name }}</span
                                    >
                                    <span
                                        class="ml-auto shrink-0 rounded-full border px-2 py-px text-xs text-text-secondary"
                                        >{{ t('Check') }}</span
                                    >
                                </div>
                                <code
                                    class="text-[13px] break-words text-text-secondary"
                                    >{{ check.definition }}</code
                                >
                            </li>
                            <li
                                v-for="trigger in structure.triggers"
                                :key="`trigger-${trigger.name}`"
                                class="flex flex-col gap-1 px-4 py-2.5"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="truncate font-mono text-sm font-medium"
                                        :title="trigger.name"
                                        >{{ trigger.name }}</span
                                    >
                                    <span
                                        class="ml-auto shrink-0 rounded-full border px-2 py-px text-xs whitespace-nowrap text-text-secondary"
                                        >{{ trigger.timing }}
                                        {{ trigger.event }}</span
                                    >
                                </div>
                                <code
                                    class="line-clamp-3 text-[13px] break-words whitespace-pre-wrap text-text-secondary"
                                    >{{ trigger.definition }}</code
                                >
                            </li>
                            <li
                                v-if="
                                    !structure.checks.length &&
                                    !structure.triggers.length
                                "
                                class="px-4 py-3 text-sm text-text-secondary"
                            >
                                {{ t('Keine Checks und keine Trigger.') }}
                            </li>
                        </ul>
                    </section>
                </template>

                <!-- DDL -->
                <section
                    id="ddl"
                    class="scroll-mt-4 rounded-lg border bg-background"
                    :class="isView && 'md:col-span-2'"
                >
                    <h2
                        class="flex items-center gap-2 border-b px-4 py-3 text-[15px] font-semibold"
                    >
                        <Code class="size-4" />{{
                            isView ? t('Definition') : t('DDL')
                        }}
                    </h2>
                    <div class="flex flex-col gap-2 p-3">
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-[13px] font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                @click="copyDdl"
                            >
                                <Copy class="size-3.5" />{{ t('Kopieren') }}
                            </button>
                        </div>
                        <pre
                            :class="[
                                'overflow-auto rounded-md border bg-muted p-2.5 font-mono text-[13px] leading-5 whitespace-pre-wrap',
                                !ddlOpen && 'max-h-48',
                            ]"
                            tabindex="0"
                            :aria-label="isView ? t('Definition') : t('DDL')"
                            >{{ structure.ddl }}</pre>
                        <button
                            v-if="structure.ddl.split('\n').length > 9"
                            type="button"
                            class="self-start text-[13px] text-primary hover:underline"
                            :aria-expanded="ddlOpen"
                            @click="ddlOpen = !ddlOpen"
                        >
                            {{
                                ddlOpen
                                    ? t('Weniger zeigen')
                                    : t('Alles zeigen')
                            }}
                        </button>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
