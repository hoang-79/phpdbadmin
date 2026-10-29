<?php

namespace App\Services\Shape;

use App\Enums\ColumnKind;
use Carbon\CarbonImmutable;

/**
 * Laravel-Migration aus einer bestehenden Tabelle (S-09): `Schema::create` mit Spaltenmethoden, die Laravel auf jedem
 * Treiber versteht — `id()`, `timestamps()`, Längen und Genauigkeit, `nullable`, `default`, Indizes und Fremdschlüssel
 * mit ihren Regeln. Was Laravel nicht kennt (Checks, eigene Typen, Ausdrücke als Vorgabe), steht als Kommentar mit
 * Hinweis in der Datei.
 *
 * Spalten von Fremdschlüsseln bekommen den Ganzzahltyp ihres Ziels — sonst lehnte MySQL den Schlüssel ab, wenn die
 * Tabelle aus PostgreSQL oder SQLite kommt.
 */
final class MigrationWriter
{
    /** @var list<string> */
    private array $notes = [];

    /**
     * @param  list<TableShape>  $referenced  Tabellen, auf die Fremdschlüssel zeigen (für die Ganzzahltypen)
     * @param  string  $origin  Herkunft für den Kopf («MySQL 8.4.11, Datenbank shop»)
     * @return array{fileName: string, code: string, notes: list<string>}
     */
    public function write(TableShape $table, array $referenced, string $origin, ?CarbonImmutable $now = null): array
    {
        $this->notes = [];
        $now ??= CarbonImmutable::now();
        $references = PortableColumns::referenceTypes([$table, ...$referenced])[strtolower($table->name)] ?? [];

        $body = [];
        $skip = [];
        $columns = $table->columns;

        foreach ($columns as $position => $column) {
            if (in_array($column->name, $skip, true)) {
                continue;
            }

            $shortcut = $this->shortcut($table, $column, $columns[$position + 1] ?? null);

            if ($shortcut !== null) {
                [$line, $consumed] = $shortcut;
                $body[] = $line;
                $skip = [...$skip, ...$consumed];

                continue;
            }

            $body[] = $this->column($table, $column, $references[strtolower($column->name)] ?? null);
        }

        $primary = $table->primary();

        if ($primary !== null && ! $table->primaryIsImplicit()) {
            $body[] = '$table->primary('.$this->columnList($primary->columns).');';
        }

        foreach ($table->indexes as $index) {
            if ($index->kind !== IndexShape::PRIMARY && ! $table->isImplicitForeignKeyIndex($index)) {
                $body[] = $this->index($table, $index);
            }
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $body[] = $this->foreignKey($table, $foreignKey);
        }

        foreach ($table->checks as $check) {
            $body[] = '// '.__('Check «:name»: :definition — Laravel kennt keine Checks, bitte von Hand übernehmen.', ['name' => $this->oneLine($check['name']), 'definition' => $this->oneLine($check['definition'])]);
        }

        $imports = [
            'use Illuminate\Database\Migrations\Migration;',
            'use Illuminate\Database\Schema\Blueprint;',
            ...(str_contains(implode("\n", $body), 'DB::raw(') ? ['use Illuminate\Support\Facades\DB;'] : []),
            'use Illuminate\Support\Facades\Schema;',
        ];

        $origin = __('Erzeugt von phpdbadmin aus «:table» (:origin) am :date.', ['table' => $this->oneLine($table->name), 'origin' => $this->oneLine($origin), 'date' => $now->format('d.m.Y')]);

        $code = implode("\n", [
            '<?php',
            '',
            ...$imports,
            '',
            '/**',
            ' * '.$origin,
            ' */',
            'return new class extends Migration',
            '{',
            '    public function up(): void',
            '    {',
            '        Schema::create('.$this->php($table->name).', function (Blueprint $table) {',
            ...array_map(fn (string $line): string => '            '.$line, $body),
            '        });',
            '    }',
            '',
            '    public function down(): void',
            '    {',
            '        Schema::dropIfExists('.$this->php($table->name).');',
            '    }',
            '};',
            '',
        ]);

        return [
            'fileName' => $now->format('Y_m_d_His').'_create_'.(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($table->name)), '_') ?: 'table').'_table.php',
            'code' => $code,
            'notes' => $this->notes,
        ];
    }

    /**
     * Kurzformen, die Laravel-Projekte kennen: `id()`, `timestamps()`, `softDeletes()`, `rememberToken()`.
     *
     * @return array{0: string, 1: list<string>}|null Zeile und die Spalten, die sie abdeckt
     */
    private function shortcut(TableShape $table, ColumnShape $column, ?ColumnShape $next): ?array
    {
        $laravel = $column->laravel;

        if ($column->name === 'id' && $column->autoIncrement && $laravel->type === 'bigInteger' && $laravel->unsigned() && $table->primaryIsImplicit()) {
            return ['$table->id();', ['id']];
        }

        $isTimestamp = fn (?ColumnShape $candidate): bool => $candidate !== null && $candidate->nullable && $candidate->default === null
            && in_array($candidate->laravel->type, ['timestamp', 'dateTime', 'timestampTz'], true) && in_array($candidate->laravel->parameters['precision'] ?? null, [null, 0], true);

        if ($column->name === 'created_at' && $next?->name === 'updated_at' && $isTimestamp($column) && $isTimestamp($next) && $column->laravel->type === $next->laravel->type) {
            return [$column->laravel->type === 'timestampTz' ? '$table->timestampsTz();' : '$table->timestamps();', ['created_at', 'updated_at']];
        }

        if ($column->name === 'deleted_at' && $isTimestamp($column)) {
            return [$column->laravel->type === 'timestampTz' ? '$table->softDeletesTz();' : '$table->softDeletes();', ['deleted_at']];
        }

        if ($column->name === 'remember_token' && $column->nullable && $laravel->type === 'string' && ($laravel->parameters['length'] ?? null) === 100) {
            return ['$table->rememberToken();', ['remember_token']];
        }

        return null;
    }

    private function column(TableShape $table, ColumnShape $column, ?LaravelColumn $reference): string
    {
        $laravel = $reference ?? $column->laravel;
        $line = '$table->'.$this->method($laravel, $column->name);
        $comments = [];

        if ($laravel->note !== null) {
            $comments[] = $laravel->note;
            $this->notes[] = __('Spalte «:column»: :note', ['column' => $column->name, 'note' => $laravel->note]);
        }

        if ($column->nullable) {
            $line .= '->nullable()';
        }

        if ($column->default !== null) {
            $line .= $this->default($column, $laravel);

            if ($column->default->isExpression && ! $column->default->isPortable()) {
                $comments[] = $this->foreignDefaultComment($table);
                $this->notes[] = __('Spalte «:column»: Vorgabe :default ist ein Ausdruck aus :driver.', ['column' => $column->name, 'default' => $column->default->value, 'driver' => $table->driver->label()]);
            }
        }

        if ($column->comment !== null) {
            $line .= '->comment('.$this->php($column->comment).')';
        }

        if ($column->generated !== null) {
            $line .= ($column->generated['type'] === 'virtual' ? '->virtualAs(' : '->storedAs(').$this->php($column->generated['expression']).')';
            $comments[] = $this->generatedComment($table);
        }

        return $line.';'.($comments !== [] ? ' // '.$this->oneLine(implode(' ', $comments)) : '');
    }

    /**
     * Spaltenmethode mit Angaben: `string('email', 100)`, `unsignedBigInteger('kunde_id')`, `decimal('saldo', 10, 2)`.
     */
    private function method(LaravelColumn $laravel, string $name): string
    {
        $parameters = $laravel->parameters;
        $quoted = $this->php($name);

        if ($laravel->isInteger()) {
            if ($laravel->autoIncrement()) {
                $increments = ['tinyInteger' => 'tinyIncrements', 'smallInteger' => 'smallIncrements', 'mediumInteger' => 'mediumIncrements', 'integer' => 'increments', 'bigInteger' => 'bigIncrements'][$laravel->type];

                return $laravel->unsigned() ? $increments.'('.$quoted.')' : $laravel->type.'('.$quoted.', true)';
            }

            return ($laravel->unsigned() ? 'unsigned'.ucfirst($laravel->type) : $laravel->type).'('.$quoted.')';
        }

        $arguments = match ($laravel->type) {
            'string' => isset($parameters['length']) && $parameters['length'] !== LaravelTypes::DEFAULT_STRING_LENGTH ? [(string) $parameters['length']] : [],
            'char' => [(string) ($parameters['length'] ?? 255)],
            'decimal' => ($parameters['total'] ?? 8) === 8 && ($parameters['places'] ?? 2) === 2 ? [] : [(string) ($parameters['total'] ?? 8), (string) ($parameters['places'] ?? 2)],
            'float' => [(string) ($parameters['precision'] ?? 53)],
            'enum', 'set' => [$this->stringList($parameters['allowed'] ?? [])],
            'dateTime', 'timestamp', 'timestampTz', 'dateTimeTz', 'time', 'timeTz' => ($parameters['precision'] ?? 0) > 0 ? [(string) $parameters['precision']] : [],
            'binary' => isset($parameters['length']) ? [(string) $parameters['length'], ...(($parameters['fixed'] ?? false) ? ['true'] : [])] : [],
            'geometry' => isset($parameters['subtype']) ? [$this->php((string) $parameters['subtype'])] : [],
            default => [],
        };

        return $laravel->type.'('.implode(', ', [$quoted, ...$arguments]).')';
    }

    private function foreignDefaultComment(TableShape $table): string
    {
        return __('Vorgabe aus :driver — auf anderen Treibern prüfen.', ['driver' => $table->driver->label()]);
    }

    private function generatedComment(TableShape $table): string
    {
        return __('Ausdruck aus :driver — auf anderen Treibern prüfen.', ['driver' => $table->driver->label()]);
    }

    private function default(ColumnShape $column, LaravelColumn $laravel): string
    {
        $default = $column->default;

        if ($default === null) {
            return '';
        }

        if ($default->isExpression) {
            if (preg_match('/^CURRENT_TIMESTAMP(\(\d+\))?$/', $default->value) === 1 && in_array($laravel->type, ['timestamp', 'dateTime', 'timestampTz', 'dateTimeTz'], true)) {
                return '->useCurrent()';
            }

            return '->default(DB::raw('.$this->php($default->value).'))';
        }

        if ($laravel->type === 'boolean') {
            return '->default('.($default->asBoolean() ? 'true' : 'false').')';
        }

        if ($column->kind === ColumnKind::Number && is_numeric($default->value)) {
            return '->default('.$this->number($default->value).')';
        }

        return '->default('.$this->php($default->value).')';
    }

    private function index(TableShape $table, IndexShape $index): string
    {
        [$method, $suffix] = match ($index->kind) {
            IndexShape::UNIQUE => ['unique', 'unique'],
            IndexShape::FULLTEXT => ['fullText', 'fulltext'],
            IndexShape::SPATIAL => ['spatialIndex', 'spatialindex'],
            default => ['index', 'index'],
        };

        $columns = count($index->columns) === 1 ? $this->php($index->columns[0]) : $this->columnList($index->columns);
        $name = $index->name === $this->defaultName($table->name, $index->columns, $suffix) ? '' : ', '.$this->php($index->name);

        return '$table->'.$method.'('.$columns.$name.');';
    }

    private function foreignKey(TableShape $table, ForeignKeyShape $foreignKey): string
    {
        $columns = count($foreignKey->columns) === 1 ? $this->php($foreignKey->columns[0]) : $this->columnList($foreignKey->columns);
        $references = count($foreignKey->foreignColumns) === 1 ? $this->php($foreignKey->foreignColumns[0]) : $this->columnList($foreignKey->foreignColumns);
        $name = $foreignKey->name === null || $foreignKey->name === $this->defaultName($table->name, $foreignKey->columns, 'foreign') ? '' : ', '.$this->php($foreignKey->name);

        $line = '$table->foreign('.$columns.$name.')->references('.$references.')->on('.$this->php($foreignKey->foreignTable).')';
        $line .= match ($foreignKey->onDelete) {
            'cascade' => '->cascadeOnDelete()',
            'set null' => '->nullOnDelete()',
            'no action' => '',
            default => '->onDelete('.$this->php($foreignKey->onDelete).')',
        };
        $line .= match ($foreignKey->onUpdate) {
            'cascade' => '->cascadeOnUpdate()',
            'no action' => '',
            default => '->onUpdate('.$this->php($foreignKey->onUpdate).')',
        };

        return $line.';';
    }

    /**
     * Laravels eigener Name für Index und Schlüssel — dann braucht die Migration keinen.
     *
     * @param  list<string>  $columns
     */
    private function defaultName(string $table, array $columns, string $suffix): string
    {
        return str_replace(['-', '.'], '_', strtolower($table.'_'.implode('_', $columns).'_'.$suffix));
    }

    /**
     * @param  list<string>  $columns
     */
    private function columnList(array $columns): string
    {
        return $this->stringList($columns);
    }

    /**
     * @param  list<string>  $values
     */
    private function stringList(array $values): string
    {
        return '['.implode(', ', array_map(fn (string $value): string => $this->php($value), $values)).']';
    }

    /** PHP-Zeichenkette in einfachen Anführungszeichen. */
    private function php(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    private function number(string $value): string
    {
        return (string) (str_contains($value, '.') || stripos($value, 'e') !== false ? (float) $value : (int) $value);
    }

    /**
     * Für Kommentare aus Namen und Definitionen der Datenbank: keine Zeilenumbrüche (sie beendeten den Kommentar), kein
     * «*\/» (es beendete den Kopf) und kein «?>» — das beendete in einem Zeilenkommentar PHP, und was danach käme,
     * liefe beim Ausführen der Migration als Code.
     */
    private function oneLine(string $text): string
    {
        return str_replace(['*/', '?>', "\r", "\n"], ['* /', '? >', ' ', ' '], $text);
    }
}
