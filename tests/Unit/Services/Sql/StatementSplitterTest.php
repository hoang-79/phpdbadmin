<?php

namespace Tests\Unit\Services\Sql;

use App\Enums\DatabaseDriver;
use App\Services\Sql\StatementSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Zerleger des SQL-Editors (Q-02): Trennzeichen in Zeichenketten, Bezeichnern, Kommentaren, Dollar-Blöcken und
 * Routinen trennen nie; DELIMITER aus MySQL-Dumps wirkt; die Schreibmarke findet ihre Anweisung.
 */
class StatementSplitterTest extends TestCase
{
    /**
     * @return array<string, array{0: DatabaseDriver}>
     */
    public static function drivers(): array
    {
        return [
            'MySQL' => [DatabaseDriver::MySql],
            'MariaDB' => [DatabaseDriver::MariaDb],
            'PostgreSQL' => [DatabaseDriver::Postgres],
            'SQLite' => [DatabaseDriver::Sqlite],
        ];
    }

    #[DataProvider('drivers')]
    public function test_splits_on_semicolons_and_keeps_the_last_statement_without_one(DatabaseDriver $driver): void
    {
        $this->assertSame(['select 1', 'select 2', 'select 3'], $this->texts("select 1;\nselect 2 ;\n\n  select 3", $driver));
    }

    #[DataProvider('drivers')]
    public function test_semicolons_in_strings_and_comments_do_not_split(DatabaseDriver $driver): void
    {
        $sql = "select 'a;b', 'it''s; fine' -- kein; Ende\n from t /* auch; nicht */;\nselect 2";

        $this->assertSame(["select 'a;b', 'it''s; fine' -- kein; Ende\n from t /* auch; nicht */", 'select 2'], $this->texts($sql, $driver));
    }

    public function test_mysql_knows_backslash_escapes_backticks_hash_comments_and_minus_minus(): void
    {
        $sql = "select 'it\\'s; ok', `a;b` # Kommentar; hier\n;select 2--1;select 3";

        $this->assertSame(["select 'it\\'s; ok', `a;b` # Kommentar; hier", 'select 2--1', 'select 3'], $this->texts($sql, DatabaseDriver::MySql));
    }

    public function test_postgres_knows_identifiers_dollar_blocks_parameters_and_nested_comments(): void
    {
        $sql = <<<'SQL'
            create function f() returns int language plpgsql as $body$ begin perform 1; return 2; end $body$;
            select "a;b", $1 from t;
            /* aussen /* innen; */ noch; */ select $$x;y$$;
            select E'a\';b'
            SQL;

        $this->assertCount(4, $statements = $this->texts($sql, DatabaseDriver::Postgres));
        $this->assertStringEndsWith('end $body$', $statements[0]);
        $this->assertSame('select "a;b", $1 from t', $statements[1]);
        $this->assertStringEndsWith('select $$x;y$$', $statements[2]);
        $this->assertSame("select E'a\\';b'", $statements[3]);
    }

    public function test_mysql_routine_with_begin_end_if_and_case_stays_one_statement(): void
    {
        $sql = <<<'SQL'
            CREATE DEFINER=`root`@`localhost` PROCEDURE p(IN x INT)
            BEGIN
              DECLARE y INT;
              IF x > 0 THEN SET y = 1; END IF;
              SET y = CASE WHEN x = 1 THEN 2 ELSE 3 END;
              CASE x WHEN 1 THEN SELECT 1; ELSE SELECT 2; END CASE;
              WHILE y < 3 DO SET y = y + 1; END WHILE;
            END;
            SELECT 1;
            SQL;

        $statements = $this->texts($sql, DatabaseDriver::MySql);

        $this->assertCount(2, $statements);
        $this->assertStringEndsWith('END WHILE;'."\n".'END', $statements[0]);
        $this->assertSame('SELECT 1', $statements[1]);
    }

    public function test_sqlite_trigger_with_body_stays_one_statement(): void
    {
        $sql = "create trigger t after insert on a begin update b set n = n + 1; insert into c values (case when 1 then 2 end); end;\nselect 1";

        $this->assertSame(2, count($this->texts($sql, DatabaseDriver::Sqlite)));
    }

    public function test_transaction_keywords_outside_routines_split_normally(): void
    {
        $this->assertSame(['begin', 'update t set a = 1', 'commit'], $this->texts('begin; update t set a = 1; commit;', DatabaseDriver::MariaDb));
    }

    public function test_delimiter_from_mysql_dumps_changes_the_separator(): void
    {
        $sql = "DELIMITER //\nCREATE PROCEDURE p() BEGIN SELECT 1; END //\nDELIMITER ;\nSELECT 2;";

        $this->assertSame(['CREATE PROCEDURE p() BEGIN SELECT 1; END', 'SELECT 2'], $this->texts($sql, DatabaseDriver::MySql));
    }

    #[DataProvider('drivers')]
    public function test_comment_only_parts_are_skipped_and_leading_comments_belong_to_their_statement(DatabaseDriver $driver): void
    {
        $sql = "-- Umsatz je Kunde\nselect 1;\n/* nur Kommentar */;\n-- am Ende";

        $this->assertSame(["-- Umsatz je Kunde\nselect 1"], $this->texts($sql, $driver));
    }

    #[DataProvider('drivers')]
    public function test_statement_at_the_cursor(DatabaseDriver $driver): void
    {
        $sql = "select 'Zürich';\nselect 2;\n\nselect 3";
        $splitter = new StatementSplitter;

        $this->assertSame("select 'Zürich'", $splitter->statementAt($sql, 3, $driver)['sql'] ?? null);
        $this->assertSame("select 'Zürich'", $splitter->statementAt($sql, strpos($sql, ';') + 1, $driver)['sql'] ?? null, 'hinter dem Semikolon');
        $this->assertSame('select 2', $splitter->statementAt($sql, strpos($sql, 'select 2') + 2, $driver)['sql'] ?? null);
        $this->assertSame('select 3', $splitter->statementAt($sql, strlen($sql), $driver)['sql'] ?? null);
        $this->assertNull($splitter->statementAt('-- nur Kommentar', 3, $driver));
    }

    /**
     * @return list<string>
     */
    private function texts(string $sql, DatabaseDriver $driver): array
    {
        return array_column((new StatementSplitter)->split($sql, $driver), 'sql');
    }
}
