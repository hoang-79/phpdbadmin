<?php

namespace App\Services\Catalog;

use App\Connections\TargetConnections;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;

/**
 * Katalog für den Navigator (O-01): Schemas und Objektgruppen einer Zielverbindung — für alle vier Treiber mit
 * denselben Gruppen und Begriffen (O-04). Wo ein Treiber etwas nicht kennt, steht die Gruppe mit Erklärung da statt
 * zu fehlen.
 *
 * @phpstan-type NavigatorItem array{name: string, kind: string, rows: int|null, detail: string|null}
 * @phpstan-type NavigatorGroup array{key: string, label: string, items: list<NavigatorItem>, note: string|null}
 */
final class Catalog
{
    public function __construct(private readonly TargetConnections $targets) {}

    /**
     * @return list<string>
     */
    public function schemas(DatabaseConnection $connection): array
    {
        $target = $this->targets->open($connection);

        return $connection->driver->dialect()->schemas($target);
    }

    /**
     * Das Schema, das beim Öffnen gezeigt wird: die eingetragene Datenbank (MySQL, MariaDB), das aktuelle Schema
     * (PostgreSQL) bzw. «main» (SQLite) — sonst das erste.
     */
    public function defaultSchema(DatabaseConnection $connection): ?string
    {
        $schemas = $this->schemas($connection);
        $preferred = match ($connection->driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => $connection->database,
            default => $connection->driver->dialect()->defaultSchema($this->targets->open($connection)),
        };

        return in_array($preferred, $schemas, true) ? $preferred : ($schemas[0] ?? null);
    }

    /**
     * Objektgruppen eines Schemas in fester Reihenfolge.
     *
     * @return list<NavigatorGroup>
     */
    public function groups(DatabaseConnection $connection, string $schema): array
    {
        $target = $this->targets->open($connection);
        $dialect = $connection->driver->dialect();
        $objects = $dialect->tables($target, $schema);

        $item = fn (string $name, string $kind, ?int $rows = null, ?string $detail = null): array => [
            'name' => $name, 'kind' => $kind, 'rows' => $rows, 'detail' => $detail,
        ];

        $tables = [];
        $views = [];
        foreach ($objects as $object) {
            if ($object['type'] === 'view') {
                $views[] = $item($object['name'], 'view');
            } else {
                $tables[] = $item($object['name'], 'table', $object['rows']);
            }
        }

        $routines = $dialect->routines($target, $schema);
        $events = $dialect->events($target, $schema);
        $sequences = $dialect->sequences($target, $schema);

        $groups = [
            ['key' => 'tables', 'label' => 'Tabellen', 'items' => $tables, 'note' => null],
            ['key' => 'views', 'label' => 'Sichten', 'items' => $views, 'note' => null],
            [
                'key' => 'routines',
                'label' => 'Routinen',
                'items' => array_map(fn (array $routine): array => $item($routine['name'], $routine['kind']), $routines ?? []),
                'note' => $routines === null ? 'SQLite kennt keine gespeicherten Funktionen und Prozeduren.' : null,
            ],
            [
                'key' => 'triggers',
                'label' => 'Trigger',
                'items' => array_map(fn (array $trigger): array => $item($trigger['name'], 'trigger', detail: $trigger['table']), $dialect->schemaTriggers($target, $schema)),
                'note' => null,
            ],
        ];

        if ($events !== null) {
            $groups[] = ['key' => 'events', 'label' => 'Ereignisse', 'items' => array_map(fn (string $name): array => $item($name, 'event'), $events), 'note' => null];
        }

        if ($sequences !== null) {
            $groups[] = ['key' => 'sequences', 'label' => 'Sequenzen', 'items' => array_map(fn (string $name): array => $item($name, 'sequence'), $sequences), 'note' => null];
        }

        return $groups;
    }
}
