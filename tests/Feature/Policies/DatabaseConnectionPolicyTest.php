<?php

namespace Tests\Feature\Policies;

use App\Enums\ConnectionPermission;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rechte je Verbindung (A-07, V-09): die ganze Matrix aus Rolle, Besitz, Teilen und Recht gegen die Fähigkeiten
 * view, write, manage und share.
 */
class DatabaseConnectionPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: array{view: bool, write: bool, manage: bool, share: bool}}>
     */
    public static function scenarios(): array
    {
        $none = ['view' => false, 'write' => false, 'manage' => false, 'share' => false];

        return [
            'Besitzer einer persönlichen Verbindung' => ['owner-personal', ['view' => true, 'write' => true, 'manage' => true, 'share' => false]],
            'Besitzer einer geteilten Verbindung' => ['owner-shared', ['view' => true, 'write' => true, 'manage' => true, 'share' => false]],
            'Administrator, eigene persönliche Verbindung' => ['admin-own-personal', ['view' => true, 'write' => true, 'manage' => true, 'share' => true]],
            'Administrator, geteilte Verbindung ohne Eintrag' => ['admin-shared', ['view' => true, 'write' => true, 'manage' => true, 'share' => true]],
            'Administrator, persönliche Verbindung eines anderen' => ['admin-foreign-personal', $none],
            'Mitglied mit Recht lesen' => ['member-read', ['view' => true, 'write' => false, 'manage' => false, 'share' => false]],
            'Mitglied mit Recht schreiben' => ['member-write', ['view' => true, 'write' => true, 'manage' => false, 'share' => false]],
            'Mitglied mit Recht verwalten' => ['member-manage', ['view' => true, 'write' => true, 'manage' => true, 'share' => false]],
            'Mitglied ohne Eintrag an geteilter Verbindung' => ['member-without-entry', $none],
            'Mitglied mit Eintrag, Verbindung nicht mehr geteilt' => ['member-entry-not-shared', $none],
        ];
    }

    /**
     * @param  array{view: bool, write: bool, manage: bool, share: bool}  $expected
     */
    #[DataProvider('scenarios')]
    public function test_abilities_follow_role_ownership_and_permission(string $scenario, array $expected): void
    {
        [$user, $connection] = $this->arrange($scenario);

        $this->assertSame($expected, [
            'view' => $user->can('view', $connection),
            'write' => $user->can('write', $connection),
            'manage' => $user->can('manage', $connection),
            'share' => $user->can('share', $connection),
        ]);
        $this->assertSame($expected['manage'], $connection->isManageableBy($user));
        $this->assertSame($expected['view'], DatabaseConnection::query()->visibleTo($user)->whereKey($connection->id)->exists(), 'Sichtbarkeit und Policy weichen ab');
    }

    /**
     * @return array{0: User, 1: DatabaseConnection}
     */
    private function arrange(string $scenario): array
    {
        $member = User::factory()->create();
        $admin = User::factory()->admin()->create();

        return match ($scenario) {
            'owner-personal' => [$member, DatabaseConnection::factory()->for($member, 'owner')->create()],
            'owner-shared' => [$member, DatabaseConnection::factory()->for($member, 'owner')->create(['shared' => true])],
            'admin-own-personal' => [$admin, DatabaseConnection::factory()->for($admin, 'owner')->create()],
            'admin-shared' => [$admin, DatabaseConnection::factory()->create(['shared' => true])],
            'admin-foreign-personal' => [$admin, DatabaseConnection::factory()->create()],
            'member-read' => [$member, $this->sharedWith($member, ConnectionPermission::Read)],
            'member-write' => [$member, $this->sharedWith($member, ConnectionPermission::Write)],
            'member-manage' => [$member, $this->sharedWith($member, ConnectionPermission::Manage)],
            'member-without-entry' => [$member, DatabaseConnection::factory()->create(['shared' => true])],
            'member-entry-not-shared' => [$member, $this->sharedWith($member, ConnectionPermission::Manage, shared: false)],
        };
    }

    private function sharedWith(User $user, ConnectionPermission $permission, bool $shared = true): DatabaseConnection
    {
        $connection = DatabaseConnection::factory()->create(['shared' => $shared]);
        $connection->members()->attach($user, ['permission' => $permission->value]);

        return $connection;
    }
}
