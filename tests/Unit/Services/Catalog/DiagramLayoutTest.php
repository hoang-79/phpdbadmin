<?php

namespace Tests\Unit\Services\Catalog;

use App\Services\Catalog\DiagramLayout;
use App\Services\Catalog\DiagramSvg;
use Tests\TestCase;

/**
 * Anordnung und SVG des Beziehungsdiagramms (S-07): Eltern links der Kinder, nichts überlappt, Kreise brechen die
 * Anordnung nicht, Tabellen ohne Beziehung stehen gesammelt rechts; die SVG-Datei ist gültiges XML mit maskierten
 * Namen und einer Linie je Beziehung.
 */
class DiagramLayoutTest extends TestCase
{
    public function test_referenced_tables_stand_left_of_the_tables_that_reference_them(): void
    {
        $layout = (new DiagramLayout)->arrange(
            [$this->table('orders', ['id', 'customer_id']), $this->table('customers', ['id']), $this->table('items', ['id', 'order_id']), $this->table('log', ['id'])],
            [$this->relation('orders', 'customer_id', 'customers'), $this->relation('items', 'order_id', 'orders')],
        );
        $x = array_column($layout['tables'], 'x', 'name');

        $this->assertLessThan($x['orders'], $x['customers']);
        $this->assertLessThan($x['items'], $x['orders']);
        $this->assertGreaterThan($x['items'], $x['log'], 'Ohne Beziehung: gesammelt rechts.');
        $this->assertNoOverlaps($layout['tables']);
        $this->assertGreaterThanOrEqual(max(array_map(fn (array $table): int => $table['x'] + $table['width'], $layout['tables'])), $layout['width']);
    }

    public function test_cycles_and_self_references_still_place_every_table(): void
    {
        $layout = (new DiagramLayout)->arrange(
            [$this->table('a', ['id', 'b_id']), $this->table('b', ['id', 'a_id']), $this->table('tree', ['id', 'parent_id'])],
            [$this->relation('a', 'b_id', 'b'), $this->relation('b', 'a_id', 'a'), $this->relation('tree', 'parent_id', 'tree')],
        );

        $this->assertEqualsCanonicalizing(['a', 'b', 'tree'], array_column($layout['tables'], 'name'));
        $this->assertNoOverlaps($layout['tables']);
    }

    public function test_tall_layers_wrap_into_further_columns(): void
    {
        $tables = array_map(fn (int $index): array => $this->table('t'.$index, ['id', 'a', 'b', 'c', 'd', 'e']), range(1, 30));
        $layout = (new DiagramLayout)->arrange($tables, []);

        $this->assertGreaterThan(1, count(array_unique(array_column($layout['tables'], 'x'))));
        $this->assertLessThanOrEqual(1100 + 2 * DiagramLayout::MARGIN + 200, $layout['height']);
        $this->assertNoOverlaps($layout['tables']);
    }

    public function test_wide_tables_show_their_keys_and_count_the_rest(): void
    {
        $columns = array_map(fn (int $index): string => 'spalte_'.$index, range(1, 40));
        $table = $this->table('breit', ['id', ...$columns, 'kunde_id']);
        $table['columns'][41]['foreign'] = true;

        $box = (new DiagramLayout)->arrange([$table], [])['tables'][0];

        $this->assertCount(DiagramLayout::MAX_COLUMNS, $box['columns']);
        $this->assertSame(42 - DiagramLayout::MAX_COLUMNS, $box['more']);
        $this->assertSame(['id', 'kunde_id'], array_column(array_filter($box['columns'], fn (array $column): bool => $column['primary'] || $column['foreign']), 'name'));
    }

    public function test_svg_is_standalone_xml_with_escaped_names_and_one_line_per_relation(): void
    {
        $layout = (new DiagramLayout)->arrange(
            [$this->table('<script>alert(1)</script>', ['id']), $this->table('orders & "Co"', ['id', 'kunde_id'])],
            [$this->relation('orders & "Co"', 'kunde_id', '<script>alert(1)</script>')],
        );

        $svg = (new DiagramSvg)->render($layout['tables'], [$this->relation('orders & "Co"', 'kunde_id', '<script>alert(1)</script>')], 'shop — Beziehungsdiagramm', fn (int $count): string => $count.' weitere');

        $this->assertNotFalse(simplexml_load_string($svg), 'gültiges XML');
        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $svg);
        $this->assertStringContainsString('orders &amp; &quot;Co&quot;', $svg);
        $this->assertStringContainsString('<style>', $svg);
        $this->assertSame(1, substr_count($svg, 'class="link"'));
        $this->assertStringNotContainsString('http://', str_replace('http://www.w3.org/2000/svg', '', $svg), 'nichts von aussen nachladen');
    }

    public function test_svg_uses_the_positions_it_gets_and_starts_at_the_margin(): void
    {
        $tables = (new DiagramLayout)->arrange([$this->table('a', ['id']), $this->table('b', ['id'])], [])['tables'];
        $tables[0]['x'] = 500;
        $tables[0]['y'] = 900;
        $tables[1]['x'] = 100;
        $tables[1]['y'] = 100;

        $svg = (new DiagramSvg)->render($tables, [], 'x', fn (int $count): string => '');

        $this->assertStringContainsString('<rect class="box" x="424" y="824"', $svg);
        $this->assertStringContainsString('<rect class="box" x="24" y="24"', $svg);
    }

    /**
     * @param  list<array{name: string, x: int, y: int, width: int, height: int}>  $tables
     */
    private function assertNoOverlaps(array $tables): void
    {
        foreach ($tables as $index => $a) {
            foreach (array_slice($tables, $index + 1) as $b) {
                $apart = $b['x'] >= $a['x'] + $a['width'] || $a['x'] >= $b['x'] + $b['width']
                    || $b['y'] >= $a['y'] + $a['height'] || $a['y'] >= $b['y'] + $b['height'];
                $this->assertTrue($apart, $a['name'].' überlappt '.$b['name']);
            }
        }
    }

    /**
     * @param  list<string>  $columns
     * @return array{name: string, columns: list<array{name: string, type: string, primary: bool, nullable: bool, foreign: bool}>}
     */
    private function table(string $name, array $columns): array
    {
        return ['name' => $name, 'columns' => array_map(fn (string $column): array => [
            'name' => $column, 'type' => 'int', 'primary' => $column === 'id', 'nullable' => false, 'foreign' => str_ends_with($column, '_id'),
        ], $columns)];
    }

    /**
     * @return array{name: string, table: string, columns: list<string>, foreignTable: string, foreignColumns: list<string>}
     */
    private function relation(string $table, string $column, string $foreignTable): array
    {
        return ['name' => $table.'_'.$column.'_fk', 'table' => $table, 'columns' => [$column], 'foreignTable' => $foreignTable, 'foreignColumns' => ['id']];
    }
}
