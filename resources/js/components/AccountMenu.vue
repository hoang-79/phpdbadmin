<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    CircleArrowUp,
    CircleHelp,
    LogOut,
    Palette,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getInitials } from '@/composables/useInitials';
import { t } from '@/lib/i18n';
import { account, help, logout } from '@/routes';
import { index as adminAccounts } from '@/routes/admin/accounts';
import { index as adminUpdate } from '@/routes/admin/update';

const page = usePage();
const user = computed(() => page.props.auth.user);
// Neue Version (B-09): nur Administratoren bekommen den Hinweis.
const update = computed(() => page.props.update);

function signOut(): void {
    router.flushAll();
}
</script>

<template>
    <DropdownMenu v-if="user">
        <DropdownMenuTrigger
            class="inline-flex items-center gap-1 rounded-full text-text-secondary outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
            :aria-label="
                update
                    ? t('Kontomenü — neue Version verfügbar')
                    : t('Kontomenü')
            "
        >
            <span
                class="relative inline-grid size-8 place-items-center rounded-full border bg-surface-strong text-xs font-semibold text-foreground"
                >{{ getInitials(user.name)
                }}<span
                    v-if="update"
                    class="absolute -top-0.5 -right-0.5 size-2.5 rounded-full border-2 border-background bg-primary"
                    :title="t('Neue Version verfügbar')"
            /></span>
            <ChevronDown class="size-3.5" />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-64 p-1.5">
            <DropdownMenuLabel
                class="flex flex-col px-2.5 pt-2 pb-2.5 font-normal"
            >
                <b class="font-semibold">{{ user.name }}</b>
                <span class="truncate text-xs text-muted-foreground">{{
                    user.email
                }}</span>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <Link :href="account()" class="gap-2.5">
                    <ShieldCheck class="size-4" />{{ t('Sicherheit') }}
                </Link>
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
                <Link :href="`${account.url()}#darstellung`" class="gap-2.5">
                    <Palette class="size-4" />{{ t('Darstellung') }}
                </Link>
            </DropdownMenuItem>
            <template v-if="user.isAdmin">
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child>
                    <Link :href="adminAccounts()" class="gap-2.5">
                        <Users class="size-4" />{{ t('Verwaltung') }}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem v-if="update" as-child>
                    <Link :href="adminUpdate()" class="gap-2.5 text-primary">
                        <CircleArrowUp class="size-4" />{{
                            t('Version :version verfügbar', {
                                version: update.version,
                            })
                        }}
                    </Link>
                </DropdownMenuItem>
            </template>
            <DropdownMenuItem as-child>
                <Link :href="help()" class="gap-2.5">
                    <CircleHelp class="size-4" />{{ t('Hilfe') }}
                </Link>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <Link
                    :href="logout()"
                    as="button"
                    class="w-full gap-2.5"
                    @click="signOut"
                >
                    <LogOut class="size-4" />{{ t('Abmelden') }}
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
