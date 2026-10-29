<?php

namespace App\Services\Ai;

use App\Models\DatabaseConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SQL-Hilfe mit KI (Q-08): aus einer Frage und dem Umriss des Schemas wird eine Anweisung im Dialekt der Verbindung.
 * Nur eingeschaltet, nur mit dem eigenen Schlüssel des Kontos, nur Frage und Schema — keine Datenzeilen, kein Text
 * aus dem Editor. Ausgeführt wird nichts: Die Anweisung landet im Editor, der Mensch entscheidet.
 *
 * Claude über die Messages API, ohne SDK (keine neue Abhängigkeit): strukturierte Ausgabe (JSON-Schema) und
 * `fallbacks: "default"`, damit eine abgelehnte Anfrage serverseitig auf einem anderen Modell weiterläuft.
 */
final class SqlAssistant
{
    private const string API_VERSION = '2026-10-21';

    private const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(private readonly SchemaOutline $outline) {}

    public static function enabled(): bool
    {
        return (bool) config('phpdbadmin.ai.enabled');
    }

    /**
     * @return array{sql: string, explanation: string, tables: int, omitted: int}
     *
     * @throws AssistantFailed
     */
    public function suggest(User $user, DatabaseConnection $connection, string $schema, ?string $database, string $question): array
    {
        $key = $user->ai_api_key;

        if (blank($key)) {
            throw new AssistantFailed(__('Hinterlegen Sie zuerst Ihren eigenen Schlüssel unter Konto → KI-Hilfe.'));
        }

        $outline = $this->outline->describe($connection, $schema, $database, (int) config('phpdbadmin.ai.max_schema_chars', 60000));

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => self::API_VERSION,
                'anthropic-beta' => self::FALLBACK_BETA,
            ])
                ->acceptJson()
                ->timeout((int) config('phpdbadmin.ai.timeout_seconds', 120))
                ->post((string) config('phpdbadmin.ai.endpoint'), $this->request($connection, $schema, $outline, $question, $user->preferredLocale() ?? 'de'));
        } catch (ConnectionException) {
            throw new AssistantFailed(__('Der KI-Dienst ist nicht erreichbar. Versuchen Sie es später noch einmal.'));
        }

        return [...$this->answer($response), 'tables' => $outline['tables'], 'omitted' => $outline['omitted']];
    }

    /**
     * @param  array{text: string, tables: int, omitted: int}  $outline
     * @return array<string, mixed>
     */
    private function request(DatabaseConnection $connection, string $schema, array $outline, string $question, string $locale): array
    {
        $dialect = $connection->driver->label().($connection->server_version ? ' '.$connection->server_version : '');
        $language = $locale === 'en' ? 'English' : 'German (Swiss spelling, no ß)';

        return [
            'model' => (string) config('phpdbadmin.ai.model'),
            'max_tokens' => 16000,
            'fallbacks' => 'default',
            'output_config' => [
                'effort' => (string) config('phpdbadmin.ai.effort', 'medium'),
                'format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'sql' => ['type' => 'string', 'description' => 'One SQL statement (or a short script) for the user to review and run.'],
                            'explanation' => ['type' => 'string', 'description' => 'Two or three sentences on what the statement does and anything to check before running it.'],
                        ],
                        'required' => ['sql', 'explanation'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'system' => <<<PROMPT
                You help people write SQL in phpdbadmin, a database administration tool. Write SQL for {$dialect}, using its exact syntax and quoting rules, against the schema "{$schema}" described below. You only know the schema (tables, columns, types, keys) — never the data; do not invent values that would have to come from the data.

                Prefer one clear statement. Read data with SELECT unless the person clearly asks to change something; for statements that change or delete data, always include a WHERE clause that matches the request and mention in the explanation what to check first. Nothing you write runs automatically — the person reviews it in the editor.

                Write the explanation in {$language}.
                PROMPT,
            'messages' => [
                ['role' => 'user', 'content' => "Schema \"{$schema}\":\n{$outline['text']}"
                    .($outline['omitted'] > 0 ? "\n(… {$outline['omitted']} more tables not shown)" : '')
                    ."\n\nRequest:\n{$question}"],
            ],
        ];
    }

    /**
     * @return array{sql: string, explanation: string}
     *
     * @throws AssistantFailed
     */
    private function answer(Response $response): array
    {
        if (! $response->successful()) {
            throw new AssistantFailed(match (true) {
                $response->status() === 401 || $response->status() === 403 => __('Der KI-Dienst hat Ihren Schlüssel abgelehnt. Prüfen Sie ihn unter Konto → KI-Hilfe.'),
                $response->status() === 429 => __('Zu viele Anfragen an den KI-Dienst. Warten Sie einen Moment.'),
                $response->status() >= 500 => __('Der KI-Dienst ist gerade überlastet. Versuchen Sie es später noch einmal.'),
                default => __('Der KI-Dienst hat die Anfrage abgewiesen (:status).', ['status' => $response->status()]),
            });
        }

        if ($response->json('stop_reason') === 'refusal') {
            throw new AssistantFailed(__('Der KI-Dienst hat diese Anfrage abgelehnt. Formulieren Sie sie anders.'));
        }

        $text = collect((array) $response->json('content'))
            ->filter(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'text')
            ->map(fn (array $block): string => (string) ($block['text'] ?? ''))
            ->implode('');

        $answer = json_decode($text, true);

        if (! is_array($answer) || ! is_string($answer['sql'] ?? null) || trim($answer['sql']) === '') {
            throw new AssistantFailed(__('Die Antwort des KI-Dienstes war unvollständig. Versuchen Sie es noch einmal.'));
        }

        return ['sql' => trim($answer['sql']), 'explanation' => trim((string) ($answer['explanation'] ?? ''))];
    }
}
