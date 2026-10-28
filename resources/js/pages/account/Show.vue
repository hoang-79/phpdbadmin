<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { LogOut } from '@lucide/vue';
import AppearanceCard from '@/components/account/AppearanceCard.vue';
import PasskeyCard from '@/components/account/PasskeyCard.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { getInitials } from '@/composables/useInitials';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { logout } from '@/routes';
import type { Passkey } from '@/types';

/** Konto (A-05, Wireframe 13): Sicherheit und Darstellung — keine Profilseite. */
defineProps<{
    account: {
        name: string;
        email: string;
        role: string;
        isAdmin: boolean;
        memberSince: string | null;
    };
    passkeys: Passkey[];
    canManagePasskeys: boolean;
}>();
</script>

<template>
    <Head :title="t('Konto')" />

    <div class="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-8">
        <div class="flex items-start gap-3">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold">{{ t('Konto') }}</h1>
                <p class="text-text-secondary">
                    {{ t('Sicherheit und Darstellung für Ihr Konto') }}
                </p>
            </div>
            <Button variant="outline" class="ml-auto" as-child>
                <Link :href="logout()" as="button" @click="router.flushAll()"
                    ><LogOut />{{ t('Abmelden') }}</Link
                >
            </Button>
        </div>

        <div
            class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_420px] lg:items-start"
        >
            <section
                class="flex flex-col gap-3 rounded-lg border bg-background p-4 lg:col-start-2 lg:row-start-1"
            >
                <div class="flex items-center gap-3">
                    <span
                        class="inline-grid size-11 shrink-0 place-items-center rounded-full border bg-surface-strong text-[15px] font-semibold"
                        >{{ getInitials(account.name) }}</span
                    >
                    <div class="flex min-w-0 flex-col">
                        <b class="text-base font-semibold">{{
                            account.name
                        }}</b>
                        <span class="wrap-break-word text-text-secondary">{{
                            account.email
                        }}</span>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        :class="[
                            'rounded-full border px-2 py-px text-xs font-medium',
                            account.isAdmin
                                ? 'border-primary text-primary'
                                : 'text-text-secondary',
                        ]"
                        >{{ account.role }}</span
                    >
                    <span
                        v-if="account.memberSince"
                        class="text-xs text-muted-foreground"
                        >{{
                            t('Konto seit :date', {
                                date: formatDate(account.memberSince),
                            })
                        }}</span
                    >
                </div>
                <Notice>
                    {{
                        t(
                            'Name und E-Mail-Adresse ändert eine Administratorin oder ein Administrator. Es gibt keine eigene Profilseite.',
                        )
                    }}
                </Notice>
            </section>

            <PasskeyCard
                class="lg:col-start-1 lg:row-span-2 lg:row-start-1"
                :passkeys="passkeys"
                :can-manage="canManagePasskeys"
                :email="account.email"
            />

            <AppearanceCard class="lg:col-start-2 lg:row-start-2" />
        </div>
    </div>
</template>
