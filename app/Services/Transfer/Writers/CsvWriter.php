<?php

namespace App\Services\Transfer\Writers;

use App\Services\Transfer\ColumnInfo;
use App\Services\Transfer\Csv;
use App\Services\Transfer\ExportTable;
use App\Services\Transfer\Values;

/**
 * CSV einer Tabelle (E-01, Wireframe 11): Trennzeichen nach Wahl, UTF-8 mit BOM für Excel, Kopfzeile, Binärspalten
 * als Base64 oder weggelassen. NULL ist ein leeres Feld ohne Anführungszeichen, leerer Text `""`.
 */
final class CsvWriter implements ExportWriter
{
    /** @var list<ColumnInfo> */
    private array $columns = [];

    /**
     * @param  resource  $stream
     */
    public function __construct(
        private $stream,
        private readonly string $delimiter = ',',
        private readonly bool $bom = false,
        private readonly bool $header = true,
        private readonly bool $skipBinary = false,
    ) {}

    public function begin(): void
    {
        if ($this->bom) {
            fwrite($this->stream, Csv::BOM);
        }
    }

    public function beginTable(ExportTable $table): void
    {
        $this->columns = array_values(array_filter(
            $table->dataColumns(),
            fn (ColumnInfo $column): bool => ! ($this->skipBinary && $column->binary),
        ));

        if ($this->header) {
            fwrite($this->stream, Csv::line(array_map(fn (ColumnInfo $column): string => $column->name, $this->columns), $this->delimiter));
        }
    }

    public function wantsRows(ExportTable $table): bool
    {
        return true;
    }

    public function row(array $row): void
    {
        $fields = [];

        foreach ($this->columns as $column) {
            $fields[] = Values::csv($row[$column->name] ?? null, $column);
        }

        fwrite($this->stream, Csv::line($fields, $this->delimiter));
    }

    public function endTable(): void {}

    public function finish(): void {}
}
