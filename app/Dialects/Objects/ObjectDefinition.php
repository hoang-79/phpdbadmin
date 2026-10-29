<?php

namespace App\Dialects\Objects;

use App\Enums\ObjectKind;

/**
 * Ein Objekt eines Schemas, wie die Detailseite es zeigt (S-06): die Definition zum Ansehen und Kopieren, die Vorlage
 * zum Ändern und was sich darüber sagen lässt.
 *
 * Der Zusatz (`qualifier`) unterscheidet Objekte mit gleichem Namen: bei PostgreSQL die Tabelle eines Triggers (Trigger
 * heissen je Tabelle) und die Parameter einer Routine (Überladung).
 *
 * @phpstan-type Fact array{key: string, value: string}
 * @phpstan-type Reference array{kind: 'table'|'function', schema: string, name: string, qualifier: string|null}
 */
final readonly class ObjectDefinition
{
    /**
     * @param  string  $ddl  Definition zum Ansehen (nur lesen, kopierbar)
     * @param  string|null  $editable  Vorlage zum Ändern; null, wenn sich das Objekt hier nicht ändern lässt
     * @param  string|null  $restore  Definition, die das Objekt so wieder anlegt, wie es ist (ohne DEFINER) — für
     *                                MySQL und MariaDB, wo Löschen und Neuanlegen nicht rückrollbar ist
     * @param  list<Fact>  $facts
     * @param  list<array{name: string, type: string}>  $columns  Spalten einer Sicht bzw. Felder eines Typs
     * @param  list<string>  $values  Werte eines Aufzählungstyps
     * @param  list<Reference>  $references  Tabelle eines Triggers, Funktion eines Triggers (PostgreSQL)
     * @param  list<string>  $overloads  Parameter aller Routinen dieses Namens (PostgreSQL)
     * @param  string|null  $variant  Art eines Typs (enum, composite, domain, range) bzw. CONSTRAINT bei Triggern
     */
    public function __construct(
        public ObjectKind $kind,
        public string $schema,
        public string $name,
        public ?string $qualifier,
        public string $ddl,
        public ?string $editable,
        public ?string $restore = null,
        public array $facts = [],
        public array $columns = [],
        public array $values = [],
        public array $references = [],
        public array $overloads = [],
        public ?string $variant = null,
    ) {}

    /**
     * Für die Seite — ohne `restore`, das nur der Server braucht.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'schema' => $this->schema,
            'name' => $this->name,
            'qualifier' => $this->qualifier,
            'ddl' => $this->ddl,
            'editable' => $this->editable,
            'facts' => $this->facts,
            'columns' => $this->columns,
            'values' => $this->values,
            'references' => $this->references,
            'overloads' => $this->overloads,
            'variant' => $this->variant,
        ];
    }
}
