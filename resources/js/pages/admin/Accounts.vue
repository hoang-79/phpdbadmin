<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Lock,
    LockOpen,
    Pencil,
    Search,
    ShieldCheck,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AdminHeader from '@/components/admin/AdminHeader.vue';
import ConnectionBadge from '@/components/connections/ConnectionBadge.vue';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { getInitials } from '@/composables/useInitials';
import { formatRecent } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    destroy,
    index,
    permissions as permissionsRoute,
    role as roleRoute,
    show,
    status,
    update,
} from '@/routes/admin/accounts';
import type {
    AdminAccount,
    AdminAccountDetail,
    AdminTabs,
    ConnectionPermission,
    ConnectionRight,
    PermissionOption,
    RoleOption,
    UserRole,
} from '@/types';

/**
 * Verwaltung · Konten (Wireframe 14): Liste links, ein Konto rechts mit Name und Adresse (A-05), Rolle, Sperre,
 * Entfernen und den Rechten je geteilter Verbindung (A-07). Auf dem Telefon erst die Liste, dann das Konto.
 */
const props = defineProps<{
    tabs: AdminTabs;
    accounts: AdminAccount[];
    selected: AdminAccountDetail | null;
    rights: ConnectionRight[];
    permissions: PermissionOption[];
    roles: RoleOption[];
}>();

const search = ref('');
const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term === ''
        ? props.accounts
        : props.accounts.filter((account) =>
              [account.name, account.email].some((value) =>
                  value.toLowerCase().includes(term),
              ),
          );
});

// Rechte je Verbindung: Entwurf bis «Speichern».
const draft = ref<Record<string, ConnectionPermission | null>>({});
const savingRights = ref(false);

watch(
    () => props.rights,
    (rights) => {
        draft.value = Object.fromEntries(
            rights.map((right) => [right.id, right.permission]),
        );
    },
    { immediate: true },
);

const editableRights = computed(() =>
    props.rights.filter((right) => right.editable),
);
const rightsChanged = computed(() =>
    editableRights.value.some(
        (right) => draft.value[right.id] !== right.permission,
    ),
);

function saveRights(): void {
    if (!props.selected) {
        return;
    }

    savingRights.value = true;
    router.put(
        permissionsRoute.url(props.selected.id),
        {
            permissions: Object.fromEntries(
                editableRights.value.map((right) => [
                    right.id,
                    draft.value[right.id] ?? null,
                ]),
            ),
        },
        {
            preserveScroll: true,
            onFinish: () => {
                savingRights.value = false;
            },
        },
    );
}

// Name und E-Mail-Adresse (A-05).
const editing = ref(false);
const form = useForm({ name: '', email: '' });

function startEditing(): void {
    if (!props.selected) {
        return;
    }

    form.defaults({ name: props.selected.name, email: props.selected.email });
    form.reset();
    form.clearErrors();
    editing.value = true;
}

function saveAccount(): void {
    if (!props.selected) {
        return;
    }

    form.patch(update.url(props.selected.id), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}

function changeRole(
    value: string | number | bigint | Record<string, unknown> | null,
): void {
    if (!props.selected || value === props.selected.role) {
        return;
    }

    router.put(
        roleRoute.url(props.selected.id),
        { role: value as UserRole },
        { preserveScroll: true },
    );
}

function toggleDisabled(): void {
    if (!props.selected) {
        return;
    }

    router.put(
        status.url(props.selected.id),
        { disabled: !props.selected.disabled },
        { preserveScroll: true },
    );
}

// Entfernen mit Namenseingabe (Gestaltung G-08: zerstörende Handlung).
const removing = ref(false);
const confirmName = ref('');
const isRemoving = ref(false);

function remove(): void {
    if (!props.selected) {
        return;
    }

    isRemoving.value = true;
    router.delete(destroy.url(props.selected.id), {
        onSuccess: () => {
            removing.value = false;
        },
        onFinish: () => {
            isRemoving.value = false;
        },
    });
}

function lastSeen(account: AdminAccount): string {
    return account.lastLoginAt
        ? t('zuletzt angemeldet :time', {
              time: formatRecent(account.lastLoginAt),
          })
        : t('noch nie angemeldet');
}

function rightNote(right: ConnectionRight): string {
    if (props.selected?.isAdmin) {
        return t('alle Rechte (Administrator)');
    }

    if (right.isOwner) {
        return t('Besitzer — alle Rechte');
    }

    return t('persönlich — gehört :name, nicht geteilt', {
        name: right.ownerName,
    });
}
</script>

<template>
    <Head :title="selected ? selected.name : t('Verwaltung')" />

    <div class="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-6 sm:px-8">
        <AdminHeader :tabs="tabs" active="accounts" />

        <div
            class="grid gap-4 lg:grid-cols-[minmax(0,340px)_minmax(0,1fr)] lg:items-start"
        >
            <!-- Liste: auf dem Telefon nur ohne gewähltes Konto -->
            <section
                :class="[
                    'flex min-w-0 flex-col gap-2',
                    selected && 'hidden lg:flex',
                ]"
            >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        :placeholder="t('Konten suchen')"
                        :aria-label="t('Konten suchen')"
                        class="pl-8"
                    />
                </div>
                <ul
                    class="flex flex-col gap-1 rounded-lg border bg-background p-1"
                >
                    <li v-for="account in filtered" :key="account.id">
                        <Link
                            :href="show(account.id)"
                            preserve-scroll
                            :aria-current="
                                selected?.id === account.id ? 'page' : undefined
                            "
                            :class="[
                                'flex items-center gap-3 rounded-md px-3 py-2.5 outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                selected?.id === account.id
                                    ? 'bg-accent shadow-[inset_3px_0_0_var(--primary)]'
                                    : 'hover:bg-muted',
                            ]"
                        >
                            <span
                                class="inline-grid size-9 shrink-0 place-items-center rounded-full border bg-surface-strong text-xs font-semibold"
                                >{{ getInitials(account.name) }}</span
                            >
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <b class="font-semibold">{{
                                        account.name
                                    }}</b>
                                    <span
                                        :class="[
                                            'rounded-full border px-1.5 text-xs',
                                            account.isAdmin
                                                ? 'border-primary text-primary'
                                                : 'text-text-secondary',
                                        ]"
                                        >{{ account.roleLabel }}</span
                                    >
                                    <span
                                        v-if="account.disabled"
                                        class="rounded-full border border-destructive px-1.5 text-xs text-destructive"
                                        >{{ t('gesperrt') }}</span
                                    >
                                </span>
                                <span
                                    class="truncate text-[13px] text-text-secondary"
                                    >{{ account.email }}</span
                                >
                                <span class="text-xs text-muted-foreground">{{
                                    lastSeen(account)
                                }}</span>
                            </span>
                            <ChevronRight
                                class="size-4 shrink-0 text-muted-foreground lg:hidden"
                            />
                        </Link>
                    </li>
                    <li
                        v-if="!filtered.length"
                        class="px-3 py-2.5 text-sm text-text-secondary"
                    >
                        {{ t('Kein Konto passt zur Suche.') }}
                    </li>
                </ul>
            </section>

            <!-- Gewähltes Konto -->
            <div v-if="selected" class="flex min-w-0 flex-col gap-4">
                <Link
                    :href="index()"
                    class="inline-flex items-center gap-1 text-sm text-text-secondary hover:text-foreground lg:hidden"
                    ><ChevronLeft class="size-4" />{{ t('Alle Konten') }}</Link
                >

                <section
                    class="flex flex-wrap items-center gap-3 rounded-lg border bg-background p-4"
                >
                    <span
                        class="inline-grid size-11 shrink-0 place-items-center rounded-full border bg-surface-strong text-[15px] font-semibold"
                        >{{ getInitials(selected.name) }}</span
                    >
                    <div class="flex min-w-56 flex-1 flex-col">
                        <h2 class="text-base font-semibold">
                            {{ selected.name }}
                        </h2>
                        <span class="wrap-break-word text-text-secondary"
                            >{{ selected.email }} ·
                            {{ lastSeen(selected) }}</span
                        >
                        <span class="text-xs text-muted-foreground">
                            {{
                                selected.hasPasskeys
                                    ? t('Passkey eingerichtet')
                                    : t('noch kein Passkey')
                            }}
                            <template v-if="selected.disabled">
                                · {{ t('gesperrt') }}</template
                            >
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="selected.can.update"
                            variant="outline"
                            size="sm"
                            @click="startEditing"
                            ><Pencil />{{ t('Name und E-Mail ändern') }}</Button
                        >
                        <DropdownMenu v-if="selected.can.changeRole">
                            <DropdownMenuTrigger as-child>
                                <Button variant="outline" size="sm"
                                    >{{
                                        t('Rolle: :role', {
                                            role: selected.roleLabel,
                                        })
                                    }}<ChevronDown
                                /></Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuRadioGroup
                                    :model-value="selected.role"
                                    @update:model-value="changeRole"
                                >
                                    <DropdownMenuRadioItem
                                        v-for="role in roles"
                                        :key="role.value"
                                        :value="role.value"
                                        >{{ role.label }}</DropdownMenuRadioItem
                                    >
                                </DropdownMenuRadioGroup>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <span
                            v-else
                            class="inline-flex h-8 items-center rounded-md border px-2.5 text-[13px] text-text-secondary"
                            >{{
                                t('Rolle: :role', { role: selected.roleLabel })
                            }}</span
                        >
                        <Button
                            v-if="selected.can.disable"
                            variant="outline"
                            size="sm"
                            @click="toggleDisabled"
                        >
                            <template v-if="selected.disabled"
                                ><LockOpen />{{ t('Entsperren') }}</template
                            >
                            <template v-else
                                ><Lock />{{ t('Sperren') }}</template
                            >
                        </Button>
                        <Button
                            v-if="selected.can.delete"
                            variant="outline"
                            size="sm"
                            class="border-destructive text-destructive hover:bg-destructive-soft"
                            @click="
                                confirmName = '';
                                removing = true;
                            "
                            ><Trash2 />{{ t('Entfernen') }}</Button
                        >
                    </div>
                    <p
                        v-if="selected.isSelf"
                        class="basis-full text-xs text-muted-foreground"
                    >
                        {{
                            t(
                                'Das eigene Konto: Rolle, Sperre und Entfernen ändert eine andere Administratorin oder ein anderer Administrator.',
                            )
                        }}
                    </p>
                </section>

                <section class="rounded-lg border bg-background">
                    <div
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-4 py-3"
                    >
                        <ShieldCheck class="size-4" />
                        <h2 class="text-[15px] font-semibold">
                            {{ t('Rechte je Verbindung') }}
                        </h2>
                        <span class="text-[13px] text-text-secondary">{{
                            t('Administratoren haben überall alle Rechte')
                        }}</span>
                        <Button
                            v-if="editableRights.length"
                            size="sm"
                            class="ml-auto"
                            :disabled="!rightsChanged || savingRights"
                            @click="saveRights"
                        >
                            <Spinner v-if="savingRights" />{{ t('Speichern') }}
                        </Button>
                    </div>

                    <p
                        v-if="!rights.length"
                        class="px-4 py-6 text-sm text-text-secondary"
                    >
                        {{
                            t(
                                'Noch keine geteilte Verbindung. Teilen Sie eine Verbindung in der Übersicht über «Teilen …».',
                            )
                        }}
                    </p>

                    <table v-else class="w-full text-left text-sm">
                        <thead
                            class="hidden bg-muted text-[13px] text-text-secondary sm:table-header-group"
                        >
                            <tr>
                                <th class="px-4 py-2 font-medium">
                                    {{ t('Verbindung') }}
                                </th>
                                <th class="px-4 py-2 font-medium">
                                    {{ t('Treiber') }}
                                </th>
                                <th class="px-4 py-2 font-medium">
                                    {{
                                        t('Recht von :name', {
                                            name: selected.name,
                                        })
                                    }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="right in rights"
                                :key="right.id"
                                class="flex flex-col gap-2 px-4 py-3 sm:table-row"
                            >
                                <td class="sm:px-4 sm:py-2.5">
                                    <ConnectionBadge
                                        :name="right.name"
                                        :environment="right.environment"
                                        :read-only="right.readOnly"
                                    />
                                    <p
                                        v-if="right.readOnly && right.editable"
                                        class="mt-1 text-xs text-text-secondary"
                                    >
                                        {{
                                            t(
                                                'Schreibgeschützt: auch mit «schreiben» erst nach Aufheben auf Zeit',
                                            )
                                        }}
                                    </p>
                                </td>
                                <td class="sm:px-4 sm:py-2.5">
                                    <span
                                        class="rounded-sm border px-1.5 py-px text-xs whitespace-nowrap text-text-secondary"
                                        >{{ right.driverLabel }}
                                        {{ right.serverVersion }}</span
                                    >
                                </td>
                                <td class="sm:px-4 sm:py-2.5">
                                    <PermissionPicker
                                        v-if="right.editable"
                                        v-model="draft[right.id]"
                                        :label="
                                            t('Recht an :connection', {
                                                connection: right.name,
                                            })
                                        "
                                    />
                                    <span
                                        v-else
                                        class="text-[13px] text-text-secondary"
                                        >{{ rightNote(right) }}</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <dl
                        class="grid gap-3 rounded-b-lg border-t bg-muted px-4 py-3 sm:grid-cols-3"
                    >
                        <div
                            v-for="permission in permissions"
                            :key="permission.value"
                        >
                            <dt class="font-semibold">
                                {{ permission.label }}
                            </dt>
                            <dd class="text-[13px] text-text-secondary">
                                {{ permission.description }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            <p
                v-else
                class="hidden rounded-lg border border-dashed px-6 py-16 text-center text-sm text-text-secondary lg:block"
            >
                {{ t('Wählen Sie links ein Konto.') }}
            </p>
        </div>

        <p class="text-xs text-muted-foreground">
            {{
                t(
                    'Das erste Konto entsteht bei der Einrichtung; alle weiteren per Einladung.',
                )
            }}
        </p>

        <!-- Name und E-Mail-Adresse -->
        <Dialog v-model:open="editing">
            <DialogContent v-if="selected">
                <form
                    class="flex flex-col gap-4"
                    novalidate
                    @submit.prevent="saveAccount"
                >
                    <DialogTitle>{{ t('Name und E-Mail ändern') }}</DialogTitle>
                    <DialogDescription>{{
                        t(
                            'Nur die Verwaltung ändert Name und Adresse. Die neue Adresse gilt ab der nächsten Anmeldung.',
                        )
                    }}</DialogDescription>
                    <div class="flex flex-col gap-1.5">
                        <Label for="account-name" class="text-[13px]">{{
                            t('Name')
                        }}</Label>
                        <Input
                            id="account-name"
                            v-model="form.name"
                            required
                            maxlength="255"
                            :aria-invalid="form.errors.name ? true : undefined"
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="account-email" class="text-[13px]">{{
                            t('E-Mail-Adresse')
                        }}</Label>
                        <Input
                            id="account-email"
                            v-model="form.email"
                            type="email"
                            required
                            maxlength="255"
                            :aria-invalid="form.errors.email ? true : undefined"
                        />
                        <InputError :message="form.errors.email" />
                    </div>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="outline">{{
                                t('Abbrechen')
                            }}</Button>
                        </DialogClose>
                        <Button type="submit" :disabled="form.processing">
                            <Spinner v-if="form.processing" />{{
                                t('Speichern')
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Entfernen mit Namenseingabe -->
        <Dialog v-model:open="removing">
            <DialogContent v-if="selected">
                <form class="flex flex-col gap-4" @submit.prevent="remove">
                    <DialogTitle>{{
                        t('Konto «:name» entfernen?', { name: selected.name })
                    }}</DialogTitle>
                    <DialogDescription>{{
                        t(
                            'Passkeys, persönliche Verbindungen und Rechte des Kontos gehen verloren. Geteilte Verbindungen des Kontos übernehmen Sie. Das Protokoll bleibt.',
                        )
                    }}</DialogDescription>
                    <Notice variant="danger">{{
                        t('Zum Bestätigen den Namen eintippen: :name', {
                            name: selected.name,
                        })
                    }}</Notice>
                    <Input
                        v-model="confirmName"
                        :aria-label="t('Name zum Bestätigen')"
                        autocomplete="off"
                    />
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="outline">{{
                                t('Abbrechen')
                            }}</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="
                                isRemoving ||
                                confirmName.trim() !== selected.name
                            "
                        >
                            <Spinner v-if="isRemoving" />{{ t('Entfernen') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
