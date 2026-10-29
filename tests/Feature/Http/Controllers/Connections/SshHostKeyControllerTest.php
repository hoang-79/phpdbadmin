<?php

namespace Tests\Feature\Http\Controllers\Connections;

use App\Models\AuditEntry;
use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Host-Schlüssel eines SSH-Servers vergessen (V-08, N-02): Die known_hosts gilt für die ganze Installation — wer nur
 * die eigene Verbindung verwaltet, vergisst ihn nicht für Verbindungen anderer.
 */
class SshHostKeyControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        // Nie die known_hosts der Installation anfassen.
        $this->folder = storage_path('framework/testing/hostschluessel-'.getmypid());
        @mkdir($this->folder, 0777, true);
        config(['phpdbadmin.ssh.known_hosts' => $this->folder.'/known_hosts', 'phpdbadmin.ssh.temporary' => $this->folder.'/tmp']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->folder));

        parent::tearDown();
    }

    public function test_forgetting_the_key_of_a_server_only_the_owner_uses_is_allowed(): void
    {
        $owner = User::factory()->create();
        $connection = $this->sshConnection($owner, 'bastion.example.ch');

        $this->actingAs($owner)->delete(route('connections.ssh-host-key.destroy', $connection))->assertRedirect();

        $this->assertSame(1, AuditEntry::query()->where('action', 'connection.ssh_host_key_forgotten')->count());
    }

    public function test_a_server_shared_with_connections_of_others_is_left_to_administrators(): void
    {
        $member = User::factory()->create();
        $mine = $this->sshConnection($member, 'bastion.example.ch');
        $this->sshConnection(User::factory()->create(), 'bastion.example.ch');

        $this->actingAs($member)->delete(route('connections.ssh-host-key.destroy', $mine))->assertForbidden();
        $this->assertSame(0, AuditEntry::query()->where('action', 'connection.ssh_host_key_forgotten')->count());

        $admin = User::factory()->admin()->create();
        $adminConnection = $this->sshConnection($admin, 'bastion.example.ch');
        $this->actingAs($admin)->delete(route('connections.ssh-host-key.destroy', $adminConnection))->assertRedirect();
    }

    private function sshConnection(User $owner, string $host): DatabaseConnection
    {
        return DatabaseConnection::factory()->for($owner, 'owner')->create([
            'ssh_host' => $host, 'ssh_port' => 22, 'ssh_username' => 'deploy', 'ssh_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----",
        ]);
    }
}
