<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArchiveRestore,
    ArrowDownToLine,
    ArrowUpFromLine,
    Wrench,
} from '@lucide/vue';
import { DropdownMenuPortal } from 'reka-ui';
import { computed } from 'vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { t } from '@/lib/i18n';
import { maintenanceDescription } from '@/lib/maintenance';
import { backupHref, maintenanceHref, transfersHref } from '@/lib/transfers';
import type { Workspace } from '@/types';

/**
 * Was man mit einer Datenbank oder Tabelle sonst noch tut (O-10): exportieren, importieren, sichern und warten — für
 * die Menüs «Datenbank» und «Weitere Aktionen». Die Einträge führen auf die Seiten, die es dafür gibt, mit Datenbank
 * und Tabelle vorgewählt; dort folgen Vorschau und Bestätigung. Sichern geht nur für die ganze Datenbank.
 */
const props = defineProps<{
    workspace: Workspace;
    schema: string;
    table?: string;
}>();

const operations = computed(() =>
    props.workspace.connection.maintenance.filter(
        (operation) => !props.table || operation.forTables,
    ),
);
</script>

<template>
    <DropdownMenuGroup>
        <DropdownMenuItem as-child>
            <Link
                :href="
                    transfersHref(
                        workspace,
                        table ? { schema, table } : { schema, scope: 'schema' },
                        'exportieren',
                    )
                "
            >
                <ArrowDownToLine />{{ t('Exportieren …') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem as-child>
            <Link
                :href="
                    transfersHref(workspace, { schema, table }, 'importieren')
                "
            >
                <ArrowUpFromLine />{{ t('Importieren …') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuItem v-if="!table" as-child>
            <Link :href="backupHref(workspace, schema)">
                <ArchiveRestore />{{ t('Sichern und wiederherstellen …') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuSub v-if="operations.length > 0">
            <DropdownMenuSubTrigger>
                <Wrench />{{ t('Wartung') }}
            </DropdownMenuSubTrigger>
            <!-- Eigenes Portal: im Hauptmenü würde das Untermenü abgeschnitten (overflow) -->
            <DropdownMenuPortal>
                <DropdownMenuSubContent class="w-72">
                    <DropdownMenuItem
                        v-for="operation in operations"
                        :key="operation.key"
                        as-child
                    >
                        <Link
                            :href="
                                maintenanceHref(workspace, {
                                    operation: operation.key,
                                    schema,
                                    table,
                                })
                            "
                            class="flex items-baseline justify-between gap-3"
                        >
                            <span>{{
                                maintenanceDescription(
                                    operation.key,
                                    workspace.connection.driver,
                                ).title
                            }}</span>
                            <span
                                class="font-mono text-[11px] text-text-secondary"
                                >{{
                                    maintenanceDescription(
                                        operation.key,
                                        workspace.connection.driver,
                                    ).sql
                                }}</span
                            >
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuSubContent>
            </DropdownMenuPortal>
        </DropdownMenuSub>
    </DropdownMenuGroup>
</template>
