<?php

namespace App\Http\Requests\Workspace\Server;

use App\Dialects\AccountName;
use App\Dialects\PrivilegeTarget;
use App\Enums\AccountAction;
use App\Enums\PrivilegeLevel;
use App\Services\Server\AccountChange;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Konto anlegen oder löschen, Rechte erteilen oder entziehen (W-04). Namen und Hosts nur in einem engen Format, Rechte
 * nur aus der festen Liste der Ebene — dass Datenbank, Schema und Tabelle existieren, prüft danach der Plan gegen den
 * Katalog. SQLite kennt keine Konten.
 */
class AccountChangeRequest extends ServerChangeRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $dialect = $this->targetConnection()->driver->dialect();
        $choices = $dialect->privilegeChoices();
        $level = $this->input('level');

        return [
            'action' => ['required', Rule::enum(AccountAction::class), Rule::prohibitedIf($choices === [])],
            'name' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_.\-]*$/'],
            'host' => $dialect->accountsHaveHosts()
                ? ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.%_:\/\-]+$/']
                : ['prohibited'],
            'password' => ['exclude_unless:action,create', 'required', 'string', 'min:8', 'max:128', 'not_regex:/\x00/'],
            'level' => ['exclude_unless:action,grant,revoke', 'required', Rule::in(array_keys($choices))],
            'privileges' => ['exclude_unless:action,grant,revoke', 'required', 'array', 'min:1'],
            'privileges.*' => ['exclude_unless:action,grant,revoke', 'string', Rule::in(is_string($level) ? ($choices[$level] ?? []) : [])],
            'database' => ['exclude_unless:action,grant,revoke', 'nullable', 'string', 'max:255'],
            'schema' => ['exclude_unless:action,grant,revoke', 'nullable', 'string', 'max:255'],
            'table' => ['exclude_unless:action,grant,revoke', 'nullable', 'string', 'max:255'],
            ...$this->confirmationRules(),
        ];
    }

    public function change(): AccountChange
    {
        $action = AccountAction::from((string) $this->validated('action'));
        $text = fn (string $key): ?string => is_string($this->validated($key)) && $this->validated($key) !== '' ? $this->validated($key) : null;

        return new AccountChange(
            $action,
            new AccountName((string) $this->validated('name'), $text('host')),
            $action === AccountAction::Create ? (string) $this->validated('password') : null,
            $action->changesPrivileges()
                ? new PrivilegeTarget(PrivilegeLevel::from((string) $this->validated('level')), $text('database'), $text('schema'), $text('table'))
                : null,
            $action->changesPrivileges() ? array_values(array_map('strval', (array) $this->validated('privileges'))) : [],
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'action.prohibited' => __('SQLite kennt keine Konten und Rechte.'),
            'name.regex' => __('Kontoname: nur Buchstaben, Ziffern und _ . - ohne Leerzeichen.'),
            'host.regex' => __('Host: nur Buchstaben, Ziffern und . % _ : / -, zum Beispiel % oder localhost.'),
            'host.prohibited' => __('PostgreSQL-Rollen haben keinen Host.'),
            'privileges.*.in' => __('Dieses Recht gibt es auf dieser Ebene nicht.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'action' => __('Aktion'),
            'name' => __('Kontoname'),
            'host' => __('Host'),
            'password' => __('Passwort'),
            'level' => __('Ebene'),
            'privileges' => __('Rechte'),
            'database' => __('Datenbank'),
            'schema' => __('Schema'),
            'table' => __('Tabelle'),
            'confirmation' => __('Name der Verbindung'),
        ];
    }
}
