<?php

namespace Tests\Unit\Services\Data;

use App\Enums\DatabaseDriver;
use App\Services\Data\RowCopy;
use PHPUnit\Framework\TestCase;

/**
 * Kopieren aus dem Raster (E-05): Sonderzeichen, NULL und Binärdaten je Format, Anführungszeichen je Treiber.
 */
class RowCopyTest extends TestCase
{
    /** @var list<list<mixed>> */
    private array $rows = [[1, "O'Brien \\ Pipe|Tab\tZeile\nzwei", null, true, ['binary' => 4, 'hex' => '00ff10ab']]];

    /** @var list<string> */
    private array $columns = ['id', 'name', 'note', 'active', 'photo'];

    public function test_tsv_flattens_tabs_and_lines_and_leaves_null_empty(): void
    {
        $this->assertSame("id\tname\tnote\tactive\tphoto\n1\tO'Brien \\ Pipe|Tab Zeile zwei\t\ttrue\t\n", $this->format('tsv'));
    }

    public function test_markdown_escapes_pipes_and_shows_null(): void
    {
        $this->assertSame(
            "| id | name | note | active | photo |\n| --- | --- | --- | --- | --- |\n| 1 | O'Brien \\ Pipe\\|Tab\tZeile<br>zwei | NULL | true |  |\n",
            $this->format('markdown'),
        );
    }

    public function test_insert_quotes_per_driver(): void
    {
        $this->assertSame(
            "INSERT INTO `t` (`id`, `name`, `note`, `active`, `photo`) VALUES (1, 'O''Brien \\\\ Pipe|Tab\tZeile\nzwei', NULL, TRUE, NULL);\n",
            $this->format('insert', DatabaseDriver::MySql),
        );
        $this->assertSame(
            "INSERT INTO \"t\" (\"id\", \"name\", \"note\", \"active\", \"photo\") VALUES (1, 'O''Brien \\ Pipe|Tab\tZeile\nzwei', NULL, TRUE, NULL);\n",
            $this->format('insert', DatabaseDriver::Postgres),
        );
    }

    /**
     * @param  'tsv'|'markdown'|'insert'  $format
     */
    private function format(string $format, DatabaseDriver $driver = DatabaseDriver::Sqlite): string
    {
        return (new RowCopy)->format($this->columns, $this->rows, $format, $driver, 't');
    }
}
