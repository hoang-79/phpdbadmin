import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type { TransferSummary } from '@/types';

/**
 * Stand der Aufträge abfragen, solange einer wartet oder läuft (B-07): alle 1,5 Sekunden die JSON-Adresse
 * `transfers/status` — nur die App-Datenbank, keine Zielverbindung. Endet ein Auftrag, ruft `onFinished` auf (z. B.
 * um das Ergebnis eines Probelaufs nachzuladen).
 */
export function useTransferPolling(
    url: () => string,
    initial: () => TransferSummary[],
    onFinished: (transfer: TransferSummary) => void = () => {},
): Ref<TransferSummary[]> {
    const transfers = ref<TransferSummary[]>(initial());
    let timer: ReturnType<typeof setTimeout> | null = null;
    let stopped = false;

    function schedule(): void {
        if (timer !== null || stopped) {
            return;
        }

        if (transfers.value.some((transfer) => transfer.active)) {
            timer = setTimeout(poll, 1500);
        }
    }

    async function poll(): Promise<void> {
        timer = null;

        try {
            const response = await fetch(url(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (response.ok) {
                const data = (await response.json()) as {
                    transfers: TransferSummary[];
                };
                const wasActive = new Set(
                    transfers.value
                        .filter((transfer) => transfer.active)
                        .map((transfer) => transfer.id),
                );
                transfers.value = data.transfers;

                for (const transfer of data.transfers) {
                    if (wasActive.has(transfer.id) && !transfer.active) {
                        onFinished(transfer);
                    }
                }
            }
        } finally {
            schedule();
        }
    }

    watch(initial, (value) => {
        transfers.value = value;
        schedule();
    });

    schedule();

    onBeforeUnmount(() => {
        stopped = true;

        if (timer !== null) {
            clearTimeout(timer);
        }
    });

    return transfers;
}
