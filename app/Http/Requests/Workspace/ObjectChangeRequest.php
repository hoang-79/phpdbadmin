<?php

namespace App\Http\Requests\Workspace;

use App\Connections\WriteUnlock;
use App\Enums\ConnectionEnvironment;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Ein Objekt anlegen, ändern oder löschen (S-06): nur mit dem Recht «schreiben», nie auf einer schreibgeschützten
 * Verbindung (403, V-04) — ausser der Schutz ist auf Zeit aufgehoben (V-11); auf Produktion erst mit dem
 * eingetippten Namen der Verbindung (G-08). Ob die Definition zum Objekt passt, prüft der Plan.
 */
class ObjectChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $connection = $this->targetConnection();

        return $user instanceof User
            && Gate::forUser($user)->allows('write', $connection)
            && (! $connection->read_only || app(WriteUnlock::class)->isActive($connection));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $connection = $this->targetConnection();
        $confirm = ! $this->isPreview() && $connection->environment === ConnectionEnvironment::Production;

        return [
            'action' => ['required', Rule::in(['create', 'alter', 'drop'])],
            'name' => ['exclude_if:action,create', 'required', 'string', 'max:255'],
            'qualifier' => ['nullable', 'string', 'max:2000'],
            'definition' => ['exclude_if:action,drop', 'required', 'string', 'max:1000000'],
            ...($confirm ? ['confirmation' => ['required', 'string', Rule::in([$connection->name])]] : []),
        ];
    }

    public function targetConnection(): DatabaseConnection
    {
        $connection = $this->route('connection');
        abort_unless($connection instanceof DatabaseConnection, 404);

        return $connection;
    }

    public function isPreview(): bool
    {
        return $this->routeIs('workspace.definition.preview');
    }

    /** @return 'create'|'alter'|'drop' */
    public function action(): string
    {
        $action = (string) $this->validated('action');

        return match ($action) {
            'create' => 'create',
            'alter' => 'alter',
            default => 'drop',
        };
    }

    public function qualifier(): ?string
    {
        $qualifier = $this->validated('qualifier');

        return is_string($qualifier) ? $qualifier : null;
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
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'definition' => __('Definition'),
            'name' => __('Name'),
            'confirmation' => __('Name der Verbindung'),
        ];
    }

    protected function failedAuthorization(): never
    {
        throw new AccessDeniedHttpException($this->blockedReason());
    }

    private function blockedReason(): string
    {
        return $this->targetConnection()->read_only && Gate::allows('write', $this->targetConnection())
            ? __('Diese Verbindung ist schreibgeschützt. Heben Sie den Schutz auf Zeit auf, um zu ändern.')
            : __('Dafür braucht es das Recht «schreiben» an dieser Verbindung.');
    }
}
