<?php

namespace App\Services\Catalog;

use App\Connections\TargetConnections;
use App\Dialects\Objects\ObjectDialects;
use App\Models\DatabaseConnection;

/**
 * Beziehungsdiagramm eines Schemas (S-07): Tabellen mit Spalten und die Fremdschlüssel zwischen ihnen, gelesen mit
 * wenigen Abfragen für das ganze Schema, angeordnet von DiagramLayout. Bei mehr als 60 Tabellen zeigt es eine Auswahl
 * — zuerst die Tabellen mit den meisten Beziehungen —, die Person wählt weitere.
 *
 * @phpstan-import-type LayoutTable from DiagramLayout
 * @phpstan-import-type LayoutRelation from DiagramLayout
 * @phpstan-import-type LayoutColumn from DiagramLayout
 */
final class RelationDiagram
{
    /** Ab so vielen Tabellen zeigt das Diagramm eine Auswahl. */
    public const int ALL_UP_TO = 60;

    /** So viele Tabellen zeigt die erste Auswahl bei grossen Schemas. */
    public const int FIRST_CHOICE = 40;

    /** Mehr Tabellen auf einmal würden unlesbar und die Seite schwer. */
    public const int MAX_TABLES = 200;

    public function __construct(private readonly TargetConnections $targets, private readonly DiagramLayout $layout) {}

    /**
     * Alle Tabellen mit Spalten (Fremdschlüssel markiert) und alle Beziehungen innerhalb des Schemas.
     *
     * @return array{tables: array<string, array{name: string, columns: list<LayoutColumn>}>, relations: list<LayoutRelation>}
     */
    public function read(DatabaseConnection $connection, string $schema, ?string $database = null): array
    {
        $data = ObjectDialects::for($connection->driver)->diagram($this->targets->open($connection, $database), $schema);
        $foreign = [];

        foreach ($data['relations'] as $relation) {
            foreach ($relation['columns'] as $column) {
                $foreign[$relation['table']][$column] = true;
            }
        }

        $tables = [];
        foreach ($data['tables'] as $table) {
            $tables[$table['name']] = [
                'name' => $table['name'],
                'columns' => array_map(fn (array $column): array => [...$column, 'foreign' => isset($foreign[$table['name']][$column['name']])], $table['columns']),
            ];
        }

        return ['tables' => $tables, 'relations' => array_values(array_filter($data['relations'], fn (array $relation): bool => isset($tables[$relation['table']], $tables[$relation['foreignTable']])))];
    }

    /**
     * Tabellen zur Auswahl mit der Zahl ihrer Beziehungen (eingehend und ausgehend) und ihren Nachbarn — damit lässt
     * sich eine Auswahl um die verbundenen Tabellen erweitern.
     *
     * @param  array{tables: array<string, array{name: string, columns: list<LayoutColumn>}>, relations: list<LayoutRelation>}  $data
     * @return list<array{name: string, relations: int, neighbours: list<string>}>
     */
    public function catalog(array $data): array
    {
        $counts = array_fill_keys(array_keys($data['tables']), 0);
        $neighbours = [];

        foreach ($data['relations'] as $relation) {
            $counts[$relation['table']]++;

            if ($relation['foreignTable'] !== $relation['table']) {
                $counts[$relation['foreignTable']]++;
                $neighbours[$relation['table']][$relation['foreignTable']] = true;
                $neighbours[$relation['foreignTable']][$relation['table']] = true;
            }
        }

        $catalog = [];
        foreach ($counts as $name => $count) {
            $catalog[] = ['name' => (string) $name, 'relations' => $count, 'neighbours' => array_map('strval', array_keys($neighbours[$name] ?? []))];
        }

        usort($catalog, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $catalog;
    }

    /**
     * Die gewünschten Tabellen, soweit es sie gibt; ohne Wunsch alle bzw. bei grossen Schemas die mit den meisten
     * Beziehungen. Höchstens MAX_TABLES.
     *
     * @param  array{tables: array<string, array{name: string, columns: list<LayoutColumn>}>, relations: list<LayoutRelation>}  $data
     * @param  list<string>|null  $wanted
     * @return list<string>
     */
    public function selection(array $data, ?array $wanted): array
    {
        if ($wanted !== null && $wanted !== []) {
            return array_slice(array_values(array_unique(array_filter($wanted, fn (string $name): bool => isset($data['tables'][$name])))), 0, self::MAX_TABLES);
        }

        $names = array_map('strval', array_keys($data['tables']));

        if (count($names) <= self::ALL_UP_TO) {
            return $names;
        }

        $catalog = $this->catalog($data);
        usort($catalog, fn (array $a, array $b): int => $b['relations'] <=> $a['relations'] ?: strnatcasecmp($a['name'], $b['name']));

        return array_column(array_slice($catalog, 0, self::FIRST_CHOICE), 'name');
    }

    /**
     * Die Auswahl angeordnet, mit den Beziehungen zwischen ihren Tabellen.
     *
     * @param  array{tables: array<string, array{name: string, columns: list<LayoutColumn>}>, relations: list<LayoutRelation>}  $data
     * @param  list<string>  $selection
     * @return array{tables: list<LayoutTable>, relations: list<LayoutRelation>, width: int, height: int}
     */
    public function arrange(array $data, array $selection): array
    {
        $chosen = array_flip($selection);
        $tables = array_values(array_intersect_key($data['tables'], $chosen));
        $relations = array_values(array_filter($data['relations'], fn (array $relation): bool => isset($chosen[$relation['table']], $chosen[$relation['foreignTable']])));

        return [...$this->layout->arrange($tables, $relations), 'relations' => $relations];
    }
}
