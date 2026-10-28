<?php

namespace App\Http\Controllers\Workspace\Server;

use App\Dialects\PlannedStatement;
use App\Enums\AccountAction;
use App\Enums\DatabaseDriver;
use App\Enums\PrivilegeLevel;
use App\Http\Requests\Workspace\Server\AccountChangeRequest;
use App\Models\DatabaseConnection;
use App\Services\Server\AccountChange;
use App\Services\Server\ServerPlan;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Datenbank-Benutzer, Rollen und Rechte (W-04): Liste mit Rechten; anlegen, löschen, erteilen und entziehen immer
 * zuerst als Vorschau (Passwort maskiert), ausgeführt erst nach der Bestätigung. SQLite kennt keine Konten.
 */
class AccountController extends ServerController
{
    public function index(Request $request, DatabaseConnection $connection): Response|RedirectResponse
    {
        return $this->page($request, $connection, 'accounts', function (?string $database) use ($request, $connection): array {
            if (! $connection->driver->isServer()) {
                return ['accounts' => null, 'accountsProblem' => null, 'levels' => [], 'hasHosts' => false, 'databases' => [], 'schemas' => null];
            }

            $dialect = $connection->driver->dialect();
            $listing = $this->inspector->accounts($connection, $database);

            return [
                'accounts' => $listing['accounts'],
                'accountsProblem' => $listing['problem'],
                'levels' => $dialect->privilegeChoices(),
                'hasHosts' => $dialect->accountsHaveHosts(),
                ...$this->inspector->privilegeTargets($connection, $database),
                // Tabellen erst, wenn im Dialog eine Datenbank bzw. ein Schema gewählt ist (?tables_in=).
                'tables' => Inertia::optional(fn (): array => $this->inspector->tables($connection, $database, (string) $request->query('tables_in', ''), withViews: true)),
            ];
        });
    }

    public function preview(AccountChangeRequest $request, DatabaseConnection $connection): JsonResponse
    {
        $change = $request->change();

        return $this->previewResponse($connection, fn (): ServerPlan => $this->changes->preview(
            $connection,
            fn (Connection $target): ServerPlan => $this->plans->account($target, $connection, $change),
            $this->databaseFor($connection, $change),
        ));
    }

    public function apply(AccountChangeRequest $request, DatabaseConnection $connection): RedirectResponse
    {
        $change = $request->change();
        $user = $this->user($request);

        return $this->attempt($connection, function () use ($connection, $change, $user): RedirectResponse {
            $this->changes->apply(
                $connection,
                $user,
                fn (Connection $target): ServerPlan => $this->plans->account($target, $connection, $change),
                fn (Connection $target, PlannedStatement $statement): bool => $target->statement($statement->sql),
                $this->databaseFor($connection, $change),
            );

            Inertia::flash('toast', ['type' => 'success', 'message' => $this->doneMessage($change)]);

            return back();
        });
    }

    /** PostgreSQL: Rechte auf Schema und Tabelle gelten in der gewählten Datenbank — nur einer aus der Liste. */
    private function databaseFor(DatabaseConnection $connection, AccountChange $change): ?string
    {
        $target = $change->target;

        return $connection->driver === DatabaseDriver::Postgres && $target !== null && in_array($target->level, [PrivilegeLevel::Schema, PrivilegeLevel::Table], true)
            ? $this->inspector->database($connection, $target->database)
            : null;
    }

    private function doneMessage(AccountChange $change): string
    {
        $account = $change->account->label();

        return match ($change->action) {
            AccountAction::Create => __('Konto :account angelegt.', ['account' => $account]),
            AccountAction::Drop => __('Konto :account gelöscht.', ['account' => $account]),
            AccountAction::Grant => __('Rechte für :account erteilt.', ['account' => $account]),
            AccountAction::Revoke => __('Rechte für :account entzogen.', ['account' => $account]),
        };
    }
}
