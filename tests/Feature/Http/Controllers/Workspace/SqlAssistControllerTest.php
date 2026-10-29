<?php

namespace Tests\Feature\Http\Controllers\Workspace;

use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * SQL-Hilfe mit KI (Q-08), mit Attrappe statt Anthropic: nur eingeschaltet, nur mit eigenem Schlüssel, nur das Schema
 * geht hinaus — keine Datenzeile, kein Schlüssel im Browser.
 */
class SqlAssistControllerTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET_ROW_VALUE = 'GEHEIM-ZEILENWERT-4711';

    private string $folder;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/ki-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config(['phpdbadmin.connections.sqlite_paths' => [$this->folder], 'phpdbadmin.ai.enabled' => true]);
        $this->user = User::factory()->create();
        $this->user->forceFill(['ai_api_key' => 'sk-ant-test-0123456789abcdef'])->save();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_sends_only_question_and_schema_never_rows(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->answer('select k.name from kunden k join bestellungen b on b.kunde_id = k.id'))]);
        $connection = $this->connection();

        $this->actingAs($this->user)
            ->postJson(route('workspace.sql.assist', $connection), ['schema' => 'main', 'question' => 'Kunden mit Bestellungen'])
            ->assertOk()
            ->assertJsonPath('sql', 'select k.name from kunden k join bestellungen b on b.kunde_id = k.id')
            ->assertJsonPath('omitted', 0);

        Http::assertSent(function (HttpRequest $request): bool {
            $body = $request->body();
            $prompt = (string) $request['messages'][0]['content'];

            return $request->hasHeader('x-api-key', 'sk-ant-test-0123456789abcdef')
                && $request['model'] === 'claude-opus-5-5'
                && $request['fallbacks'] === 'default'
                && str_contains($prompt, 'kunden (id integer PK, name text NOT NULL, email text)')
                && str_contains($prompt, 'kunde_id integer NOT NULL → kunden.id')
                && str_contains($prompt, 'Kunden mit Bestellungen')
                && ! str_contains($body, self::SECRET_ROW_VALUE)
                && ! str_contains($body, 'geheim@example.com');
        });
        $this->assertSame(1, AuditEntry::query()->where('action', 'sql.assist')->count());
    }

    public function test_is_off_unless_the_installation_turns_it_on(): void
    {
        config(['phpdbadmin.ai.enabled' => false]);
        Http::fake();

        $this->actingAs($this->user)
            ->postJson(route('workspace.sql.assist', $this->connection()), ['schema' => 'main', 'question' => 'alle Kunden'])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_without_own_key_nothing_is_sent(): void
    {
        $this->user->forceFill(['ai_api_key' => null])->save();
        Http::fake();

        $this->actingAs($this->user)
            ->postJson(route('workspace.sql.assist', $this->connection()), ['schema' => 'main', 'question' => 'alle Kunden'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Hinterlegen Sie zuerst Ihren eigenen Schlüssel unter Konto → KI-Hilfe.');

        Http::assertNothingSent();
    }

    public function test_rejected_key_refusal_and_broken_answers_become_messages(): void
    {
        $connection = $this->connection();
        $ask = fn () => $this->actingAs($this->user)->postJson(route('workspace.sql.assist', $connection), ['schema' => 'main', 'question' => 'alle Kunden']);

        Http::fake(['*' => Http::sequence()
            ->push(['type' => 'error'], 401)
            ->push(['stop_reason' => 'refusal', 'content' => []])
            ->push(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'kein JSON']]]),
        ]);

        $ask()->assertUnprocessable()->assertJsonPath('message', 'Der KI-Dienst hat Ihren Schlüssel abgelehnt. Prüfen Sie ihn unter Konto → KI-Hilfe.');
        $ask()->assertUnprocessable()->assertJsonPath('message', 'Der KI-Dienst hat diese Anfrage abgelehnt. Formulieren Sie sie anders.');
        $ask()->assertUnprocessable()->assertJsonPath('message', 'Die Antwort des KI-Dienstes war unvollständig. Versuchen Sie es noch einmal.');
    }

    public function test_only_schemas_of_the_connection_can_be_described(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->postJson(route('workspace.sql.assist', $this->connection()), ['schema' => 'fremd', 'question' => 'alle Kunden'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schema');

        Http::assertNothingSent();
    }

    public function test_the_key_is_stored_encrypted_and_never_shown(): void
    {
        $this->actingAs($this->user)->put(route('account.ai-key.update'), ['key' => 'sk-ant-neu-abcdefghijklmnopWXYZ'])->assertRedirect();

        $stored = (string) $this->user->getConnection()->table('users')->where('id', $this->user->id)->value('ai_api_key');
        $this->assertStringNotContainsString('sk-ant-neu', $stored);

        $response = $this->actingAs($this->user)->get(route('account'));
        $response->assertInertia(fn ($page) => $page->where('aiKey.hint', '…WXYZ')->where('auth.user.ai.hasKey', true));
        $this->assertStringNotContainsString('sk-ant-neu', (string) $response->getContent());

        $this->actingAs($this->user)->delete(route('account.ai-key.destroy'))->assertRedirect();
        $this->assertNull($this->user->refresh()->ai_api_key);
    }

    private function connection(): DatabaseConnection
    {
        $file = $this->folder.'/laden-'.uniqid().'.sqlite';
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('create table kunden (id integer primary key, name text not null, email text)');
        $pdo->exec('create table bestellungen (id integer primary key, kunde_id integer not null references kunden(id), notiz text)');
        $pdo->exec("insert into kunden (name, email) values ('".self::SECRET_ROW_VALUE."', 'geheim@example.com')");
        $pdo->exec("insert into bestellungen (kunde_id, notiz) values (1, '".self::SECRET_ROW_VALUE."')");

        return DatabaseConnection::factory()->for($this->user, 'owner')->sqlite($file)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $sql): array
    {
        return [
            'model' => 'claude-opus-5-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['sql' => $sql, 'explanation' => 'Verknüpft Kunden mit ihren Bestellungen.'])]],
        ];
    }
}
