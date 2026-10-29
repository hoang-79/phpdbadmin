<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FileText, Send, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import InviteDialog from '@/components/admin/InviteDialog.vue';
import { Button } from '@/components/ui/button';
import { formatNumber, t } from '@/lib/i18n';
import { index as accounts } from '@/routes/admin/accounts';
import { index as audit } from '@/routes/admin/audit';
import { index as invitations } from '@/routes/admin/invitations';
import type { AdminTabs } from '@/types';

/** Kopf der Verwaltung (Wireframe 14): Titel, «Person einladen», Reiter Konten · Einladungen · Protokoll. */
const props = defineProps<{
    tabs: AdminTabs;
    active: 'accounts' | 'invitations' | 'audit';
}>();

const inviting = ref(false);

const links = computed(() => [
    {
        key: 'accounts',
        href: accounts(),
        label: t('Konten'),
        count: props.tabs.accounts,
        icon: Users,
    },
    {
        key: 'invitations',
        href: invitations(),
        label: t('Einladungen'),
        count: props.tabs.invitations,
        icon: Send,
    },
    {
        key: 'audit',
        href: audit(),
        label: t('Protokoll'),
        count: null,
        icon: FileText,
    },
]);
</script>

<template>
    <div class="flex flex-col gap-5">
        <div class="flex flex-wrap items-start gap-3">
            <div class="flex flex-col gap-1">
                <h1 class="text-xl font-semibold">{{ t('Verwaltung') }}</h1>
                <p class="text-text-secondary">
                    <span class="hidden sm:inline"
                        >{{
                            t(
                                'Konten, Einladungen und Rechte dieser Installation',
                            )
                        }}
                        ·
                    </span>
                    {{ t('nur für Administratoren') }}
                </p>
            </div>
            <Button
                class="w-full sm:ml-auto sm:w-auto"
                @click="inviting = true"
            >
                <Send />{{ t('Person einladen') }}
            </Button>
        </div>

        <nav
            class="flex flex-wrap gap-1 border-b"
            :aria-label="t('Verwaltung')"
        >
            <Link
                v-for="tab in links"
                :key="tab.key"
                :href="tab.href"
                :aria-current="active === tab.key ? 'page' : undefined"
                :class="[
                    '-mb-px inline-flex items-center gap-2 border-b-2 px-2.5 py-2 text-sm whitespace-nowrap outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    active === tab.key
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-text-secondary hover:text-foreground',
                ]"
            >
                <component :is="tab.icon" class="size-4" />{{ tab.label }}
                <span
                    v-if="tab.count !== null"
                    class="text-xs font-normal text-muted-foreground tabular-nums"
                    >{{ formatNumber(tab.count) }}</span
                >
            </Link>
        </nav>

        <InviteDialog
            v-model:open="inviting"
            :valid-days="tabs.invitationDays"
        />
    </div>
</template>
