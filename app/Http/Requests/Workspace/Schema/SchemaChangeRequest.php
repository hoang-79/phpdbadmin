<?php

namespace App\Http\Requests\Workspace\Schema;

use App\Connections\WriteUnlock;
use App\Enums\ConnectionEnvironment;
use App\Models\DatabaseConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Grundlage jeder Strukturänderung (S-02 bis S-05): nur mit dem Recht «schreiben» (Policy `write`), nie auf einer
 * schreibgeschützten Verbindung (403, V-04; ausser mit Freigabe auf Zeit, V-11), auf Produktion erst mit dem eingetippten Namen der Verbindung. Eine fremde
 * Verbindung ist schon an der Routenbindung 404. Den Namen der Tabelle bzw. des Schemas zur Bestätigung (Leeren,
 * Löschen, Spalten löschen) prüft SchemaChanges, bevor eine Anweisung läuft — erst der Plan weiss, ob es ihn braucht.
 */
abstract class SchemaChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = $this->targetConnection();

        // Schreibschutz gilt, ausser er ist auf Zeit aufgehoben (V-11) — wie beim Bearbeiten von Zeilen.
        if ($connection->read_only && ! app(WriteUnlock::class)->isActive($connection)) {
            throw new AccessDeniedHttpException(__('Diese Verbindung ist schreibgeschützt — Strukturänderungen sind gesperrt.'));
        }

        return Gate::forUser($this->user())->allows('write', $connection);
    }

    public function targetConnection(): DatabaseConnection
    {
        $connection = $this->route('connection');
        abort_unless($connection instanceof DatabaseConnection, 404);

        return $connection;
    }

    /** Die Vorschau führt nichts aus und braucht deshalb keine Bestätigung. */
    public function isPreview(): bool
    {
        return str_ends_with((string) $this->route()?->getName(), '.preview');
    }

    /** Eingetippter Name der Tabelle bzw. des Schemas (Leeren, Löschen, Spalten löschen). */
    public function confirmedName(): ?string
    {
        $name = $this->validated('confirm_name');

        return is_string($name) ? $name : null;
    }

    /** PostgreSQL mit «alle Datenbanken»: die gewählte Datenbank (geprüft gegen die Liste des Servers). */
    public function database(): ?string
    {
        $database = $this->validated('database');

        return is_string($database) && $database !== '' ? $database : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.required' => __('Geben Sie zur Bestätigung den Namen der Verbindung ein.'),
            'confirmation.in' => __('Der Name stimmt nicht mit dem Namen der Verbindung überein.'),
        ];
    }

    /**
     * Gemeinsame Felder: Datenbank (PostgreSQL), Name zur Bestätigung, auf Produktion der Name der Verbindung.
     *
     * @return array<string, list<mixed>>
     */
    protected function commonRules(): array
    {
        $connection = $this->targetConnection();
        $production = ! $this->isPreview() && $connection->environment === ConnectionEnvironment::Production;

        return [
            'database' => ['nullable', 'string', 'max:255'],
            'confirm_name' => ['nullable', 'string', 'max:255'],
            ...($production ? ['confirmation' => ['required', 'string', Rule::in([$connection->name])]] : []),
        ];
    }

    protected function failedAuthorization(): never
    {
        throw new AccessDeniedHttpException(__('Dafür braucht es das Recht «schreiben» an dieser Verbindung.'));
    }
}
