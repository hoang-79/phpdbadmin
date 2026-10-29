<?php

namespace App\Services\Transfer;

use App\Connections\TargetConnections;
use App\Enums\DatabaseDriver;
use App\Models\DatabaseConnection;

/**
 * Statistik einer Tabelle aktualisieren (E-06): nach eigenen Importen und auf Knopfdruck. Danach stimmt die
 * geschätzte Zeilenzahl im Navigator wieder, und MySQL/MariaDB sortieren grosse Tabellen über den Index (F-3).
 */
final class Statistics
{
    public function __construct(private readonly TargetConnections $targets) {}

    public function refresh(DatabaseConnection $connection, string $schema, string $table, ?string $database = null): void
    {
        $target = $this->targets->open($connection, $database);

        // Die Sitzung wird gleich wieder beschreibbar — das nur, wenn die Verbindung es jetzt noch ist (T-06): zwischen
        // Einreihen und Ausführen kann sie schreibgeschützt geworden sein.
        $this->targets->assertWritable($connection);

        if ($connection->driver === DatabaseDriver::MySql || $connection->driver === DatabaseDriver::MariaDb) {
            // Ein früherer Lesezugriff im Wächter lässt die Sitzung schreibgeschützt zurück.
            $target->statement('set session transaction read write');
        }

        $connection->driver->dialect()->analyze($target, $schema, $table);
    }
}
