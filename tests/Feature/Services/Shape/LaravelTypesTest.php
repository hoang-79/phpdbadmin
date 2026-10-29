<?php

namespace Tests\Feature\Services\Shape;

use App\Enums\ColumnKind;
use App\Enums\DatabaseDriver;
use App\Services\Shape\LaravelTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aufzählungen, die Laravel nicht sicher schreiben kann (N-02): `enum()` verdoppelt «'» nicht, PostgreSQL und SQLite
 * setzen den Spaltennamen roh in den Check — solche Spalten werden Text mit Hinweis, statt aus dem Literal
 * auszubrechen (im Änderungsskript wie in der erzeugten Migration).
 */
class LaravelTypesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsafeEnums(): array
    {
        return [
            'Anführungszeichen im Wert' => ["enum('a','x'',(select 1)','c')", 'status'],
            'Backslash im Wert' => ["enum('a','b\\\\')", 'status'],
            'Anführungszeichen im Namen' => ["enum('a','b')", 'st"at'],
        ];
    }

    #[DataProvider('unsafeEnums')]
    public function test_enums_that_would_break_out_become_text_with_a_note(string $type, string $column): void
    {
        $laravel = LaravelTypes::for(DatabaseDriver::MySql, 'enum', $type, ColumnKind::Choice, false, null, $column);

        $this->assertSame('string', $laravel->type);
        $this->assertNotNull($laravel->note);
    }

    public function test_ordinary_enums_stay_enums(): void
    {
        $laravel = LaravelTypes::for(DatabaseDriver::MySql, 'enum', "enum('offen','bezahlt')", ColumnKind::Choice, false, null, 'status');

        $this->assertSame(['enum', ['allowed' => ['offen', 'bezahlt']]], [$laravel->type, $laravel->parameters]);
    }
}
