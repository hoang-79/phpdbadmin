<?php

namespace Tests\Unit\Services\Audit;

use App\Services\Audit\Masking;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Maskierung für Protokoll und Meldungen (B-04, T-11): Literale und gebundene Werte werden zu `?`, Bezeichner und
 * Aufbau der Anweisung bleiben lesbar.
 */
class MaskingTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function statements(): array
    {
        return [
            'Zeichenkette und Zahl' => [
                "UPDATE `orders` SET `status` = 'bezahlt' WHERE `id` = 42",
                'UPDATE `orders` SET `status` = ? WHERE `id` = ?',
            ],
            'verdoppeltes und maskiertes Anführungszeichen' => [
                "insert into notes (text) values ('O''Reilly'), ('it\\'s')",
                'insert into notes (text) values (?), (?)',
            ],
            'Zahlen in Bezeichnern bleiben' => [
                'select col1, t2.x3 from t2 where y = -1.5e3 limit 200',
                'select col1, t2.x3 from t2 where y = ? limit ?',
            ],
            'Bezeichner in Anführungszeichen (PostgreSQL) bleiben' => [
                'select "Kunde 1" from "public"."orders" where "id" = 7',
                'select "Kunde 1" from "public"."orders" where "id" = ?',
            ],
            'Platzhalter bleiben' => [
                'update orders set note = ? where id = $1 and x = :wert',
                'update orders set note = ? where id = $1 and x = :wert',
            ],
            'Zeichenkette über mehrere Zeilen' => [
                "update notes set text = 'Zeile 1\nZeile 2' where id = 3",
                'update notes set text = ? where id = ?',
            ],
        ];
    }

    #[DataProvider('statements')]
    public function test_sql_literals_become_placeholders(string $sql, string $expected): void
    {
        $this->assertSame($expected, Masking::sql($sql));
    }

    public function test_double_quotes_are_strings_only_where_the_driver_says_so(): void
    {
        $sql = 'update t set name = "geheim" where id = 1';

        $this->assertSame('update t set name = "geheim" where id = ?', Masking::sql($sql));
        $this->assertSame('update t set name = ? where id = ?', Masking::sql($sql, doubleQuotedStrings: true));
    }

    public function test_bindings_inserted_by_laravel_are_masked_in_sql_and_messages(): void
    {
        $bindings = ['nina@kunde-shop.ch', 4711, new DateTimeImmutable('2026-10-28 10:31:40')];
        $message = 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry nina@kunde-shop.ch for key users_email '
            .'(Connection: mysql, SQL: insert into users (email, id, seen) values (nina@kunde-shop.ch, 4711, 2026-10-28 10:31:40))';

        $masked = Masking::message($message, $bindings);

        $this->assertStringNotContainsString('nina@kunde-shop.ch', $masked);
        $this->assertStringNotContainsString('4711', $masked);
        $this->assertStringNotContainsString('2026-10-28', $masked);
        $this->assertStringContainsString('1062 Duplicate entry ? for key users_email', $masked);
    }

    public function test_short_bindings_only_match_whole_words(): void
    {
        $this->assertSame('SQLSTATE[23000]: 1062 Fehler in Zeile ?', Masking::message('SQLSTATE[23000]: 1062 Fehler in Zeile 1', [1]));
    }

    #[TestWith(["SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'nina@kunde-shop.ch' for key 'users.users_email_unique'", 'Duplicate entry ? for key ?'])]
    #[TestWith(["SQLSTATE[42000]: Syntax error: 1064 You have an error in your SQL syntax; check the manual near ''geheim' WHERE id = 1' at line 1", 'near ? at line 1'])]
    #[TestWith(['SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "users_email_unique" DETAIL:  Key (email)=(nina@kunde-shop.ch) already exists.', 'Key (email)=(?) already exists.'])]
    #[TestWith(['SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "name" DETAIL:  Failing row contains (1, nina@kunde-shop.ch, null).', 'Failing row contains (?).'])]
    #[TestWith(['SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type integer: "nina@kunde-shop.ch"', 'invalid input syntax for type integer: ?'])]
    public function test_values_quoted_by_the_servers_are_masked_without_bindings(string $message, string $expected): void
    {
        $masked = Masking::message($message);

        $this->assertStringContainsString($expected, $masked);
        $this->assertStringNotContainsString('nina@kunde-shop.ch', $masked);
        $this->assertStringNotContainsString('geheim', $masked);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function secretStatements(): array
    {
        return [
            'MySQL CREATE USER' => ["CREATE USER 'anna'@'%' IDENTIFIED BY 'geheim';", "CREATE USER 'anna'@'%' IDENTIFIED BY ?;"],
            'MySQL mit Plugin' => ['alter user anna identified with mysql_native_password by "g\'eh";', 'alter user anna identified with mysql_native_password by ?;'],
            'SET PASSWORD' => ["SET PASSWORD FOR anna = 'x1';", 'SET PASSWORD FOR anna = ?;'],
            'PostgreSQL' => ["ALTER ROLE anna WITH ENCRYPTED PASSWORD 'pg-geheim' VALID UNTIL '2027-01-01';", "ALTER ROLE anna WITH ENCRYPTED PASSWORD ? VALID UNTIL '2027-01-01';"],
            'gewöhnliche Abfrage bleibt' => ["select * from users where name = 'Peter'", "select * from users where name = 'Peter'"],
        ];
    }

    #[DataProvider('secretStatements')]
    public function test_secrets_are_masked_and_the_rest_stays_readable(string $sql, string $expected): void
    {
        $this->assertSame($expected, Masking::secrets($sql));
    }

    public function test_copy_context_of_psql_keeps_no_value(): void
    {
        $this->assertSame(
            "ERROR:  invalid input syntax for type integer: ?\nCONTEXT:  COPY kunden, line 3, column id: ?",
            Masking::message("ERROR:  invalid input syntax for type integer: \"abc\"\nCONTEXT:  COPY kunden, line 3, column id: \"abc\""),
        );
        $this->assertSame('CONTEXT:  COPY kunden, line 3: ?', Masking::message("CONTEXT:  COPY kunden, line 3: \"3\tJürg Müller\tgeheim@example.com\""));
    }
}
