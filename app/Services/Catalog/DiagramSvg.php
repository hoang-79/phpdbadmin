<?php

namespace App\Services\Catalog;

use Closure;

/**
 * Beziehungsdiagramm als eigenständige SVG-Datei (S-07): Kästen mit Spalten (Schlüssel markiert), Fremdschlüssel als
 * Linien mit Pfeil zur verwiesenen Tabelle, Stile eingebettet und in den hellen Farben der Gestaltung — die Datei
 * sieht ohne phpdbadmin, ohne Netz und in jedem Programm gleich aus. Jeder Text aus der Datenbank ist für XML
 * maskiert: ein Tabellenname mit «<script>» bleibt Text.
 *
 * Linien wie in der Oberfläche (resources/js/lib/diagram.ts): vom ersten Spaltenfeld des Fremdschlüssels zum ersten
 * verwiesenen Feld, von der Seite, die zur anderen Tabelle zeigt.
 *
 * @phpstan-import-type LayoutTable from DiagramLayout
 * @phpstan-import-type LayoutRelation from DiagramLayout
 */
final class DiagramSvg
{
    /** Farben aus gestaltung.md (hell). */
    private const string STYLE = <<<'CSS'
        .canvas { fill: #ffffff; }
        .box { fill: #ffffff; }
        .frame { fill: none; stroke: #b9c2cd; stroke-width: 1; }
        .head { fill: #e3f2f5; }
        .rule { stroke: #dde2e8; stroke-width: 1; }
        .name { font: 600 13px Inter, "Segoe UI", system-ui, sans-serif; fill: #1c2534; }
        .column { font: 12px "JetBrains Mono", ui-monospace, Menlo, Consolas, monospace; fill: #1c2534; }
        .type { font: 12px "JetBrains Mono", ui-monospace, Menlo, Consolas, monospace; fill: #5f6b7d; }
        .badge { font: 600 10px Inter, "Segoe UI", system-ui, sans-serif; }
        .pk { fill: #0b6e82; }
        .fk { fill: #4a5568; }
        .more { font: italic 12px Inter, "Segoe UI", system-ui, sans-serif; fill: #5f6b7d; }
        .link { fill: none; stroke: #0b6e82; stroke-width: 1.5; }
        .arrow { fill: #0b6e82; }
        CSS;

    /**
     * @param  list<LayoutTable>  $tables
     * @param  list<LayoutRelation>  $relations
     * @param  Closure(int): string  $more  Text für ausgeblendete Spalten
     */
    public function render(array $tables, array $relations, string $title, Closure $more): string
    {
        $minX = $tables === [] ? 0 : min(array_column($tables, 'x'));
        $minY = $tables === [] ? 0 : min(array_column($tables, 'y'));
        $byName = [];

        foreach ($tables as $table) {
            $byName[$table['name']] = $this->shifted($table, $minX, $minY);
        }

        $width = $byName === [] ? 2 * DiagramLayout::MARGIN : max(array_map(fn (array $table): int => $table['x'] + $table['width'], $byName)) + DiagramLayout::MARGIN;
        $height = $byName === [] ? 2 * DiagramLayout::MARGIN : max(array_map(fn (array $table): int => $table['y'] + $table['height'], $byName)) + DiagramLayout::MARGIN;

        $links = [];
        foreach ($relations as $relation) {
            $child = $byName[$relation['table']] ?? null;
            $parent = $byName[$relation['foreignTable']] ?? null;

            if ($child !== null && $parent !== null) {
                $links[] = '<path class="link" marker-end="url(#pfeil)" d="'.self::path($child, $relation['columns'][0] ?? '', $parent, $relation['foreignColumns'][0] ?? '').'"><title>'
                    .$this->text($relation['name'].': '.$relation['table'].'('.implode(', ', $relation['columns']).') → '.$relation['foreignTable'].'('.implode(', ', $relation['foreignColumns']).')').'</title></path>';
            }
        }

        $boxes = array_map(fn (array $table): string => $this->table($table, $more), array_values($byName));

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<svg xmlns="http://www.w3.org/2000/svg" width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'" role="img">'."\n"
            .'<title>'.$this->text($title).'</title>'."\n"
            .'<style>'."\n".self::STYLE."\n".'</style>'."\n"
            .'<defs><marker id="pfeil" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path class="arrow" d="M0,0 L10,5 L0,10 z"/></marker></defs>'."\n"
            .'<rect class="canvas" x="0" y="0" width="'.$width.'" height="'.$height.'"/>'."\n"
            .'<g>'.implode("\n", $links).'</g>'."\n"
            .'<g>'.implode("\n", $boxes).'</g>'."\n"
            .'</svg>'."\n";
    }

    /**
     * In die Datei verschoben: die Tabelle oben links bekommt den Rand.
     *
     * @param  LayoutTable  $table
     * @return LayoutTable
     */
    private function shifted(array $table, int $minX, int $minY): array
    {
        $table['x'] = $table['x'] - $minX + DiagramLayout::MARGIN;
        $table['y'] = $table['y'] - $minY + DiagramLayout::MARGIN;

        return $table;
    }

    /**
     * Linie vom Fremdschlüssel zur verwiesenen Spalte — dieselbe Rechnung wie in der Oberfläche.
     *
     * @param  LayoutTable  $child
     * @param  LayoutTable  $parent
     */
    public static function path(array $child, string $column, array $parent, string $foreignColumn): string
    {
        $childY = self::rowY($child, $column);
        $parentY = self::rowY($parent, $foreignColumn);

        if ($child['name'] === $parent['name']) {
            $x = $child['x'] + $child['width'];

            return sprintf('M%d %d C%d %d, %d %d, %d %d', $x, $childY, $x + 48, $childY, $x + 48, $parentY, $x, $parentY);
        }

        $childRight = $child['x'] + $child['width'] / 2 < $parent['x'] + $parent['width'] / 2;
        $startX = $childRight ? $child['x'] + $child['width'] : $child['x'];
        $endX = $childRight ? $parent['x'] : $parent['x'] + $parent['width'];
        $bend = max(40, (int) (abs($endX - $startX) / 2));
        $direction = $childRight ? 1 : -1;

        return sprintf('M%d %d C%d %d, %d %d, %d %d', $startX, $childY, $startX + $direction * $bend, $childY, $endX - $direction * $bend, $parentY, $endX, $parentY);
    }

    /**
     * Mitte der Zeile einer Spalte; ist sie ausgeblendet, die Mitte des Kopfes.
     *
     * @param  LayoutTable  $table
     */
    private static function rowY(array $table, string $column): int
    {
        $index = array_search($column, array_column($table['columns'], 'name'), true);

        return $index === false
            ? $table['y'] + intdiv(DiagramLayout::HEADER, 2)
            : $table['y'] + DiagramLayout::HEADER + (int) $index * DiagramLayout::ROW + intdiv(DiagramLayout::ROW, 2);
    }

    /**
     * @param  LayoutTable  $table
     * @param  Closure(int): string  $more
     */
    private function table(array $table, Closure $more): string
    {
        $x = $table['x'];
        $y = $table['y'];
        $parts = [
            '<g>',
            '<title>'.$this->text($table['name']).'</title>',
            sprintf('<rect class="box" x="%d" y="%d" width="%d" height="%d" rx="6"/>', $x, $y, $table['width'], $table['height']),
            sprintf('<rect class="head" x="%d" y="%d" width="%d" height="%d" rx="6"/>', $x, $y, $table['width'], DiagramLayout::HEADER),
            sprintf('<rect class="head" x="%d" y="%d" width="%d" height="6"/>', $x, $y + DiagramLayout::HEADER - 6, $table['width']),
            sprintf('<line class="rule" x1="%d" y1="%d" x2="%d" y2="%d"/>', $x, $y + DiagramLayout::HEADER, $x + $table['width'], $y + DiagramLayout::HEADER),
            sprintf('<rect class="frame" x="%d" y="%d" width="%d" height="%d" rx="6"/>', $x, $y, $table['width'], $table['height']),
            sprintf('<text class="name" x="%d" y="%d">%s</text>', $x + 10, $y + 19, $this->text($this->shortened($table['name'], $table['width'] - 20, 7.8))),
        ];

        foreach ($table['columns'] as $index => $column) {
            $rowY = $y + DiagramLayout::HEADER + $index * DiagramLayout::ROW + 14;
            $badge = $column['primary'] ? ['pk', 'PK'] : ($column['foreign'] ? ['fk', 'FK'] : null);

            if ($badge !== null) {
                $parts[] = sprintf('<text class="badge %s" x="%d" y="%d">%s</text>', $badge[0], $x + 8, $rowY, $badge[1]);
            }

            $parts[] = sprintf('<text class="column" x="%d" y="%d">%s</text>', $x + 30, $rowY, $this->text($this->shortened($column['name'], (int) ($table['width'] * 0.55), 7.3)));
            $parts[] = sprintf('<text class="type" x="%d" y="%d" text-anchor="end">%s</text>', $x + $table['width'] - 8, $rowY, $this->text($this->shortened($column['type'], (int) ($table['width'] * 0.35), 7.3)));
        }

        if ($table['more'] > 0) {
            $parts[] = sprintf('<text class="more" x="%d" y="%d">%s</text>', $x + 30, $y + DiagramLayout::HEADER + count($table['columns']) * DiagramLayout::ROW + 14, $this->text($more($table['more'])));
        }

        $parts[] = '</g>';

        return implode('', $parts);
    }

    /** Kürzen mit «…», wenn der Text breiter als der Platz wäre. */
    private function shortened(string $text, int $space, float $char): string
    {
        $fits = max(3, (int) floor($space / $char));

        return mb_strlen($text) > $fits ? mb_substr($text, 0, $fits - 1).'…' : $text;
    }

    private function text(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
