import { useForm, useHttp, usePage } from '@inertiajs/vue3';
import type { ComputedRef, Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import { t } from '@/lib/i18n';
import type { SchemaFailure, SchemaPayload, SchemaPlanPreview } from '@/types';

type Confirmation = { confirmName: string; confirmation: string };

/**
 * Vorschau und Anwenden einer Strukturänderung (S-02 bis S-05): die Vorschau als JSON (nichts läuft), das Anwenden
 * als Inertia-POST mit Weiterleitung. Ältere Antworten der Vorschau werden verworfen, wenn inzwischen eine neuere
 * unterwegs ist. Scheitert das Anwenden, kommt über die einmalige Meldung (Flash), was gelaufen ist.
 */
export function useSchemaChange(options: {
    previewUrl: () => string;
    applyUrl: () => string;
    payload: () => SchemaPayload;
}): {
    plan: Ref<SchemaPlanPreview | null>;
    loading: Ref<boolean>;
    failure: Ref<SchemaFailure | null>;
    errors: ComputedRef<Record<string, string | undefined>>;
    message: ComputedRef<string | null>;
    processing: ComputedRef<boolean>;
    refresh: () => Promise<void>;
    apply: (confirmation: Confirmation, onSuccess?: () => void) => void;
    reset: () => void;
} {
    // Die Felder kommen erst beim Senden aus payload() (transform) — das Formular selbst bleibt leer.
    const http = useHttp<Record<string, never>, SchemaPlanPreview>({});
    const form = useForm<Record<string, never>>({});
    const plan = ref<SchemaPlanPreview | null>(null);
    const loading = ref(false);
    const failure = ref<SchemaFailure | null>(null);
    /** Abgelehnt ohne Prüfmeldung (Sitzung abgelaufen, Recht entzogen, Server weg) — sonst bliebe es still. */
    const requestError = ref<string | null>(null);
    let sequence = 0;

    const errors = computed<Record<string, string | undefined>>(() => ({
        ...(http.errors as Record<string, string | undefined>),
        ...(form.errors as Record<string, string | undefined>),
    }));

    /** Meldung, die zu keinem einzelnen Feld gehört (Server, Katalog, Entwurf als Ganzes). */
    const message = computed<string | null>(() => {
        const entries = Object.entries(errors.value).filter(
            ([field, text]) =>
                text && !['confirm_name', 'confirmation'].includes(field),
        );

        return entries[0]?.[1] ?? requestError.value;
    });

    async function refresh(): Promise<void> {
        sequence += 1;
        const current = sequence;
        loading.value = true;
        requestError.value = null;
        http.clearErrors();

        try {
            const response = await http
                .transform(() => options.payload())
                .post(options.previewUrl());

            if (current === sequence) {
                plan.value = response ?? null;
            }
        } catch {
            if (current === sequence) {
                plan.value = null;

                if (Object.keys(http.errors).length === 0) {
                    requestError.value = t(
                        'Die Vorschau liess sich nicht laden — laden Sie die Seite neu.',
                    );
                }
            }
        } finally {
            if (current === sequence) {
                loading.value = false;
            }
        }
    }

    function apply(confirmation: Confirmation, onSuccess?: () => void): void {
        failure.value = null;
        form.transform(() => ({
            ...options.payload(),
            confirm_name: confirmation.confirmName,
            confirmation: confirmation.confirmation,
        })).post(options.applyUrl(), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onSuccess?.(),
        });
    }

    function reset(): void {
        sequence += 1;
        plan.value = null;
        loading.value = false;
        failure.value = null;
        requestError.value = null;
        http.clearErrors();
        form.clearErrors();
    }

    const page = usePage();
    watch(
        () =>
            (page.flash as { schemaFailure?: SchemaFailure } | undefined)
                ?.schemaFailure,
        (value) => {
            if (value) {
                failure.value = value;
            }
        },
    );

    return {
        plan,
        loading,
        failure,
        errors,
        message,
        processing: computed(() => form.processing),
        refresh,
        apply,
        reset,
    };
}
