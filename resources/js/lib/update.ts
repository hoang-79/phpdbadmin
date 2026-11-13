import { xsrfToken } from '@/lib/api';
import { t } from '@/lib/i18n';
import { step as stepRoute } from '@/routes/admin/update';
import type { UpdateStepResponse } from '@/types';

/** Die vier Schritte der Aktualisierung (B-09), je eine eigene Anfrage — `finish` beantwortet schon die neue Version. */
export type UpdateStep = 'download' | 'extract' | 'replace' | 'finish';

export const UPDATE_STEPS: UpdateStep[] = [
    'download',
    'extract',
    'replace',
    'finish',
];

export function wait(milliseconds: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, milliseconds));
}

async function post(step: UpdateStep): Promise<UpdateStepResponse> {
    let response: Response;

    try {
        response = await fetch(stepRoute.url(step), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        });
    } catch (error) {
        return {
            ok: false,
            retry: step === 'finish',
            message: t('Keine Antwort vom Server: :reason', {
                reason: String(error),
            }),
        };
    }

    const body = (await response
        .json()
        .catch(() => null)) as UpdateStepResponse | null;

    if (body && typeof body.ok === 'boolean') {
        return body;
    }

    return {
        ok: false,
        message: t('Unerwartete Antwort des Servers (HTTP :status).', {
            status: response.status,
        }),
    };
}

/**
 * Ein Schritt; meldet der Server «noch alter Code geladen» (retry), fragt er bis zu zehnmal im Abstand von zwei
 * Sekunden nach.
 */
export async function runUpdateStep(
    step: UpdateStep,
): Promise<UpdateStepResponse> {
    let result = await post(step);

    for (
        let attempt = 1;
        attempt < 10 && !result.ok && result.retry;
        attempt++
    ) {
        await wait(2000);
        result = await post(step);
    }

    return result;
}
