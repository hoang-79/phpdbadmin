import { toast } from 'vue-sonner';
import { t } from '@/lib/i18n';

/** Text als Datei herunterladen — ohne Umweg über den Server (Änderungsskript, Migration). */
export function downloadText(
    fileName: string,
    text: string,
    type = 'text/plain',
): void {
    const url = URL.createObjectURL(
        new Blob([text], { type: `${type};charset=utf-8` }),
    );
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName;
    link.click();
    URL.revokeObjectURL(url);
}

/** In die Zwischenablage, mit Rückmeldung. */
export async function copyText(text: string, success: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(success);
    } catch {
        toast.error(
            t('Kopieren ging nicht — markieren Sie den Text von Hand.'),
        );
    }
}
