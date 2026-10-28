<?php

namespace App\Http\Requests\Workspace\Server;

use App\Enums\ConnectionEnvironment;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Grundlage jeder ändernden Aktion der Server-Verwaltung (W-02, W-04, W-05): nur mit dem Recht «verwalten», nie auf
 * einer schreibgeschützten Verbindung (403, V-04), auf Produktion erst mit dem eingetippten Namen der Verbindung
 * (G-08). Eine fremde Verbindung ist schon an der Routenbindung 404.
 */
abstract class ServerChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $connection = $this->targetConnection();

        return $user instanceof User && ! $connection->read_only && $connection->isManageableBy($user);
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
        return $this->routeIs('workspace.server.*.preview');
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
     * @return array<string, list<mixed>>
     */
    protected function confirmationRules(): array
    {
        $connection = $this->targetConnection();

        if ($this->isPreview() || $connection->environment !== ConnectionEnvironment::Production) {
            return [];
        }

        return ['confirmation' => ['required', 'string', Rule::in([$connection->name])]];
    }

    protected function failedAuthorization(): never
    {
        throw new AccessDeniedHttpException($this->blockedReason());
    }

    private function blockedReason(): string
    {
        return $this->targetConnection()->read_only
            ? __('Diese Verbindung ist schreibgeschützt — Änderungen sind gesperrt.')
            : __('Dafür braucht es das Recht «verwalten» an dieser Verbindung.');
    }
}
