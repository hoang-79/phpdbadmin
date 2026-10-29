<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Enums\ConnectionEnvironment;
use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Protokoll in der Verwaltung (B-04, Wireframe 14): nur Administratoren; Filter nach Konto, Verbindung, Art und
 * Zeitraum; Seiten; Export als CSV ohne Werte.
 */
class AuditControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private DatabaseConnection $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Hoang Nguyen']);
        $this->member = User::factory()->create(['name' => 'Nina Brügger']);
        $this->shop = DatabaseConnection::factory()->create(['name' => 'shop', 'environment' => ConnectionEnvironment::Production]);

        $audit = app(AuditLog::class);
        $audit->record('data.update', $this->member, 'shop.orders', connection: $this->shop,
            sql: "update `orders` set `status` = 'bezahlt' where `id` = ?", affectedRows: 1, bindings: [42]);
        $audit->record('auth.login', $this->admin, context: ['method' => 'passkey']);
        $this->travel(-10)->days(fn () => $audit->record('connection.created', $this->admin, connection: $this->shop));
    }

    public function test_shows_entries_of_the_last_seven_days_newest_first(): void
    {
        $this->actingAs($this->admin)->get(route('admin.audit.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Audit')
                ->where('filters.period', '7')
                ->where('retentionDays', 365)
                ->where('entries.total', 2)
                ->where('entries.data.0.label', 'Anmeldung')
                ->where('entries.data.0.detail', 'per Passkey')
                ->where('entries.data.1.account', 'Nina Brügger')
                ->where('entries.data.1.connection.name', 'shop')
                ->where('entries.data.1.connection.environment', 'production')
                ->where('entries.data.1.sql', 'update `orders` set `status` = ? where `id` = ?')
                ->where('entries.data.1.affectedRows', 1)
                ->where('entries.data.1.result', 'ok')
                ->has('options.connections', 1));
    }

    public function test_filters_by_account_connection_category_and_period(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.audit.index', ['account' => $this->member->id]))
            ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)->where('entries.data.0.account', 'Nina Brügger'));
        $this->get(route('admin.audit.index', ['connection' => $this->shop->id, 'period' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->where('entries.total', 2));
        $this->get(route('admin.audit.index', ['category' => 'connection', 'period' => '30']))
            ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)->where('entries.data.0.label', 'Verbindung angelegt'));
        $this->get(route('admin.audit.index', ['search' => 'orders']))
            ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));
    }

    public function test_pages_hold_fifty_entries(): void
    {
        for ($i = 0; $i < 55; $i++) {
            AuditEntry::query()->create(['action' => 'auth.logout']);
        }

        $this->actingAs($this->admin)->get(route('admin.audit.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.total', 57)
                ->where('entries.currentPage', 2)
                ->has('entries.data', 7)
                ->where('entries.nextUrl', null));
    }

    public function test_rejects_unknown_filter_values(): void
    {
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['category' => 'alles', 'period' => '2']))
            ->assertSessionHasErrors(['category', 'period']);
    }

    public function test_exports_csv_without_values_and_records_the_export(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.audit.export', ['period' => 'all']));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('update `orders` set `status` = ? where `id` = ?', $csv);
        $this->assertStringNotContainsString('bezahlt', $csv);
        $this->assertStringContainsString('Nina Brügger', $csv);
        $this->assertDatabaseHas('audit_log', ['action' => 'audit.exported', 'user_id' => $this->admin->id]);
    }

    public function test_csv_cells_cannot_start_a_formula(): void
    {
        app(AuditLog::class)->record('account.invited', $this->admin, target: '=HYPERLINK("http://example.com")');

        $csv = $this->actingAs($this->admin)->get(route('admin.audit.export'))->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_members_are_forbidden(): void
    {
        $this->actingAs($this->member)->get(route('admin.audit.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('admin.audit.export'))->assertForbidden();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.audit.index'))->assertRedirect(route('login'));
    }
}
