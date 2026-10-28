<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, Lock, Pencil, Server, Undo2 } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { t } from '@/lib/i18n';
import { home } from '@/routes';
import { edit } from '@/routes/connections';
import { server } from '@/routes/workspace';
import type { Workspace } from '@/types';

/** Verbindung im Kopf (V-04, Wireframe 05): Umgebung als Farbe, Symbol und Wort; Schloss bei Schreibschutz. */
defineProps<{
    connection: Workspace['connection'];
    compact?: boolean;
}>();

const words = { local: 'lokal', test: 'Test', production: 'produktion' };
const short = { local: 'LOKAL', test: 'TEST', production: 'PROD' };
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger
            :class="[
                'inline-flex h-8 max-w-full min-w-0 items-center gap-2 rounded-full border px-2.5 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring',
                connection.environment === 'production'
                    ? 'border-env-production bg-destructive-soft text-env-production'
                    : connection.environment === 'test'
                      ? 'border-env-test text-env-test'
                      : 'text-foreground',
            ]"
            :aria-label="`Verbindung ${connection.name}, Umgebung ${words[connection.environment]}`"
        >
            <svg
                viewBox="0 0 10 10"
                class="size-2.5 shrink-0"
                aria-hidden="true"
            >
                <circle
                    v-if="connection.environment === 'production'"
                    cx="5"
                    cy="5"
                    r="4.7"
                    fill="currentColor"
                />
                <template v-else>
                    <circle
                        cx="5"
                        cy="5"
                        r="3.9"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                    />
                    <path
                        v-if="connection.environment === 'test'"
                        d="M5 1.1A3.9 3.9 0 0 0 5 8.9Z"
                        fill="currentColor"
                    />
                </template>
            </svg>
            <span class="truncate">
                <template v-if="compact"
                    >{{ short[connection.environment] }} ·
                    {{ connection.name }}</template
                >
                <template v-else
                    >{{ words[connection.environment] }} ·
                    {{ connection.name }}</template
                >
            </span>
            <span
                v-if="!compact"
                class="hidden rounded-sm border bg-background px-1.5 text-xs whitespace-nowrap text-text-secondary sm:inline"
                >{{ connection.driverLabel }}
                {{ connection.serverVersion }}</span
            >
            <Lock
                v-if="connection.readOnly"
                class="size-3.5 shrink-0"
                aria-label="schreibgeschützt"
            />
            <ChevronDown class="size-3.5 shrink-0 opacity-70" />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-60">
            <DropdownMenuItem v-if="connection.canManage" as-child>
                <Link :href="edit(connection.id)" class="gap-2"
                    ><Pencil class="size-4" />Verbindung bearbeiten</Link
                >
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
                <Link :href="server(connection.id)" class="gap-2"
                    ><Server class="size-4" />{{ t('Server') }}</Link
                >
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
                <Link :href="home()" class="gap-2"
                    ><Undo2 class="size-4" />Alle Verbindungen</Link
                >
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
