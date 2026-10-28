<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { ref, watch } from 'vue';
import PermissionPicker from '@/components/connections/PermissionPicker.vue';
import InputError from '@/components/InputError.vue';
import Notice from '@/components/Notice.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { show, update } from '@/routes/connections/sharing';
import type {
    ConnectionPermission,
    ConnectionSummary,
    SharingState,
} from '@/types';

/**
 * Verbindung teilen (V-09) — nur für Administratoren: Teilen ein- oder ausschalten und je Konto ein Recht geben.
 * Besitzer und Administratoren haben ohnehin alle Rechte.
 */
const props = defineProps<{
    connection: ConnectionSummary | null;
}>();

const emit = defineEmits<{ close: [] }>();

const http = useHttp<Record<string, never>, SharingState>({});
const state = ref<SharingState | null>(null);
const shared = ref(false);
const permissions = ref<Record<number, ConnectionPermission | null>>({});
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const loadFailed = ref(false);

watch(
    () => props.connection?.id,
    async (id) => {
        state.value = null;
        errors.value = {};
        loadFailed.value = false;

        if (!id) {
            return;
        }

        try {
            const loaded = await http.get(show.url(id));
            state.value = loaded;
            shared.value = loaded.shared;
            permissions.value = Object.fromEntries(
                loaded.accounts.map((account) => [
                    account.id,
                    account.permission,
                ]),
            );
        } catch {
            loadFailed.value = true;
        }
    },
    { immediate: true },
);

function save(): void {
    if (!props.connection) {
        return;
    }

    saving.value = true;
    router.put(
        update.url(props.connection.id),
        { shared: shared.value, permissions: permissions.value },
        {
            preserveScroll: true,
            onSuccess: () => emit('close'),
            onError: (bag) => {
                errors.value = bag;
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog
        :open="connection !== null"
        @update:open="(open) => !open && emit('close')"
    >
        <DialogContent v-if="connection" class="sm:max-w-2xl">
            <DialogTitle class="flex items-center gap-2">
                <Users class="size-4" />{{
                    t('«:name» teilen', { name: connection.name })
                }}
            </DialogTitle>
            <DialogDescription>
                {{
                    t(
                        'Geteilte Verbindungen sehen nur Konten mit einem Recht. Persönliche Verbindungen sieht nur ihr Besitzer.',
                    )
                }}
            </DialogDescription>

            <div
                v-if="!state && !loadFailed"
                class="flex items-center gap-2 py-6 text-sm text-text-secondary"
            >
                <Spinner />{{ t('Wird geladen') }}
            </div>
            <Notice v-else-if="loadFailed" variant="danger">{{
                t('Der Stand liess sich nicht laden. Bitte noch einmal öffnen.')
            }}</Notice>

            <template v-else-if="state">
                <label class="flex items-start gap-2.5 text-sm">
                    <input
                        v-model="shared"
                        type="checkbox"
                        class="mt-0.5 size-4 accent-primary"
                    />
                    <span class="flex flex-col gap-1">
                        <span class="font-medium">{{
                            t('Mit anderen Konten teilen')
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            t(
                                'Ausschalten entzieht allen ausser dem Besitzer den Zugriff.',
                            )
                        }}</span>
                    </span>
                </label>
                <InputError :message="errors.shared" />

                <ul
                    class="flex flex-col divide-y rounded-md border"
                    :aria-label="t('Konten')"
                >
                    <li
                        v-for="account in state.accounts"
                        :key="account.id"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-3 py-2"
                    >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <b class="truncate font-medium">{{
                                account.name
                            }}</b>
                            <span
                                class="truncate text-xs text-muted-foreground"
                                >{{ account.email }}</span
                            >
                        </span>
                        <span
                            v-if="account.isOwner"
                            class="text-[13px] text-text-secondary"
                            >{{ t('Besitzer — alle Rechte') }}</span
                        >
                        <span
                            v-else-if="account.isAdmin"
                            class="text-[13px] text-text-secondary"
                            >{{ t('Administrator — alle Rechte') }}</span
                        >
                        <template v-else>
                            <PermissionPicker
                                v-model="permissions[account.id]"
                                :label="
                                    t('Recht von :name', {
                                        name: account.name,
                                    })
                                "
                                :disabled="!shared"
                            />
                            <InputError
                                class="basis-full"
                                :message="errors[`permissions.${account.id}`]"
                            />
                        </template>
                    </li>
                </ul>
            </template>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button type="button" variant="outline">{{
                        t('Abbrechen')
                    }}</Button>
                </DialogClose>
                <Button :disabled="!state || saving" @click="save">
                    <Spinner v-if="saving" />{{ t('Speichern') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
