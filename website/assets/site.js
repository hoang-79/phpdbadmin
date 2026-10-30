// Website von phpdbadmin: Menü auf schmalen Bildschirmen, Kopieren der Befehle, sanftes Einblenden. Ohne Skript
// bleibt alles lesbar — nur das Menü klappt dann nicht auf (die Abschnitte sind trotzdem erreichbar).
(() => {
    const kopf = document.querySelector('[data-kopf]');
    const menue = document.querySelector('[data-menue]');

    const gerollt = () =>
        kopf?.toggleAttribute('data-gerollt', window.scrollY > 8);
    gerollt();
    window.addEventListener('scroll', gerollt, { passive: true });

    const schliessen = () => {
        kopf?.removeAttribute('data-offen');
        menue?.setAttribute('aria-expanded', 'false');
    };
    menue?.addEventListener('click', () => {
        const offen = !kopf.hasAttribute('data-offen');
        kopf.toggleAttribute('data-offen', offen);
        menue.setAttribute('aria-expanded', String(offen));
    });
    kopf?.querySelectorAll('.hauptnav a').forEach((link) =>
        link.addEventListener('click', schliessen),
    );
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && kopf?.hasAttribute('data-offen')) {
            schliessen();
            menue?.focus();
        }
    });
    window
        .matchMedia('(min-width: 881px)')
        .addEventListener('change', schliessen);

    document.querySelectorAll('[data-kopieren]').forEach((knopf) => {
        const text = knopf.querySelector('span');
        const vorher = text?.textContent ?? '';
        knopf.addEventListener('click', async () => {
            const code = knopf.closest('.code')?.querySelector('code');
            const befehle = [...(code?.querySelectorAll('[data-zeile]') ?? [])]
                .map((zeile) => zeile.textContent)
                .join('\n');
            try {
                await navigator.clipboard.writeText(
                    befehle || code?.textContent || '',
                );
                knopf.setAttribute('data-kopiert', '');
                if (text) {
                    text.textContent = knopf.dataset.kopieren;
                }
                setTimeout(() => {
                    knopf.removeAttribute('data-kopiert');
                    if (text) {
                        text.textContent = vorher;
                    }
                }, 1800);
            } catch {
                // Ohne Zugriff auf die Zwischenablage (z. B. ohne HTTPS) bleibt der Text zum Markieren stehen.
            }
        });
    });

    const elemente = document.querySelectorAll('[data-einblenden]');
    if (!('IntersectionObserver' in window)) {
        elemente.forEach((element) =>
            element.setAttribute('data-sichtbar', ''),
        );
        return;
    }
    const beobachter = new IntersectionObserver(
        (eintraege) => {
            eintraege.forEach((eintrag) => {
                if (eintrag.isIntersecting) {
                    eintrag.target.setAttribute('data-sichtbar', '');
                    beobachter.unobserve(eintrag.target);
                }
            });
        },
        { rootMargin: '0px 0px -10% 0px', threshold: 0.08 },
    );
    elemente.forEach((element) => beobachter.observe(element));
})();
