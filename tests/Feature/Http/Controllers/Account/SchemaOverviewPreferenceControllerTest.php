<?php

namespace Tests\Feature\Http\Controllers\Account;

use App\Models\User;
use App\Support\SchemaOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Einstellungen der Schema-Übersicht (Ansicht, Sortierung, Seiten, Filter) gehören dem Konto und kommen
 * automatisch wieder — der Filter nur im Schema, in dem er gesetzt wurde.
 */
class SchemaOverviewPreferenceControllerTest extends TestCase
{
    use RefreshDatabase;

    private const string CONNECTION = '01m62e2k2n81tbaqmyq37qbntx';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'view' => 'list',
            'sort' => 'size',
            'direction' => 'desc',
            'perPage' => 50,
            'kind' => 'tables',
            'connection' => self::CONNECTION,
            'database' => 'atrium',
            'schema' => 'public',
            'filter' => 'arbeitssicherheit',
            ...$overrides,
        ];
    }

    public function test_settings_and_the_filter_of_this_schema_are_saved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson(route('account.preferences.schema-overview'), $this->payload())
            ->assertNoContent();

        $this->assertSame(
            ['view' => 'list', 'sort' => 'size', 'direction' => 'desc', 'perPage' => 50, 'kind' => 'tables', 'filter' => 'arbeitssicherheit'],
            SchemaOverview::for($user->fresh() ?? $user, self::CONNECTION, 'atrium', 'public'),
        );
    }

    public function test_filter_stays_in_its_schema_while_the_settings_apply_everywhere(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson(route('account.preferences.schema-overview'), $this->payload())->assertNoContent();

        $elsewhere = SchemaOverview::for($user->fresh() ?? $user, self::CONNECTION, 'basalto_test', 'public');
        $this->assertSame('', $elsewhere['filter']);
        $this->assertSame('list', $elsewhere['view']);
    }

    public function test_empty_filter_forgets_the_stored_one(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->putJson(route('account.preferences.schema-overview'), $this->payload())->assertNoContent();

        $this->actingAs($user)->putJson(route('account.preferences.schema-overview'), $this->payload(['filter' => '']))->assertNoContent();

        $this->assertSame([], $user->fresh()?->preferences[SchemaOverview::KEY]['filters']);
    }

    public function test_only_the_most_recent_filters_are_kept(): void
    {
        $user = User::factory()->create();

        foreach (range(1, SchemaOverview::FILTERS + 1) as $number) {
            $this->actingAs($user)->putJson(route('account.preferences.schema-overview'), $this->payload(['schema' => 'schema_'.$number, 'filter' => 'f'.$number]))->assertNoContent();
        }

        $fresh = $user->fresh() ?? $user;
        $this->assertCount(SchemaOverview::FILTERS, $fresh->preferences[SchemaOverview::KEY]['filters']);
        $this->assertSame('', SchemaOverview::for($fresh, self::CONNECTION, 'atrium', 'schema_1')['filter'], 'der älteste fällt weg');
        $this->assertSame('f'.(SchemaOverview::FILTERS + 1), SchemaOverview::for($fresh, self::CONNECTION, 'atrium', 'schema_'.(SchemaOverview::FILTERS + 1))['filter']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'unbekannte Ansicht' => [['view' => 'gallery'], 'view'],
            'unbekannte Sortierung' => [['sort' => 'created'], 'sort'],
            'Richtung' => [['direction' => 'up'], 'direction'],
            'Seitengrösse nicht in der Auswahl' => [['perPage' => 7], 'perPage'],
            'Art' => [['kind' => 'functions'], 'kind'],
            'keine Verbindungskennung' => [['connection' => 'atrium'], 'connection'],
            'Filter zu lang' => [['filter' => str_repeat('x', SchemaOverview::FILTER_LENGTH + 1)], 'filter'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_values_outside_the_choice_are_refused(array $overrides, string $field): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson(route('account.preferences.schema-overview'), $this->payload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertNull($user->fresh()?->preferences);
    }

    public function test_guest_cannot_save(): void
    {
        $this->putJson(route('account.preferences.schema-overview'), $this->payload())->assertUnauthorized();
    }

    public function test_unknown_stored_values_fall_back_to_the_defaults(): void
    {
        $user = User::factory()->create();
        $user->preferences = [SchemaOverview::KEY => ['view' => 'gallery', 'perPage' => 7, 'sort' => 'rows']];
        $user->save();

        $settings = SchemaOverview::for($user, self::CONNECTION, null, 'main');

        $this->assertSame('cards', $settings['view']);
        $this->assertSame(SchemaOverview::DEFAULTS['perPage'], $settings['perPage']);
        $this->assertSame('rows', $settings['sort']);
    }
}
