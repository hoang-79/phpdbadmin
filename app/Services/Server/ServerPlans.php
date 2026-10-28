<?php

namespace App\Services\Server;

use App\Dialects\PrivilegeTarget;
use App\Enums\AccountAction;
use App\Enums\DatabaseDriver;
use App\Enums\MaintenanceOperation;
use App\Enums\PrivilegeLevel;
use App\Models\DatabaseConnection;
use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;

/**
 * Plant ändernde Aktionen der Server-Verwaltung (W-02, W-04, W-05) aus geprüften Eingaben. Jeder Name eines Ziels
 * muss im Katalog stehen — was dort nicht steht, wird abgewiesen, bevor eine Anweisung entsteht. Vorschau und
 * Ausführen planen über dieselbe Methode, damit die Vorschau genau zeigt, was läuft.
 */
final class ServerPlans
{
    public function __construct(private readonly ServerInspector $inspector) {}

    /**
     * Prozess beenden (W-02) — nie die Sitzung, über die phpdbadmin selbst arbeitet.
     *
     * @throws ValidationException
     */
    public function endProcess(Connection $target, DatabaseConnection $connection, int $id, bool $wholeSession): ServerPlan
    {
        $dialect = $connection->driver->dialect();
        $statement = $dialect->endProcessStatement($id, $wholeSession);

        if ($statement === null) {
            throw ValidationException::withMessages(['process' => __('SQLite hat keine Serverprozesse.')]);
        }

        if ($dialect->sessionId($target) === $id) {
            throw ValidationException::withMessages(['process' => __('Das ist die Sitzung, über die phpdbadmin gerade arbeitet.')]);
        }

        return new ServerPlan('server.kill', '#'.$id, [$statement], context: ['process' => $id, 'mode' => $wholeSession ? 'session' : 'query']);
    }

    /**
     * Konto anlegen, löschen, Rechte erteilen oder entziehen (W-04).
     *
     * @throws ValidationException
     */
    public function account(Connection $target, DatabaseConnection $connection, AccountChange $change): ServerPlan
    {
        $dialect = $connection->driver->dialect();
        $isOwn = $change->account->equals($dialect->currentAccount($target));
        $notes = [];
        $secrets = [];
        $context = ['account' => $change->account->label()];
        $label = $change->account->label();

        switch ($change->action) {
            case AccountAction::Create:
                $password = (string) $change->password;
                $quoted = (string) $target->getPdo()->quote($password);
                $secrets = [$password, $quoted, substr($quoted, 1, -1)];
                $statements = $dialect->createAccountStatements($target, $change->account, $password);
                break;

            case AccountAction::Drop:
                if ($isOwn) {
                    throw ValidationException::withMessages(['name' => __('Mit diesem Konto ist phpdbadmin verbunden — es lässt sich hier nicht löschen.')]);
                }

                $statements = $dialect->dropAccountStatements($target, $change->account);

                if ($connection->driver === DatabaseDriver::Postgres) {
                    $notes[] = __('Hat die Rolle noch Rechte oder eigene Objekte, lehnt PostgreSQL das Löschen ab — entziehen Sie die Rechte zuerst.');
                }
                break;

            case AccountAction::Grant:
            case AccountAction::Revoke:
                $privilegeTarget = $this->checkedTarget($target, $connection, $change->target);
                $statements = $dialect->privilegeStatements($target, $change->action === AccountAction::Grant, $change->account, $privilegeTarget, $change->privileges);
                $label .= ' · '.$privilegeTarget->label();
                $context += ['level' => $privilegeTarget->level->value, 'privileges' => $change->privileges];

                if ($isOwn && $change->action === AccountAction::Revoke) {
                    $notes[] = __('Mit diesem Konto ist phpdbadmin verbunden — ohne diese Rechte fehlen sie auch hier.');
                }

                if ($privilegeTarget->level === PrivilegeLevel::Schema && array_diff($change->privileges, ['USAGE', 'CREATE']) !== []) {
                    $notes[] = __('Rechte auf Tabellen gelten für alle Tabellen, die es jetzt im Schema gibt — nicht für spätere.');
                }
                break;
        }

        if (count($statements) > 1) {
            $notes[] = __('Anweisung für Anweisung: Scheitert eine, bleiben die vorherigen bestehen.');
        }

        return new ServerPlan($change->action->auditAction(), $label, $statements, $notes, $secrets, $context);
    }

    /**
     * Wartung einer Tabelle oder des ganzen Schemas (W-05).
     *
     * @throws ValidationException
     */
    public function maintenance(Connection $target, DatabaseConnection $connection, MaintenanceOperation $operation, string $schema, ?string $table): ServerPlan
    {
        $dialect = $connection->driver->dialect();

        if (! in_array($operation, $dialect->maintenanceOperations(), true)) {
            throw ValidationException::withMessages(['operation' => __('Diese Wartung gibt es für diesen Treiber nicht.')]);
        }

        if (! in_array($schema, $this->inspector->schemasOn($target, $connection), true)) {
            throw ValidationException::withMessages(['schema' => __('Dieses Schema gibt es nicht.')]);
        }

        if ($table !== null && ! $dialect->maintenanceForTables($operation)) {
            throw ValidationException::withMessages(['table' => __('VACUUM gilt bei SQLite immer der ganzen Datei.')]);
        }

        if ($table !== null && ! in_array($table, $this->inspector->tablesOn($target, $connection, $schema, withViews: false), true)) {
            throw ValidationException::withMessages(['table' => __('Diese Tabelle gibt es nicht.')]);
        }

        $statements = $dialect->maintenanceStatements($target, $operation, $schema, $table === null ? null : [$table]);

        if ($statements === []) {
            throw ValidationException::withMessages(['schema' => __('In diesem Schema gibt es keine Tabellen.')]);
        }

        return new ServerPlan(
            'server.maintenance',
            $table === null ? $schema : $schema.'.'.$table,
            $statements,
            [$this->maintenanceNote($connection->driver, $operation)],
            context: ['operation' => $operation->value],
        );
    }

    /**
     * Das Ziel eines Rechts — jeder Name muss im Katalog stehen.
     *
     * @throws ValidationException
     */
    private function checkedTarget(Connection $target, DatabaseConnection $connection, ?PrivilegeTarget $privilegeTarget): PrivilegeTarget
    {
        if ($privilegeTarget === null || ! array_key_exists($privilegeTarget->level->value, $connection->driver->dialect()->privilegeChoices())) {
            throw ValidationException::withMessages(['level' => __('Diese Ebene gibt es für diesen Treiber nicht.')]);
        }

        $isPostgres = $connection->driver === DatabaseDriver::Postgres;
        // PostgreSQL: Schema und Tabelle liegen in der geöffneten Datenbank (vorher gegen die Liste geprüft).
        $needsDatabase = $privilegeTarget->level === PrivilegeLevel::Database || (! $isPostgres && $privilegeTarget->level === PrivilegeLevel::Table);

        if ($needsDatabase && ! in_array($privilegeTarget->database, $this->inspector->databasesOn($target, $connection), true)) {
            throw ValidationException::withMessages(['database' => __('Diese Datenbank gibt es nicht.')]);
        }

        if ($isPostgres && in_array($privilegeTarget->level, [PrivilegeLevel::Schema, PrivilegeLevel::Table], true)
            && ! in_array($privilegeTarget->schema, $this->inspector->schemasOn($target, $connection), true)) {
            throw ValidationException::withMessages(['schema' => __('Dieses Schema gibt es nicht.')]);
        }

        if ($privilegeTarget->level === PrivilegeLevel::Table) {
            $schema = (string) ($isPostgres ? $privilegeTarget->schema : $privilegeTarget->database);

            if (! in_array($privilegeTarget->table, $this->inspector->tablesOn($target, $connection, $schema, withViews: true), true)) {
                throw ValidationException::withMessages(['table' => __('Diese Tabelle gibt es nicht.')]);
            }
        }

        return $privilegeTarget;
    }

    private function maintenanceNote(DatabaseDriver $driver, MaintenanceOperation $operation): string
    {
        return match ($operation) {
            MaintenanceOperation::Analyze => __('Aktualisiert die Statistik, nach der der Server Abfragen plant.'),
            MaintenanceOperation::Optimize => __('Baut die Tabellen neu auf; Schreibzugriffe warten so lange.'),
            MaintenanceOperation::Check => __('Prüft nur und ändert nichts.'),
            MaintenanceOperation::Vacuum => $driver === DatabaseDriver::Sqlite
                ? __('Baut die ganze Datei neu auf und braucht dafür kurz bis doppelt so viel Platz; so lange ist sie gesperrt.')
                : __('Gibt den Platz gelöschter Zeilen frei; Lesen und Schreiben laufen weiter.'),
        };
    }
}
