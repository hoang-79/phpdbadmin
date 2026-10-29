<?php

namespace App\Services\Transfer\Writers;

use App\Services\Transfer\ColumnInfo;
use App\Services\Transfer\ExportTable;
use App\Services\Transfer\Values;

/**
 * JSON (E-01): eine Tabelle als Liste von Objekten, eine ganze Datenbank als Objekt «Tabelle → Liste»; eine Zeile je
 * Objekt, damit sich grosse Dateien zeilenweise lesen lassen.
 */
final class JsonWriter implements ExportWriter
{
    private const int FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /** @var list<ColumnInfo> */
    private array $columns = [];

    private bool $firstTable = true;

    private bool $firstRow = true;

    /**
     * @param  resource  $stream
     * @param  bool  $keyedByTable  mehrere Tabellen: Objekt mit dem Namen jeder Tabelle
     */
    public function __construct(private $stream, private readonly bool $keyedByTable = false) {}

    public function begin(): void
    {
        if ($this->keyedByTable) {
            fwrite($this->stream, '{');
        }
    }

    public function beginTable(ExportTable $table): void
    {
        $this->columns = $table->dataColumns();
        $this->firstRow = true;

        if ($this->keyedByTable) {
            fwrite($this->stream, ($this->firstTable ? "\n" : ",\n").json_encode($table->name, self::FLAGS).': ');
        }

        $this->firstTable = false;
        fwrite($this->stream, '[');
    }

    public function wantsRows(ExportTable $table): bool
    {
        return true;
    }

    public function row(array $row): void
    {
        $object = [];

        foreach ($this->columns as $column) {
            $object[$column->name] = Values::json($row[$column->name] ?? null, $column);
        }

        fwrite($this->stream, ($this->firstRow ? "\n" : ",\n").json_encode((object) $object, self::FLAGS));
        $this->firstRow = false;
    }

    public function endTable(): void
    {
        fwrite($this->stream, $this->firstRow ? ']' : "\n]");
    }

    public function finish(): void
    {
        if ($this->keyedByTable) {
            fwrite($this->stream, $this->firstTable ? "}\n" : "\n}\n");
        } else {
            fwrite($this->stream, "\n");
        }
    }
}
