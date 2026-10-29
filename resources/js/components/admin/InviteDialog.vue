<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Send } from '@lucide/vue';
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
import { t, tc } from '@/lib/i18n';
import { store } from '@/routes/admin/invitations';
import type { UserRole } from '@/types';

/** Person einladen (A-06, Wireframe 14): Adresse und Rolle; der Link gilt einige Tage. */
defineProps<{
    validDays: number;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({ email: '', role: 'member' as UserRole });

function submit(): void {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <form
                class="flex flex-col gap-4"
                novalidate
                @submit.prevent="submit"
            >
                <DialogTitle>{{ t('Person einladen') }}</DialogTitle>
                <DialogDescription>
                    {{
                        tc(
                            'Die Person bekommt einen Link per Mail. Er gilt :count Tag.|Die Person bekommt einen Link per Mail. Er gilt :count Tage.',
                            validDays,
                        )
                    }}
                </DialogDescription>

                <div class="flex flex-col gap-1.5">
                    <Label for="invite-email" class="text-[13px]">{{
                        t('E-Mail-Adresse')
                    }}</Label>
                    <Input
                        id="invite-email"
                        v-model="form.email"
                        type="email"
                        autocomplete="off"
                        required
                        maxlength="255"
                        :aria-invalid="form.errors.email ? true : undefined"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <fieldset class="flex flex-col gap-1.5">
                    <legend class="mb-1.5 text-[13px] font-medium">
                        {{ t('Rolle') }}
                    </legend>
                    <label class="flex items-start gap-2.5 text-sm">
                        <input
                            v-model="form.role"
                            type="radio"
                            value="member"
                            class="mt-0.5 size-4 accent-primary"
                        />
                        <span class="flex flex-col">
                            <span>{{ t('Mitglied') }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                t(
                                    'Sieht eigene Verbindungen und geteilte, an denen es ein Recht hat.',
                                )
                            }}</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2.5 text-sm">
                        <input
                            v-model="form.role"
                            type="radio"
                            value="admin"
                            class="mt-0.5 size-4 accent-primary"
                        />
                        <span class="flex flex-col">
                            <span>{{ t('Administrator') }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                t(
                                    'Verwaltet Konten, Einladungen, Rechte und das Protokoll.',
                                )
                            }}</span>
                        </span>
                    </label>
                    <InputError :message="form.errors.role" />
                </fieldset>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">{{
                            t('Abbrechen')
                        }}</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" /><Send v-else />{{
                            t('Einladung senden')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
