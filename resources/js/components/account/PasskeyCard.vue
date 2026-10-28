<script setup lang="ts">
import { Form, Link, router } from '@inertiajs/vue3';
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import { KeyRound, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatRecent } from '@/lib/format';
import { passkeyMessage, suggestedPasskeyName } from '@/lib/passkeyMessage';
import { confirm as confirmPage } from '@/routes/password';
import { destroy, registrationOptions, store, update } from '@/routes/passkey';
import type { Passkey } from '@/types';

/** Passkeys im Konto (A-03, Wireframe 13): hinzufügen, umbenennen, entfernen — nach einer Bestätigung. */
const props = defineProps<{
    passkeys: Passkey[];
    canManage: boolean;
    email: string;
}>();

const adding = ref(false);
const newName = ref('');
const renaming = ref<Passkey | null>(null);
const removing = ref<Passkey | null>(null);
const isRemoving = ref(false);

const registration = usePasskeyRegister({
    routes: { options: registrationOptions.url(), submit: store.url() },
    onSuccess: () => {
        adding.value = false;
        toast.success('Passkey hinzugefügt.');
        router.reload({ only: ['passkeys'] });
    },
});

const registrationError = computed(() =>
    passkeyMessage(registration.errorInstance.value),
);

function startAdding(): void {
    newName.value = suggestedPasskeyName(navigator.userAgent);
    adding.value = true;
}

function remove(passkey: Passkey): void {
    isRemoving.value = true;
    router.delete(destroy.url(passkey.id), {
        preserveScroll: true,
        onSuccess: () => {
            removing.value = null;
            toast.success('Passkey entfernt.');
        },
        onFinish: () => {
            isRemoving.value = false;
        },
    });
}

function usage(passkey: Passkey): string {
    const added = `hinzugefügt ${formatDate(passkey.createdAt)}`;

    return passkey.lastUsedAt
        ? `${added} · zuletzt benutzt ${formatRecent(passkey.lastUsedAt)}`
        : `${added} · noch nie benutzt`;
}

const count = computed(() => props.passkeys.length);
</script>

<template>
    <section class="rounded-lg border bg-background">
        <div class="flex items-center gap-2 border-b px-4 py-3">
            <KeyRound class="size-4" />
            <h2 class="text-[15px] font-semibold">Passkeys</h2>
            <span
                class="rounded-full bg-muted px-2 text-xs font-medium text-text-secondary tabular-nums"
                >{{ count }}</span
            >
            <div class="ml-auto">
                <Button v-if="canManage" size="sm" @click="startAdding">
                    <Plus />Passkey hinzufügen
                </Button>
                <Button v-else size="sm" as-child>
                    <Link :href="confirmPage()"
                        ><Plus />Passkey hinzufügen</Link
                    >
                </Button>
            </div>
        </div>

        <form
            v-if="adding"
            class="flex flex-col gap-3 border-b bg-muted px-4 py-3"
            @submit.prevent="registration.register(newName.trim())"
        >
            <div class="flex flex-col gap-1.5">
                <Label for="passkey-name" class="text-[13px]"
                    >Name des Passkeys</Label
                >
                <Input
                    id="passkey-name"
                    v-model="newName"
                    required
                    maxlength="255"
                    autofocus
                    aria-describedby="passkey-name-hint"
                />
                <p id="passkey-name-hint" class="text-xs text-muted-foreground">
                    Damit Sie ihn später wiedererkennen, z. B. «MacBook» oder
                    «iPhone».
                </p>
            </div>
            <InputError :message="registrationError" />
            <div class="flex gap-2">
                <Button
                    type="submit"
                    size="sm"
                    :disabled="registration.isLoading.value || !newName.trim()"
                >
                    <Spinner v-if="registration.isLoading.value" />
                    Passkey anlegen
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="adding = false"
                    >Abbrechen</Button
                >
            </div>
        </form>

        <ul v-if="count" class="divide-y">
            <li
                v-for="passkey in passkeys"
                :key="passkey.id"
                class="flex items-start gap-3 px-4 py-3"
            >
                <KeyRound class="mt-0.5 size-4 shrink-0 text-primary" />
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <b class="font-semibold wrap-break-word">{{
                            passkey.name
                        }}</b>
                        <span
                            v-if="passkey.authenticator"
                            class="rounded-full border border-primary px-2 py-px text-xs font-medium text-primary"
                            >{{ passkey.authenticator }}</span
                        >
                    </div>
                    <span class="text-xs text-muted-foreground">{{
                        usage(passkey)
                    }}</span>
                </div>
                <div class="flex shrink-0 gap-1">
                    <template v-if="canManage">
                        <Button
                            size="sm"
                            variant="ghost"
                            class="text-text-secondary"
                            :aria-label="`${passkey.name} umbenennen`"
                            @click="renaming = passkey"
                        >
                            <Pencil /><span class="hidden sm:inline"
                                >Umbenennen</span
                            >
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            class="text-text-secondary"
                            :aria-label="`${passkey.name} entfernen`"
                            @click="removing = passkey"
                        >
                            <Trash2 /><span class="hidden sm:inline"
                                >Entfernen</span
                            >
                        </Button>
                    </template>
                    <Button
                        v-else
                        size="sm"
                        variant="ghost"
                        class="text-text-secondary"
                        as-child
                    >
                        <Link :href="confirmPage()">Verwalten</Link>
                    </Button>
                </div>
            </li>
        </ul>
        <p v-else class="px-4 py-6 text-sm text-text-secondary">
            Noch kein Passkey. Damit melden Sie sich per Fingerabdruck, Gesicht
            oder Geräte-PIN an — schneller als mit einem Code.
        </p>

        <p
            class="rounded-b-lg border-t bg-muted px-4 py-2.5 text-xs text-muted-foreground"
        >
            Ohne Passkey melden Sie sich mit einem Code an
            <span class="wrap-break-word">{{ email }}</span> an. Einen
            Passwort-Weg gibt es nicht.
        </p>

        <Dialog
            :open="renaming !== null"
            @update:open="(open) => !open && (renaming = null)"
        >
            <DialogContent v-if="renaming">
                <DialogTitle>Passkey umbenennen</DialogTitle>
                <Form
                    v-bind="update.form(renaming.id)"
                    class="flex flex-col gap-4"
                    :options="{ preserveScroll: true }"
                    #default="{ errors, processing }"
                    @success="renaming = null"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label for="rename" class="text-[13px]">Name</Label>
                        <Input
                            id="rename"
                            name="name"
                            :default-value="renaming.name"
                            required
                            maxlength="255"
                            autofocus
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="outline"
                                >Abbrechen</Button
                            >
                        </DialogClose>
                        <Button type="submit" :disabled="processing">
                            <Spinner v-if="processing" />Speichern
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="removing !== null"
            @update:open="(open) => !open && (removing = null)"
        >
            <DialogContent v-if="removing">
                <DialogTitle
                    >Passkey «{{ removing.name }}» entfernen?</DialogTitle
                >
                <DialogDescription>
                    Mit diesem Passkey können Sie sich danach nicht mehr
                    anmelden. Der Code per Mail bleibt immer möglich.
                </DialogDescription>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline"
                            >Abbrechen</Button
                        >
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="isRemoving"
                        @click="remove(removing)"
                    >
                        <Spinner v-if="isRemoving" />Entfernen
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
