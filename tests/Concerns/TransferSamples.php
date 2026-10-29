<?php

namespace Tests\Concerns;

use App\Enums\DatabaseDriver;
use App\Enums\TransferFormat;
use App\Enums\TransferKind;
use App\Enums\TransferStatus;
use App\Models\DatabaseConnection;
use App\Models\Transfer;
use App\Services\Transfer\TransferRunner;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;

/**
 * Beispieldaten für Export und Import (E-01, E-02): Werte, an denen ein Format scheitern kann — Umlaute, Emoji,
 * Anführungszeichen, Backslash, Semikolon, Zeilenumbruch, $$, Text, der wie SQL aussieht, leerer Text gegen NULL,
 * Binärdaten mit Nullbyte, Dezimal- und Kommazahlen, Wahrheitswerte, JSON. Dazu ein Fremdschlüssel, ein
 * zusammengesetzter Schlüssel, eine Tabelle ohne Schlüssel und eine Sicht.
 */
trait TransferSamples
{
    /** Texte, die ein Export verlustfrei tragen muss. */
    protected const array TRICKY_TEXTS = [
        'Jürg Müller',
        "O'Reilly \"Bob\"",
        'C:\\Pfad\\mit\\Backslash\\',
        "x'); DROP TABLE kunden; --",
        "Zeile 1\nZeile 2\r\nZeile 3",
        '$$ Dollar $tag$ ; ',
        '',
        'Emoji 🙂 und ß',
        '  führende Leerzeichen',
        "Komma, Strichpunkt; Tab\tEnde",
    ];

    protected function createTransferSamples(Connection $connection, DatabaseDriver $driver): void
    {
        $schema = $connection->getSchemaBuilder();

        $schema->create('kunden', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('notiz')->nullable();
            $table->decimal('betrag', 10, 2)->default(0);
            $table->double('wert')->nullable();
            $table->boolean('aktiv')->default(false);
            $table->binary('foto')->nullable();
            $table->dateTime('erstellt')->nullable();
            $table->json('meta')->nullable();
        });

        $schema->create('bestellungen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kunde_id')->constrained('kunden')->cascadeOnDelete();
            $table->foreignId('vorgaenger_id')->nullable()->constrained('bestellungen');
            $table->string('status', 20);
        });

        $schema->create('positionen', function (Blueprint $table) {
            $table->unsignedBigInteger('bestellung_id');
            $table->unsignedInteger('nr');
            $table->string('artikel');
            $table->primary(['bestellung_id', 'nr']);
        });

        $schema->create('protokoll', function (Blueprint $table) {
            $table->string('eintrag')->nullable();
            $table->integer('zahl')->nullable();
        });

        foreach (self::TRICKY_TEXTS as $index => $text) {
            $connection->table('kunden')->insert([
                'name' => $text,
                'notiz' => $index % 3 === 0 ? null : 'Notiz '.$index.' «'.$text.'»',
                'betrag' => $index * 1234.5,
                'wert' => $index === 2 ? -1.0E-12 : null,
                'aktiv' => $index % 2 === 0,
                'erstellt' => $index === 4 ? null : '2026-10-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).' 13:4'.$index.':00',
                'meta' => $index === 0 ? '{"b": 1, "a": [1, 2], "ä": "ö"}' : null,
            ]);
        }

        // Als Literal: Laravel bindet Kommazahlen als Text mit 14 Stellen (aus 0.30000000000000004 würde 0.3).
        $connection->statement('update kunden set wert = 0.30000000000000004 where id = 2');

        // Binärdaten als Literal: als Text gebunden schneidet PostgreSQL am Nullbyte ab.
        $bytes = $driver === DatabaseDriver::Postgres ? "decode('0001fffe616263', 'hex')" : "x'0001fffe616263'";
        $connection->statement('update kunden set foto = '.$bytes.' where id = 1');

        $connection->table('bestellungen')->insert([
            ['kunde_id' => 1, 'vorgaenger_id' => null, 'status' => 'offen'],
            ['kunde_id' => 2, 'vorgaenger_id' => 1, 'status' => 'bezahlt'],
            ['kunde_id' => 1, 'vorgaenger_id' => 2, 'status' => 'storniert'],
        ]);
        $connection->table('positionen')->insert([
            ['bestellung_id' => 1, 'nr' => 1, 'artikel' => 'Käse'],
            ['bestellung_id' => 1, 'nr' => 2, 'artikel' => 'Brot'],
            ['bestellung_id' => 2, 'nr' => 1, 'artikel' => 'Wein; rot'],
            ['bestellung_id' => 3, 'nr' => 10, 'artikel' => 'Öl'],
            ['bestellung_id' => 3, 'nr' => 2, 'artikel' => 'Salz'],
        ]);
        $connection->table('protokoll')->insert([
            ['eintrag' => 'doppelt', 'zahl' => 1],
            ['eintrag' => 'doppelt', 'zahl' => 1],
            ['eintrag' => null, 'zahl' => null],
            ['eintrag' => 'zuletzt', 'zahl' => -5],
        ]);

        $connection->statement('create view aktive_kunden as select id, name from kunden where aktiv');
    }

    /**
     * Inhalt einer Tabelle, vergleichbar: Ströme gelesen, Zeilen sortiert.
     *
     * @return list<array<string, mixed>>
     */
    protected function snapshot(Connection $connection, string $table): array
    {
        $rows = array_map(
            fn (object $row): array => array_map(fn (mixed $value): mixed => is_resource($value) ? stream_get_contents($value) : $value, (array) $row),
            $connection->table($table)->get()->all(),
        );
        usort($rows, fn (array $a, array $b): int => strcmp(serialize($a), serialize($b)));

        return $rows;
    }

    /**
     * Auftrag anlegen und gleich ausführen (Warteschlange «sync» in den Tests).
     *
     * @param  array<string, mixed>  $options
     */
    protected function runTransfer(DatabaseConnection $connection, TransferKind $kind, TransferFormat $format, array $options, ?string $sourceFile = null, bool $dryRun = false): Transfer
    {
        $transfer = Transfer::factory()->for($connection->owner)->for($connection, 'databaseConnection')->create([
            'kind' => $kind,
            'format' => $format,
            'status' => TransferStatus::Draft,
            'dry_run' => $dryRun,
            'target' => $options['schema'].(isset($options['table']) ? '.'.$options['table'] : ''),
            'options' => $options,
            'file_name' => 'datei.'.$format->extension(),
        ]);

        if ($sourceFile !== null) {
            $path = $transfer->directory().'/source.'.$format->extension();
            Storage::disk(Transfer::DISK)->put($path, (string) file_get_contents($sourceFile));
            $transfer->forceFill(['file_path' => $path, 'file_size' => filesize($sourceFile)])->save();
        }

        app(TransferRunner::class)->start($transfer);

        return $transfer->refresh();
    }

    protected function exportedFile(Transfer $transfer): string
    {
        $this->assertSame(TransferStatus::Done, $transfer->status, json_encode($transfer->error) ?: '');

        return Storage::disk(Transfer::DISK)->path((string) $transfer->file_path);
    }
}
