<?php

namespace App\Services\Catalog;

/**
 * Anordnung des Beziehungsdiagramms (S-07), ohne Bibliothek: geschichtet nach Abhängigkeiten. Tabellen, auf die
 * nichts zeigt bzw. die selbst auf nichts zeigen, stehen links; wer auf eine Tabelle verweist, steht eine Spalte
 * weiter rechts als sie. Innerhalb einer Spalte sortiert der Schwerpunkt der Nachbarn (Barycenter), damit sich Linien
 * wenig kreuzen. Zu hohe Spalten brechen um; Tabellen ohne Beziehung stehen gesammelt rechts.
 *
 * Masse in Pixeln, gleich in der Oberfläche (resources/js/lib/diagram.ts) und im SVG-Export (DiagramSvg).
 *
 * @phpstan-type LayoutColumn array{name: string, type: string, primary: bool, nullable: bool, foreign: bool}
 * @phpstan-type LayoutTable array{name: string, columns: list<LayoutColumn>, more: int, x: int, y: int, width: int, height: int}
 * @phpstan-type LayoutRelation array{name: string, table: string, columns: list<string>, foreignTable: string, foreignColumns: list<string>}
 */
final class DiagramLayout
{
    public const int HEADER = 30;

    public const int ROW = 20;

    public const int PADDING = 6;

    public const int GAP_X = 96;

    public const int GAP_Y = 28;

    public const int MARGIN = 24;

    /** Spalten je Kasten; Schlüssel immer, der Rest bis zu dieser Zahl, dann «… n weitere». */
    public const int MAX_COLUMNS = 24;

    private const int MIN_WIDTH = 168;

    private const int MAX_WIDTH = 340;

    /** Breite eines Zeichens in der Schrift der Spalten (12 px JetBrains Mono ≈ 7,2 px). */
    private const float CHAR = 7.3;

    /** Höhe, ab der eine Spalte umbricht — mindestens so hoch wie der höchste Kasten. */
    private const int COLUMN_HEIGHT = 1100;

    /**
     * @param  list<array{name: string, columns: list<LayoutColumn>}>  $tables
     * @param  list<LayoutRelation>  $relations
     * @return array{tables: list<LayoutTable>, width: int, height: int}
     */
    public function arrange(array $tables, array $relations): array
    {
        $boxes = [];
        foreach ($tables as $table) {
            $boxes[$table['name']] = $this->box($table);
        }

        $parents = [];
        $children = [];
        foreach ($relations as $relation) {
            if ($relation['table'] === $relation['foreignTable'] || ! isset($boxes[$relation['table']], $boxes[$relation['foreignTable']])) {
                continue;
            }
            $parents[$relation['table']][$relation['foreignTable']] = true;
            $children[$relation['foreignTable']][$relation['table']] = true;
        }

        $connected = [];
        $isolated = [];
        foreach (array_keys($boxes) as $name) {
            isset($parents[$name]) || isset($children[$name]) ? $connected[] = (string) $name : $isolated[] = (string) $name;
        }

        $layers = $this->layers($connected, $parents);
        $layers = $this->ordered($layers, $parents, $children);

        if ($isolated !== []) {
            natcasesort($isolated);
            $layers[] = array_values($isolated);
        }

        return $this->place($layers, $boxes);
    }

    /**
     * Kasten einer Tabelle: Schlüssel zuerst sichtbar, Breite aus dem längsten Text.
     *
     * @param  array{name: string, columns: list<LayoutColumn>}  $table
     * @return LayoutTable
     */
    private function box(array $table): array
    {
        $columns = $table['columns'];
        $more = 0;

        if (count($columns) > self::MAX_COLUMNS) {
            $keys = array_filter($columns, fn (array $column): bool => $column['primary'] || $column['foreign']);
            $others = array_filter($columns, fn (array $column): bool => ! $column['primary'] && ! $column['foreign']);
            $shown = array_slice($others, 0, max(0, self::MAX_COLUMNS - count($keys)), true) + $keys;
            ksort($shown);
            $more = count($columns) - count($shown);
            $columns = array_values($shown);
        }

        $longest = max(
            mb_strlen($table['name']) + 4,
            ...array_map(fn (array $column): int => mb_strlen($column['name']) + mb_strlen($column['type']) + 6, $columns ?: [['name' => '', 'type' => '']]),
        );

        return [
            'name' => $table['name'],
            'columns' => $columns,
            'more' => $more,
            'x' => 0,
            'y' => 0,
            'width' => (int) min(self::MAX_WIDTH, max(self::MIN_WIDTH, ceil($longest * self::CHAR) + 16)),
            'height' => self::HEADER + (count($columns) + ($more > 0 ? 1 : 0)) * self::ROW + self::PADDING,
        ];
    }

    /**
     * Schicht je Tabelle: 0 ohne Eltern, sonst eins mehr als die tiefste Elterntabelle. Kreise werden an der Stelle
     * gebrochen, an der sie die Suche wieder erreicht.
     *
     * @param  list<string>  $names
     * @param  array<string, array<string, true>>  $parents
     * @return list<list<string>>
     */
    private function layers(array $names, array $parents): array
    {
        $depth = [];
        $visiting = [];

        $visit = function (string $name) use (&$visit, &$depth, &$visiting, $parents): int {
            if (isset($depth[$name])) {
                return $depth[$name];
            }

            if (isset($visiting[$name])) {
                return -1;
            }

            $visiting[$name] = true;
            $deepest = -1;

            foreach (array_keys($parents[$name] ?? []) as $parent) {
                $deepest = max($deepest, $visit((string) $parent));
            }

            unset($visiting[$name]);

            return $depth[$name] = $deepest + 1;
        };

        $layers = [];
        natcasesort($names);

        foreach ($names as $name) {
            $layers[$visit($name)][] = $name;
        }

        ksort($layers);

        return array_values(array_map(array_values(...), $layers));
    }

    /**
     * Reihenfolge je Schicht nach dem Schwerpunkt der Nachbarn, abwechselnd von links und von rechts.
     *
     * @param  list<list<string>>  $layers
     * @param  array<string, array<string, true>>  $parents
     * @param  array<string, array<string, true>>  $children
     * @return list<list<string>>
     */
    private function ordered(array $layers, array $parents, array $children): array
    {
        for ($round = 0; $round < 4; $round++) {
            $position = $this->positions($layers);

            if ($round % 2 === 0) {
                for ($index = 1; $index < count($layers); $index++) {
                    $layers[$index] = $this->byBarycenter($layers[$index], $parents, $position);
                    $position = $this->positions($layers);
                }
            } else {
                for ($index = count($layers) - 2; $index >= 0; $index--) {
                    $layers[$index] = $this->byBarycenter($layers[$index], $children, $position);
                    $position = $this->positions($layers);
                }
            }
        }

        return $layers;
    }

    /**
     * @param  list<string>  $layer
     * @param  array<string, array<string, true>>  $neighbours
     * @param  array<string, int>  $position
     * @return list<string>
     */
    private function byBarycenter(array $layer, array $neighbours, array $position): array
    {
        $weights = [];

        foreach ($layer as $index => $name) {
            $known = array_values(array_filter(array_map(fn (string $neighbour): ?int => $position[$neighbour] ?? null, array_map('strval', array_keys($neighbours[$name] ?? []))), fn (?int $value): bool => $value !== null));
            $weights[$name] = $known === [] ? (float) $index : array_sum($known) / count($known);
        }

        usort($layer, fn (string $a, string $b): int => $weights[$a] <=> $weights[$b] ?: strnatcasecmp($a, $b));

        return $layer;
    }

    /**
     * @param  list<list<string>>  $layers
     * @return array<string, int>
     */
    private function positions(array $layers): array
    {
        $position = [];

        foreach ($layers as $layer) {
            foreach ($layer as $index => $name) {
                $position[$name] = $index;
            }
        }

        return $position;
    }

    /**
     * Schichten als Spalten von links nach rechts, zu hohe umgebrochen, jede Spalte senkrecht mittig.
     *
     * @param  list<list<string>>  $layers
     * @param  array<string, LayoutTable>  $boxes
     * @return array{tables: list<LayoutTable>, width: int, height: int}
     */
    private function place(array $layers, array $boxes): array
    {
        $limit = max(self::COLUMN_HEIGHT, ...array_values(array_map(fn (array $box): int => $box['height'], $boxes ?: [['height' => 0]])));
        $columns = [];

        foreach ($layers as $layer) {
            $column = [];
            $height = 0;

            foreach ($layer as $name) {
                $box = $boxes[$name];

                if ($column !== [] && $height + $box['height'] > $limit) {
                    $columns[] = $column;
                    $column = [];
                    $height = 0;
                }

                $column[] = $name;
                $height += $box['height'] + self::GAP_Y;
            }

            if ($column !== []) {
                $columns[] = $column;
            }
        }

        $heights = array_map(fn (array $column): int => array_sum(array_map(fn (string $name): int => $boxes[$name]['height'] + self::GAP_Y, $column)) - self::GAP_Y, $columns);
        $tallest = $heights === [] ? 0 : max($heights);
        $x = self::MARGIN;
        $placed = [];

        foreach ($columns as $index => $column) {
            $y = self::MARGIN + intdiv($tallest - $heights[$index], 2);
            $width = 0;

            foreach ($column as $name) {
                $placed[] = [...$boxes[$name], 'x' => $x, 'y' => $y];
                $y += $boxes[$name]['height'] + self::GAP_Y;
                $width = max($width, $boxes[$name]['width']);
            }

            $x += $width + self::GAP_X;
        }

        return [
            'tables' => $placed,
            'width' => $placed === [] ? 2 * self::MARGIN : $x - self::GAP_X + self::MARGIN,
            'height' => $tallest + 2 * self::MARGIN,
        ];
    }
}
