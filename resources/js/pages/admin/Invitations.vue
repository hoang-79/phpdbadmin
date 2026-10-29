<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Copy, Send, Undo2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import AdminHeader from '@/components/admin/AdminHeader.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { destroy, resend } from '@/routes/admin/invitations';
import type { AdminInvitation, AdminTabs } from '@/types';

/** Verwaltung · Einladungen (A-06, Wireframe 14): offen, angenommen, abgelaufen; erneut senden, zurückziehen. */
defineProps<{
    tabs: AdminTabs;
    invitations: AdminInvitation[];
}>();

const page = usePage();
const busy = ref<number | null>(null);

// Nach dem Senden einmal sichtbar: der Link, falls die Mail nicht ankommt.
const link = computed(
    () =>
        page.flash.invitationLink as { email: string; url: string } | undefined,
);

function state(invitation: AdminInvitation): string {
    switch (invitation.state) {
        case 'accepted':
            return t('angenommen :date', {
                date: formatDate(invitation.acceptedAt),
            });
        case 'open':
            return t('läuft ab :date', {
                date: formatDate(invitation.expiresAt),
            });
        default:
            return t('abgelaufen :date', {
                date: formatDate(invitation.expiresAt),
            });
    }
}

function send(invitation: AdminInvitation): void {
    busy.value = invitation.id;
    router.post(
        resend.url(invitation.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
            },
        },
    );
}

function withdraw(invitation: AdminInvitation): void {
    busy.value = invitation.id;
    router.delete(destroy.url(invitation.id), {
        preserveScroll: true,
        onFinish: () => {
            busy.value = null;
        },
    });
}

async function copy(url: string): Promise<void> {
    await navigator.clipboard.writeText(url);
    toast.success(t('Link kopiert.'));
}
</script>

<template>
    <Head :title="t('Einladungen')" />

    <div class="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-8">
        <AdminHeader :tabs="tabs" active="invitations" />

        <Notice v-if="link" variant="success">
            <p>
                {{
                    t(
                        'Einladung an :email gesendet. Falls die Mail nicht ankommt, geben Sie den Link selbst weiter — er erscheint nur jetzt.',
                        { email: link.email },
                    )
                }}
            </p>
            <div class="mt-2 flex gap-2">
                <Input
                    :model-value="link.url"
                    readonly
                    class="font-mono text-xs"
                    :aria-label="t('Link der Einladung')"
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-9"
                    @click="copy(link.url)"
                    ><Copy />{{ t('Kopieren') }}</Button
                >
            </div>
        </Notice>

        <ul
            v-if="invitations.length"
            class="flex flex-col divide-y rounded-lg border bg-background"
        >
            <li
                v-for="invitation in invitations"
                :key="invitation.id"
                class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3"
            >
                <div class="flex min-w-0 flex-1 flex-col">
                    <span class="flex flex-wrap items-center gap-1.5">
                        <b class="font-semibold wrap-break-word">{{
                            invitation.email
                        }}</b>
                        <span
                            class="rounded-full border px-1.5 text-xs text-text-secondary"
                            >{{ invitation.roleLabel }}</span
                        >
                        <span
                            :class="[
                                'rounded-full border px-1.5 text-xs',
                                invitation.state === 'open'
                                    ? 'border-warning text-warning'
                                    : invitation.state === 'accepted'
                                      ? 'border-success text-success'
                                      : 'text-muted-foreground',
                            ]"
                            >{{
                                {
                                    open: t('eingeladen'),
                                    accepted: t('angenommen'),
                                    expired: t('abgelaufen'),
                                }[invitation.state]
                            }}</span
                        >
                    </span>
                    <span class="text-[13px] text-text-secondary">
                        {{ state(invitation) }}
                        <template v-if="invitation.invitedBy">
                            ·
                            {{
                                t('eingeladen von :name', {
                                    name: invitation.invitedBy,
                                })
                            }}</template
                        >
                    </span>
                </div>
                <div
                    v-if="invitation.state !== 'accepted'"
                    class="flex flex-wrap gap-2"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="busy === invitation.id"
                        @click="send(invitation)"
                        ><Send />{{ t('Erneut senden') }}</Button
                    >
                    <Button
                        v-if="invitation.state === 'open'"
                        variant="ghost"
                        size="sm"
                        :disabled="busy === invitation.id"
                        @click="withdraw(invitation)"
                        ><Undo2 />{{ t('Zurückziehen') }}</Button
                    >
                </div>
            </li>
        </ul>

        <p
            v-else
            class="rounded-lg border border-dashed px-6 py-16 text-center text-sm text-text-secondary"
        >
            {{
                t('Noch keine Einladung. «Person einladen» schickt die erste.')
            }}
        </p>
    </div>
</template>
