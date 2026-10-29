<?php

namespace Tests\Unit\Services\Transfer;

use App\Enums\DatabaseDriver;
use App\Services\Transfer\SqlScript;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Zerlegen einer SQL-Datei (E-02): Semikolons in Zeichenketten, Namen, Kommentaren, $$-Blöcken und Routinen trennen
 * nicht; Zeilennummern zeigen auf den Anfang jeder Anweisung.
 */
class SqlScriptTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_semicolons_inside_strings_identifiers_and_comments_do_not_split(): void
    {
        $statements = $this->split(DatabaseDriver::Postgres, <<<'SQL'
            -- Kopf; mit Semikolon
            insert into "a;b" values ('x;y', 'it''s; fine');
            /* Block; mit /* verschachtelt; */ Kommentar; */
            select 1;
            SQL);

        $this->assertSame([
            ['sql' => "insert into \"a;b\" values ('x;y', 'it''s; fine')", 'line' => 2],
            ['sql' => 'select 1', 'line' => 4],
        ], $statements);
    }

    public function test_mysql_backslash_escapes_backticks_and_hash_comments(): void
    {
        $statements = $this->split(DatabaseDriver::MySql, <<<'SQL'
            # Kommentar; Hash
            insert into `t;1` values ('a\';b', "c\";d");
            select 5--3;
            SQL);

        $this->assertSame([
            "insert into `t;1` values ('a\\';b', \"c\\\";d\")",
            'select 5--3',
        ], array_column($statements, 'sql'));
    }

    public function test_postgres_dollar_quotes_and_escape_strings(): void
    {
        $statements = $this->split(DatabaseDriver::Postgres, <<<'SQL'
            create function f() returns int language plpgsql as $$ begin perform 1; return 2; end $$;
            create function g() returns text language sql as $body$ select 'a;$$;b' $body$;
            select E'x\';y', 'z\';
            SQL);

        $this->assertCount(3, $statements);
        $this->assertStringEndsWith('end $$', $statements[0]['sql']);
        $this->assertStringEndsWith('$body$', $statements[1]['sql']);
        $this->assertSame("select E'x\\';y', 'z\\'", $statements[2]['sql']);
    }

    /**
     * @return array<string, array{0: DatabaseDriver, 1: string}>
     */
    public static function triggers(): array
    {
        return [
            'SQLite' => [DatabaseDriver::Sqlite, "create trigger t after insert on orders when new.note is null begin update orders set note = case when 1 then 'offen' end where id = new.id; select 1; end;\nselect 2;"],
            'MySQL ohne DELIMITER' => [DatabaseDriver::MySql, "create definer=`root`@`localhost` trigger t before insert on orders for each row begin if new.note is null then set new.note = 'offen'; end if; end;\nselect 2;"],
            'MySQL mit DELIMITER' => [DatabaseDriver::MariaDb, "DELIMITER ;;\ncreate trigger t before insert on orders for each row begin set new.note = 'x'; end;;\nDELIMITER ;\nselect 2;"],
        ];
    }

    #[DataProvider('triggers')]
    public function test_routine_bodies_stay_one_statement(DatabaseDriver $driver, string $sql): void
    {
        $statements = $this->split($driver, $sql);

        $this->assertCount(2, $statements);
        $this->assertStringStartsWith('create', $statements[0]['sql']);
        $this->assertMatchesRegularExpression('/end$/', $statements[0]['sql']);
        $this->assertSame(['sql' => 'select 2', 'line' => $driver === DatabaseDriver::MariaDb ? 4 : 2], $statements[1]);
    }

    public function test_mysql_executable_comments_count_as_statements_and_pure_comments_do_not(): void
    {
        $statements = $this->split(DatabaseDriver::MySql, "/*!40101 SET @OLD=@@SQL_MODE */;\n/* nur Kommentar */;\n-- auch nur\n;\nselect 1");

        $this->assertSame(['/*!40101 SET @OLD=@@SQL_MODE */', 'select 1'], array_column($statements, 'sql'));
        $this->assertSame([1, 5], array_column($statements, 'line'));
    }

    public function test_statements_spanning_read_chunks_stay_intact(): void
    {
        $long = str_repeat("Zeile mit ; und ' im Text'' ", 60_000);
        $statements = $this->split(DatabaseDriver::Sqlite, "insert into t values ('".str_replace("'", "''", $long)."');\nselect 1;");

        $this->assertCount(2, $statements);
        $this->assertSame(strlen("insert into t values ('".str_replace("'", "''", $long)."')"), strlen($statements[0]['sql']));
        $this->assertSame(['sql' => 'select 1', 'line' => 2], $statements[1]);
    }

    public function test_keyword_skips_leading_comments(): void
    {
        $this->assertSame('INSERT', SqlScript::keyword("-- x\n/* y */ insert into t values (1)"));
        $this->assertSame('SET', SqlScript::keyword('/*!40101 SET NAMES utf8 */'));
    }

    /**
     * @return list<array{sql: string, line: int}>
     */
    private function split(DatabaseDriver $driver, string $sql): array
    {
        $file = tempnam(sys_get_temp_dir(), 'sqlscript');
        $this->assertNotFalse($file);
        $this->files[] = $file;
        file_put_contents($file, $sql);

        return iterator_to_array((new SqlScript($file, $driver))->statements(), false);
    }
}
