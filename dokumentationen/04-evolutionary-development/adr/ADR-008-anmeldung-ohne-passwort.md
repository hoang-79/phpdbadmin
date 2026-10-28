# ADR-008 — Anmeldung ohne Passwort

| Feld | Wert |
|---|---|
| Status | Angenommen |
| Datum | 28.10.2026 (Vorgabe Hoang; F-6) |
| Betrifft | Anmeldung, Konten |

## Kontext

Vorgabe: kein Passwort, nur Passkey und Code per Mail; kein Dashboard, kein Profil. Fortify 1.41 registriert `POST /login` und die Passwortbestätigung unabhängig von den Features; das Feature `passkeys` steht im Starter-Kit mit `confirmPassword: true`. Basalto hat dasselbe mit denselben Versionen gebaut (Code-Login, Abweisung, Software-Authenticator für Tests).

## Optionen

| Option | Vorteile | Nachteile |
|---|---|---|
| **A — Fortify nur für Passkeys, eigener Code-Login, Passwort-Endpunkte abweisen** | Muster erprobt, Passkeys von Laravel | Fortify-Updates können Routen verschieben (Lektion) |
| B — Fortify ganz entfernen, Passkeys selbst | keine Überraschungen aus Fortify | WebAuthn selbst prüfen — mehr Risiko |

## Entscheid

**Option A.** `Fortify::authenticateUsing()` liefert `null`; Passwortbestätigung durch Bestätigung per Passkey bzw. Code ersetzt; Passwort-Spalten und -Tabellen entfernt; erstes Konto per `phpdbadmin:install`, weitere per Einladung.

## Konsequenzen

- Test ruft jede alte Adresse des Starter-Kits auf (404/405).
- Vor jedem `composer update` Routenliste sichern und vergleichen.
- Parameter nie `{passkey}` nennen (globale Bindung von `laravel/passkeys`).

Siehe [Solution-Architektur](../../03-foundation/solution-architecture.md) · [Feasibility](../../02-feasibility/feasibility.md)
