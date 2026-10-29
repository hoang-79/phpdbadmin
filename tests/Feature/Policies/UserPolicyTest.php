<?php

namespace Tests\Feature\Policies;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Konten verwalten (Wireframe 14): nur Administratoren, nie das eigene Konto und nie das letzte aktive
 * Administratorkonto — Name und Adresse ausgenommen (A-05).
 */
class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: array{update: bool, changeRole: bool, disable: bool, delete: bool}}>
     */
    public static function scenarios(): array
    {
        $all = ['update' => true, 'changeRole' => true, 'disable' => true, 'delete' => true];
        $onlyUpdate = ['update' => true, 'changeRole' => false, 'disable' => false, 'delete' => false];

        return [
            'Administrator, Mitglied' => ['admin-member', $all],
            'Administrator, anderer Administrator' => ['admin-admin', $all],
            'Administrator, eigenes Konto' => ['admin-self', $onlyUpdate],
            'gesperrter Administrator, letzter aktiver Administrator' => ['disabled-admin-last-admin', $onlyUpdate],
            'Mitglied, anderes Konto' => ['member-other', ['update' => false, 'changeRole' => false, 'disable' => false, 'delete' => false]],
            'Mitglied, eigenes Konto' => ['member-self', ['update' => false, 'changeRole' => false, 'disable' => false, 'delete' => false]],
        ];
    }

    /**
     * @param  array{update: bool, changeRole: bool, disable: bool, delete: bool}  $expected
     */
    #[DataProvider('scenarios')]
    public function test_abilities_follow_role_and_self(string $scenario, array $expected): void
    {
        [$actor, $account] = match ($scenario) {
            'admin-member' => [User::factory()->admin()->create(), User::factory()->create()],
            'admin-admin' => [User::factory()->admin()->create(), User::factory()->admin()->create()],
            'admin-self' => array_fill(0, 2, User::factory()->admin()->create()),
            'disabled-admin-last-admin' => [User::factory()->admin()->create(['disabled_at' => now()]), User::factory()->admin()->create()],
            'member-other' => [User::factory()->create(), User::factory()->create()],
            'member-self' => array_fill(0, 2, User::factory()->create()),
        };

        $this->assertSame($expected, [
            'update' => $actor->can('update', $account),
            'changeRole' => $actor->can('changeRole', $account),
            'disable' => $actor->can('disable', $account),
            'delete' => $actor->can('delete', $account),
        ]);
    }
}
