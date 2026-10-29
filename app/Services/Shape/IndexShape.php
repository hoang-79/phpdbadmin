<?php

namespace App\Services\Shape;

/**
 * Ein Index: Primärschlüssel, eindeutig, gewöhnlich, Volltext oder räumlich. Verglichen wird über Art und Spalten,
 * nicht über den Namen — den vergibt jeder Treiber anders («PRIMARY», «kunden_pkey», «primary»).
 */
final readonly class IndexShape
{
    public const string PRIMARY = 'primary';

    public const string UNIQUE = 'unique';

    public const string INDEX = 'index';

    public const string FULLTEXT = 'fulltext';

    public const string SPATIAL = 'spatial';

    /**
     * @param  list<string>  $columns
     * @param  self::PRIMARY|self::UNIQUE|self::INDEX|self::FULLTEXT|self::SPATIAL  $kind
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $kind,
    ) {}

    /**
     * Art aus Laravels Introspektion (`getIndexes()`).
     *
     * @param  array{name: string, columns: list<string>, type: string|null, unique: bool, primary: bool}  $index
     */
    public static function fromIntrospection(array $index): self
    {
        $type = strtolower((string) $index['type']);

        $kind = match (true) {
            $index['primary'] => self::PRIMARY,
            $index['unique'] => self::UNIQUE,
            $type === 'fulltext' => self::FULLTEXT,
            $type === 'spatial' => self::SPATIAL,
            default => self::INDEX,
        };

        return new self((string) $index['name'], array_map('strval', $index['columns']), $kind);
    }

    /** Schlüssel für den Vergleich; zwischen verschiedenen Treibern zählen Volltext und räumlich als Index. */
    public function signature(bool $sameDriver): string
    {
        $kind = $sameDriver || in_array($this->kind, [self::PRIMARY, self::UNIQUE], true) ? $this->kind : self::INDEX;

        return $kind.'('.implode(',', array_map('strtolower', $this->columns)).')';
    }

    /** Für Listen: «UNIQUE (email)». */
    public function summary(): string
    {
        $label = match ($this->kind) {
            self::PRIMARY => 'PRIMARY KEY',
            self::UNIQUE => 'UNIQUE',
            self::FULLTEXT => 'FULLTEXT',
            self::SPATIAL => 'SPATIAL',
            default => 'INDEX',
        };

        return $label.' ('.implode(', ', $this->columns).')';
    }
}
