<?php

namespace App\Support;

use App\Models\User;

/**
 * Einstellungen der Schema-Übersicht je Konto: Ansicht, Sortierung, Seitengrösse, Art — dazu der Filtertext je
 * Verbindung, Datenbank und Schema (ein Filter für «arbeitssicherheit» gehört nicht in jede andere Datenbank).
 * Gespeichert in `users.preferences` unter `schemaOverview`, automatisch bei jeder Änderung.
 *
 * @phpstan-type Settings array{view: string, sort: string, direction: string, perPage: int, kind: string}
 * @phpstan-type Stored array{view?: string, sort?: string, direction?: string, perPage?: int, kind?: string, filters?: array<string, string>}
 */
final class SchemaOverview
{
    public const string KEY = 'schemaOverview';

    /** Kacheln, Liste mit Spalten, kompakte Namensliste. */
    public const array VIEWS = ['cards', 'list', 'compact'];

    public const array SORTS = ['name', 'rows', 'size', 'kind'];

    public const array DIRECTIONS = ['asc', 'desc'];

    /** 0 heisst «alle auf einer Seite». */
    public const array PER_PAGE = [25, 50, 100, 250, 0];

    public const array KINDS = ['all', 'tables', 'views'];

    /** So viele Filter (je Schema) bleiben gemerkt; der älteste fällt weg. */
    public const int FILTERS = 50;

    public const int FILTER_LENGTH = 100;

    /** @var Settings */
    public const array DEFAULTS = ['view' => 'cards', 'sort' => 'name', 'direction' => 'asc', 'perPage' => 100, 'kind' => 'all'];

    /**
     * Gespeicherte Einstellungen samt Filter für dieses Schema — Unbekanntes fällt auf die Vorgabe zurück.
     *
     * @return array{view: string, sort: string, direction: string, perPage: int, kind: string, filter: string}
     */
    public static function for(User $user, string $connection, ?string $database, string $schema): array
    {
        $stored = self::stored($user);
        $settings = self::DEFAULTS;

        foreach (['view' => self::VIEWS, 'sort' => self::SORTS, 'direction' => self::DIRECTIONS, 'kind' => self::KINDS] as $key => $allowed) {
            if (isset($stored[$key]) && in_array($stored[$key], $allowed, true)) {
                $settings[$key] = $stored[$key];
            }
        }

        if (isset($stored['perPage']) && in_array($stored['perPage'], self::PER_PAGE, true)) {
            $settings['perPage'] = $stored['perPage'];
        }

        return [...$settings, 'filter' => $stored['filters'][self::scope($connection, $database, $schema)] ?? ''];
    }

    /**
     * Einstellungen und Filter dieses Schemas speichern. Die Werte sind geprüft (SchemaOverviewPreferenceController).
     *
     * @param  Settings  $settings
     */
    public static function save(User $user, array $settings, string $connection, ?string $database, string $schema, string $filter): void
    {
        $filters = self::stored($user)['filters'] ?? [];
        $scope = self::scope($connection, $database, $schema);

        // Zuletzt benutzt steht hinten — so fällt beim Kürzen der älteste weg.
        unset($filters[$scope]);
        if ($filter !== '') {
            $filters[$scope] = $filter;
        }
        $filters = array_slice($filters, -self::FILTERS, preserve_keys: true);

        $preferences = $user->preferences ?? [];
        $preferences[self::KEY] = [...$settings, 'filters' => $filters];
        $user->preferences = $preferences;
        $user->save();
    }

    /**
     * Schlüssel des Filters: Namen können jedes Zeichen enthalten, deshalb als Prüfsumme.
     */
    private static function scope(string $connection, ?string $database, string $schema): string
    {
        return sha1((string) json_encode([$connection, $database, $schema]));
    }

    /**
     * @return Stored
     */
    private static function stored(User $user): array
    {
        $stored = $user->preferences[self::KEY] ?? [];

        /** @var Stored */
        return is_array($stored) ? $stored : [];
    }
}
