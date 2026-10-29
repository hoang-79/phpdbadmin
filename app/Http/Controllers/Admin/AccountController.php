<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConnectionPermission;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\Concerns\AdminPage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAccountRequest;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Access\ConnectionAccess;
use App\Services\Audit\AuditLog;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verwaltung der Konten (Wireframe 14, A-05, A-07): Liste, ein Konto mit Rechten je Verbindung, Name und Adresse,
 * Rolle, Sperre, Entfernen. Jede Änderung steht im Protokoll.
 */
class AccountController extends Controller
{
    use AdminPage;

    public function __construct(private readonly AuditLog $audit) {}

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function show(Request $request, User $account): Response
    {
        return $this->render($request, $account);
    }

    /** Name und E-Mail-Adresse (A-05): nur hier, nie durch die Person selbst. */
    public function update(UpdateAccountRequest $request, User $account): RedirectResponse
    {
        $before = $account->only(['name', 'email']);
        $account->fill($request->only(['name', 'email']));
        $changed = array_keys($account->getDirty());
        $account->save();

        if ($changed !== []) {
            $this->audit->record('account.updated', $this->actor($request), $account->email, [
                'from' => array_intersect_key($before, array_flip($changed)),
                'to' => $account->only($changed),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Konto «:name» gespeichert.', ['name' => $account->name])]);

        return back();
    }

    public function updateRole(Request $request, User $account): RedirectResponse
    {
        Gate::authorize('changeRole', $account);
        $request->validate(['role' => ['required', Rule::enum(UserRole::class)]], attributes: ['role' => __('Rolle')]);

        $role = UserRole::from($request->string('role')->toString());

        if ($account->role !== $role) {
            $from = $account->role;
            $account->forceFill(['role' => $role])->save();
            $this->audit->record('account.role', $this->actor($request), $account->email, ['from' => $from->value, 'to' => $role->value]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name ist jetzt :role.', ['name' => $account->name, 'role' => $role->label()])]);

        return back();
    }

    /** Sperren oder entsperren: gesperrt heisst keine Anmeldung, laufende Sitzungen enden. */
    public function updateStatus(Request $request, User $account): RedirectResponse
    {
        Gate::authorize('disable', $account);
        $request->validate(['disabled' => ['required', 'boolean']], attributes: ['disabled' => __('Sperre')]);

        $disable = $request->boolean('disabled');

        if ($account->isDisabled() !== $disable) {
            $account->forceFill(['disabled_at' => $disable ? now() : null])->save();

            if ($disable) {
                DB::table('sessions')->where('user_id', $account->id)->delete();
            }

            $this->audit->record($disable ? 'account.disabled' : 'account.enabled', $this->actor($request), $account->email);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $disable
            ? __(':name ist gesperrt.', ['name' => $account->name])
            : __(':name ist wieder freigegeben.', ['name' => $account->name])]);

        return back();
    }

    /** Rechte je geteilter Verbindung (Wireframe 14, «Rechte je Verbindung»). */
    public function updatePermissions(Request $request, User $account, ConnectionAccess $access): RedirectResponse
    {
        $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['nullable', Rule::enum(ConnectionPermission::class)],
        ], attributes: ['permissions.*' => __('Recht')]);

        $actor = $this->actor($request);
        $requested = (array) $request->input('permissions', []);

        DB::transaction(function () use ($requested, $account, $actor, $access): void {
            $connections = DatabaseConnection::query()
                ->where('shared', true)
                ->whereKey(array_map('strval', array_keys($requested)))
                ->with(['memberships' => fn (HasMany $memberships) => $memberships->where('user_id', $account->id)])
                ->get();

            foreach ($connections as $connection) {
                $access->grant($connection, $account, ConnectionPermission::tryFrom((string) $requested[$connection->id]), $actor);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rechte von :name gespeichert.', ['name' => $account->name])]);

        return back();
    }

    /**
     * Konto entfernen: Passkeys, persönliche Verbindungen und Rechte gehen mit; geteilte Verbindungen des Kontos
     * übernimmt, wer entfernt — sonst verschwänden sie für alle anderen. Protokolleinträge bleiben.
     */
    public function destroy(Request $request, User $account): RedirectResponse
    {
        Gate::authorize('delete', $account);
        $actor = $this->actor($request);

        $transferred = DB::transaction(function () use ($account, $actor): int {
            $transferred = $account->ownedConnections()->where('shared', true)->update(['owner_id' => $actor->id]);
            DB::table('sessions')->where('user_id', $account->id)->delete();
            $account->delete();

            return $transferred;
        });

        $this->audit->record('account.deleted', $actor, $account->email, ['name' => $account->name, 'transferred' => $transferred]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Konto «:name» entfernt.', ['name' => $account->name])]);

        return to_route('admin.accounts.index');
    }

    private function render(Request $request, ?User $selected): Response
    {
        $actor = $this->actor($request);

        return Inertia::render('admin/Accounts', [
            'tabs' => $this->tabs(),
            'accounts' => User::query()->withCount('passkeys')->orderBy('name')->get()
                ->map(fn (User $account): array => $this->summary($account, $actor))->values(),
            'selected' => $selected === null ? null : [
                ...$this->summary($selected->loadCount('passkeys'), $actor),
                'createdAt' => $selected->created_at?->toIso8601String(),
                'can' => [
                    'update' => $actor->can('update', $selected),
                    'changeRole' => $actor->can('changeRole', $selected),
                    'disable' => $actor->can('disable', $selected),
                    'delete' => $actor->can('delete', $selected),
                ],
            ],
            'rights' => $selected === null ? [] : $this->rights($selected, $actor),
            'permissions' => array_map(fn (ConnectionPermission $permission): array => [
                'value' => $permission->value,
                'label' => $permission->label(),
                'description' => $permission->description(),
            ], ConnectionPermission::cases()),
            'roles' => array_map(fn (UserRole $role): array => ['value' => $role->value, 'label' => $role->label()], UserRole::cases()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(User $account, User $actor): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
            'role' => $account->role->value,
            'roleLabel' => $account->role->label(),
            'isAdmin' => $account->isAdmin(),
            'disabled' => $account->isDisabled(),
            'lastLoginAt' => $account->last_login_at?->toIso8601String(),
            'hasPasskeys' => (int) $account->getAttribute('passkeys_count') > 0,
            'isSelf' => $actor->is($account),
        ];
    }

    /**
     * Geteilte Verbindungen mit dem Recht des Kontos, dazu die eigenen persönlichen des Administrators (zum Teilen
     * in der Übersicht). Persönliche Verbindungen anderer erscheinen nicht (V-09).
     *
     * @return array<int, array<string, mixed>>
     */
    private function rights(User $account, User $actor): array
    {
        $access = app(ConnectionAccess::class);

        return DatabaseConnection::query()
            ->where(fn ($query) => $query->where('shared', true)->orWhere('owner_id', $actor->id))
            ->with(['owner:id,name', 'memberships' => fn (HasMany $memberships) => $memberships->where('user_id', $account->id)])
            ->get()
            ->sortBy([
                fn (DatabaseConnection $a, DatabaseConnection $b): int => $b->shared <=> $a->shared,
                fn (DatabaseConnection $a, DatabaseConnection $b): int => strnatcasecmp((string) $a->group, (string) $b->group),
                fn (DatabaseConnection $a, DatabaseConnection $b): int => strnatcasecmp($a->name, $b->name),
            ])
            ->map(fn (DatabaseConnection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'environment' => $connection->environment->value,
                'driverLabel' => $connection->driver->label(),
                'serverVersion' => $connection->server_version,
                'readOnly' => $connection->read_only,
                'shared' => $connection->shared,
                'ownerName' => $connection->owner->name,
                'isOwner' => $connection->owner_id === $account->id,
                'permission' => $connection->membershipOf($account)?->permission?->value,
                'editable' => $connection->shared && $access->needsEntry($connection, $account),
            ])
            ->values()
            ->all();
    }
}
