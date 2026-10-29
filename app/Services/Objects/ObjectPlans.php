<?php

namespace App\Services\Objects;

use App\Dialects\Objects\ObjectDefinition;
use App\Dialects\Objects\ObjectDialect;
use App\Dialects\Objects\ObjectDialects;
use App\Enums\DatabaseDriver;
use App\Enums\ObjectKind;
use App\Services\Sql\StatementSplitter;
use Illuminate\Database\Connection;

/**
 * Plant Änderungen an Objekten (S-06): anlegen, ändern, löschen. Eine selbst geschriebene Definition muss genau eine
 * Anweisung sein, die ein Objekt der Art auf der Seite im gewählten Schema anlegt bzw. ändert — sonst wird sie
 * abgewiesen, bevor etwas läuft (DefinitionRejected).
 *
 * Ändern: `CREATE OR REPLACE` und `ALTER` laufen als eine Anweisung; ein `CREATE` ohne OR REPLACE heisst löschen und
 * neu anlegen — in PostgreSQL und SQLite in einer Transaktion, in MySQL und MariaDB ohne (DDL beendet dort jede
 * Transaktion) und mit der bisherigen Definition zum Wiederherstellen.
 */
final class ObjectPlans
{
    public function __construct(private readonly StatementSplitter $splitter) {}

    public function create(Connection $target, DatabaseDriver $driver, ObjectKind $kind, string $schema, string $definition): ObjectPlan
    {
        $dialect = ObjectDialects::for($driver);
        [$sql, $head] = $this->read($definition, $driver, $kind, $schema);

        if ($head->verb !== 'create') {
            throw new DefinitionRejected($this->createOnlyText());
        }

        $qualifier = $kind === ObjectKind::Trigger && $driver === DatabaseDriver::Postgres ? $head->table : null;

        if ($head->orReplace && $dialect->describe($target, $schema, $kind, $head->name, $qualifier) !== null) {
            throw new DefinitionRejected($this->existsText($head->name));
        }

        $context = $dialect->contextStatement($schema);

        return new ObjectPlan(
            'object.create',
            $kind,
            $schema,
            $head->name,
            $qualifier,
            [...($context !== null ? [$context] : []), $sql],
            $dialect->transactionalDdl(),
            $this->notes($dialect, $driver, $kind, $context !== null, false),
        );
    }

    public function alter(DatabaseDriver $driver, ObjectDefinition $existing, string $definition): ObjectPlan
    {
        $dialect = ObjectDialects::for($driver);

        if ($existing->editable === null) {
            throw new DefinitionRejected($this->unreadableText());
        }

        [$sql, $head] = $this->read($definition, $driver, $existing->kind, $existing->schema);

        if (! $head->names($existing->name)) {
            throw new DefinitionRejected($this->renamedText($existing->name));
        }

        $table = $this->triggerTable($existing);

        if ($existing->kind === ObjectKind::Trigger && $head->table !== null && $table !== null && ! DefinitionHead::sameName($head->table, $table, $driver)) {
            throw new DefinitionRejected($this->otherTableText($table));
        }

        $replace = $head->verb === 'create' && ! $head->orReplace;
        $context = $dialect->contextStatement($existing->schema);
        $statements = $context !== null ? [$context] : [];
        $dropIndex = null;

        if ($replace) {
            $dropIndex = count($statements);
            $statements[] = $dialect->dropStatement($existing);
        }

        $statements[] = $sql;

        return new ObjectPlan(
            'object.alter',
            $existing->kind,
            $existing->schema,
            $existing->name,
            $existing->qualifier,
            $statements,
            $dialect->transactionalDdl(),
            $this->notes($dialect, $driver, $existing->kind, $context !== null, $replace, $head->orReplace),
            $replace && ! $dialect->transactionalDdl() ? $existing->restore : null,
            $dropIndex,
        );
    }

    public function drop(DatabaseDriver $driver, ObjectDefinition $existing): ObjectPlan
    {
        $dialect = ObjectDialects::for($driver);

        return new ObjectPlan(
            'object.drop',
            $existing->kind,
            $existing->schema,
            $existing->name,
            $existing->qualifier,
            [$dialect->dropStatement($existing)],
            $dialect->transactionalDdl(),
            [$driver === DatabaseDriver::Postgres ? $this->dropDependentsText() : $this->dropUsersText()],
        );
    }

    /**
     * Genau eine Anweisung, die ein Objekt dieser Art im Schema betrifft.
     *
     * @return array{0: string, 1: DefinitionHead}
     */
    private function read(string $definition, DatabaseDriver $driver, ObjectKind $kind, string $schema): array
    {
        $statements = $this->splitter->split($definition, $driver);

        if ($statements === []) {
            throw new DefinitionRejected($this->emptyText());
        }

        if (count($statements) > 1) {
            throw new DefinitionRejected($this->manyText(count($statements)));
        }

        $sql = $statements[0]['sql'];
        $head = DefinitionHead::parse($sql, $driver);

        if ($head === null || ! $head->isKind($kind)) {
            throw new DefinitionRejected($this->wrongKindText($kind));
        }

        if ($head->temporary) {
            throw new DefinitionRejected($this->temporaryText());
        }

        if (! $head->isIn($schema)) {
            throw new DefinitionRejected($this->wrongSchemaText($schema));
        }

        if ($driver === DatabaseDriver::Sqlite && $schema !== 'main' && $head->schema === null) {
            throw new DefinitionRejected($this->attachedSchemaText($schema));
        }

        if ($kind === ObjectKind::Trigger && $head->tableSchema !== null && ! DefinitionHead::sameName($head->tableSchema, $schema, $driver)) {
            throw new DefinitionRejected($this->triggerSchemaText($schema));
        }

        return [$sql, $head];
    }

    private function triggerTable(ObjectDefinition $existing): ?string
    {
        foreach ($existing->references as $reference) {
            if ($reference['kind'] === 'table') {
                return $reference['name'];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function notes(ObjectDialect $dialect, DatabaseDriver $driver, ObjectKind $kind, bool $withContext, bool $replace, bool $inPlace = false): array
    {
        $notes = [];

        if ($withContext) {
            $notes[] = $this->contextText();
        }

        if ($replace && ! $dialect->transactionalDdl()) {
            $notes[] = $this->notReversibleText($driver->label());
        } elseif ($replace) {
            $notes[] = $this->replaceText();
        }

        if ($dialect->transactionalDdl()) {
            $notes[] = $this->transactionText();
        }

        if ($inPlace && $driver === DatabaseDriver::Postgres && in_array($kind, [ObjectKind::Function, ObjectKind::Procedure], true)) {
            $notes[] = $this->signatureText();
        }

        if ($inPlace && $driver === DatabaseDriver::Postgres && $kind === ObjectKind::View) {
            $notes[] = $this->viewColumnsText();
        }

        return $notes;
    }

    private function kindLabel(ObjectKind $kind): string
    {
        return match ($kind) {
            ObjectKind::View => __('Sicht'),
            ObjectKind::Function => __('Funktion'),
            ObjectKind::Procedure => __('Prozedur'),
            ObjectKind::Trigger => __('Trigger'),
            ObjectKind::Event => __('Ereignis'),
            ObjectKind::Sequence => __('Sequenz'),
            ObjectKind::Type => __('Typ'),
        };
    }

    private function emptyText(): string
    {
        return __('Da steht keine Anweisung — nur Kommentare oder nichts.');
    }

    private function manyText(int $count): string
    {
        return __('Bitte genau eine Anweisung — hier stehen :count.', ['count' => $count]);
    }

    private function wrongKindText(ObjectKind $kind): string
    {
        return __('Die Anweisung muss mit CREATE oder ALTER beginnen und ein Objekt der Art «:kind» betreffen.', ['kind' => $this->kindLabel($kind)]);
    }

    private function createOnlyText(): string
    {
        return __('Zum Anlegen beginnt die Anweisung mit CREATE.');
    }

    private function temporaryText(): string
    {
        return __('Temporäre Objekte verschwinden mit der Sitzung — legen Sie ein dauerhaftes an.');
    }

    private function wrongSchemaText(string $schema): string
    {
        return __('Das Objekt muss im Schema «:schema» liegen.', ['schema' => $schema]);
    }

    private function attachedSchemaText(string $schema): string
    {
        return __('In der angehängten Datenbank «:schema» braucht der Name das Schema davor, etwa ":schema".name.', ['schema' => $schema]);
    }

    private function triggerSchemaText(string $schema): string
    {
        return __('Die Tabelle des Triggers muss im Schema «:schema» liegen.', ['schema' => $schema]);
    }

    private function existsText(string $name): string
    {
        return __('«:name» gibt es schon — ändern Sie es auf seiner Seite.', ['name' => $name]);
    }

    private function renamedText(string $name): string
    {
        return __('Der Name muss «:name» bleiben — für einen anderen Namen legen Sie ein neues Objekt an und löschen das alte.', ['name' => $name]);
    }

    private function otherTableText(string $table): string
    {
        return __('Der Trigger gehört zur Tabelle «:table» — für eine andere Tabelle legen Sie einen neuen an.', ['table' => $table]);
    }

    private function unreadableText(): string
    {
        return __('Die Definition ist für dieses Konto nicht lesbar — ändern lässt sie sich hier deshalb nicht.');
    }

    private function contextText(): string
    {
        return __('Die erste Anweisung wählt das Schema, damit das Objekt dort landet.');
    }

    private function notReversibleText(string $driver): string
    {
        return __('Nicht rückrollbar: :driver kennt dafür kein CREATE OR REPLACE — das Objekt wird gelöscht und neu angelegt. Scheitert das Neuanlegen, stellt phpdbadmin die bisherige Definition wieder her.', ['driver' => $driver]);
    }

    private function replaceText(): string
    {
        return __('Ohne OR REPLACE wird das Objekt gelöscht und neu angelegt.');
    }

    private function transactionText(): string
    {
        return __('In einer Transaktion: Scheitert eine Anweisung, bleibt alles, wie es war.');
    }

    private function signatureText(): string
    {
        return __('Ändern sich die Parametertypen, legt PostgreSQL eine weitere Routine an; die bisherige bleibt bestehen.');
    }

    private function viewColumnsText(): string
    {
        return __('PostgreSQL ersetzt eine Sicht nur, wenn die bisherigen Spalten bleiben — sonst OR REPLACE weglassen: Dann wird sie in der Transaktion gelöscht und neu angelegt.');
    }

    private function dropDependentsText(): string
    {
        return __('Hängt etwas an diesem Objekt (Sichten, Spalten, Trigger), lehnt PostgreSQL das Löschen ab — phpdbadmin löscht nie mit CASCADE.');
    }

    private function dropUsersText(): string
    {
        return __('Was dieses Objekt benutzt (Sichten, Routinen, Trigger), scheitert danach.');
    }
}
