<?php

namespace App\Http\Controllers\Connections;

use App\Enums\ConnectionPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Connections\SharingRequest;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Access\ConnectionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Verbindung teilen (V-09): ein Administrator schaltet das Teilen ein und gibt Konten ein Recht — lesen, schreiben
 * oder verwalten. Der Dialog in der Übersicht holt den Stand als JSON und speichert über Inertia.
 */
class SharingController extends Controller
{
    public function show(DatabaseConnection $connection): JsonResponse
    {
        Gate::authorize('share', $connection);

        $connection->load('memberships');

        return response()->json([
            'shared' => $connection->shared,
            'accounts' => User::query()->orderBy('name')->get()->map(fn (User $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'email' => $account->email,
                'isAdmin' => $account->isAdmin(),
                'isOwner' => $account->id === $connection->owner_id,
                'permission' => $connection->membershipOf($account)?->permission?->value,
            ])->values(),
        ]);
    }

    public function update(SharingRequest $request, DatabaseConnection $connection, ConnectionAccess $access): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        DB::transaction(function () use ($request, $connection, $access, $actor): void {
            $access->setShared($connection, $request->boolean('shared'), $actor);

            if (! $connection->shared) {
                return;
            }

            $connection->load('memberships');
            $accounts = User::query()->findMany(array_keys($request->permissions()))->keyBy('id');

            foreach ($request->permissions() as $accountId => $permission) {
                $account = $accounts->get($accountId);

                if ($account instanceof User) {
                    $access->grant($connection, $account, ConnectionPermission::tryFrom((string) $permission), $actor);
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $connection->shared
            ? __('Freigabe für «:name» gespeichert.', ['name' => $connection->name])
            : __('«:name» ist wieder persönlich.', ['name' => $connection->name])]);

        return back();
    }
}
