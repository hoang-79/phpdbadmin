<?php

namespace Tests\Feature\Services\Server;

use App\Connections\ConnectionFactory;
use App\Dialects\AccountName;
use App\Enums\AccountAction;
use App\Enums\DatabaseDriver;
use App\Services\Server\AccountChange;
use App\Services\Server\ServerPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\TargetDatabases;
use Tests\TestCase;

/**
 * Pläne für ändernde Aktionen (W-02, W-04): phpdbadmin beendet nie die eigene Sitzung und löscht nie das eigene
 * Konto; das Passwort steht nur maskiert in Vorschau und Protokoll.
 */
#[Group('target-databases')]
class ServerPlansTest extends TestCase
{
    use RefreshDatabase;
    use TargetDatabases;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/plaene-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config(['phpdbadmin.connections.allowed_hosts' => ['127.0.0.1']]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    #[DataProvider('serverDrivers')]
    public function test_refuses_to_end_its_own_session(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);
        $own = (int) $driver->dialect()->sessionId($target);

        try {
            app(ServerPlans::class)->endProcess($target, $this->savedConnection($settings), $own, wholeSession: true);
            $this->fail('Eigene Sitzung wäre beendet worden');
        } catch (ValidationException $exception) {
            $this->assertSame(['process' => ['Das ist die Sitzung, über die phpdbadmin gerade arbeitet.']], $exception->errors());
        }
    }

    #[DataProvider('serverDrivers')]
    public function test_refuses_to_drop_its_own_account(DatabaseDriver $driver): void
    {
        $settings = $this->scratchSettings($driver, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);
        $own = $driver->dialect()->currentAccount($target);

        $this->expectException(ValidationException::class);

        app(ServerPlans::class)->account($target, $this->savedConnection($settings), new AccountChange(AccountAction::Drop, new AccountName($own->name, $own->host)));
    }

    public function test_password_is_masked_everywhere_in_the_plan(): void
    {
        $settings = $this->scratchSettings(DatabaseDriver::MySql, $this->folder);
        $target = app(ConnectionFactory::class)->make($settings);
        $password = "Ge'heim\\Wort-4711";

        $plan = app(ServerPlans::class)->account($target, $this->savedConnection($settings), new AccountChange(AccountAction::Create, new AccountName('phpdbadmin_w04_x', '%'), $password));

        $this->assertStringContainsString("Ge\\'heim", $plan->statements[0]->sql);
        $this->assertStringNotContainsString('heim', json_encode($plan->preview(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('heim', $plan->auditSql());
        // Meldungen des Servers zitieren manchmal die Anweisung — auch dort wird maskiert.
        $this->assertSame('near •••••••• at line 1', $plan->mask("near '".substr((string) $target->getPdo()->quote($password), 1, -1)."' at line 1"));
    }
}
