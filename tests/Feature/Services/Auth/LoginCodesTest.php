<?php

namespace Tests\Feature\Services\Auth;

use App\Models\LoginCode;
use App\Services\Auth\LoginCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginCodesTest extends TestCase
{
    use RefreshDatabase;

    private const string EMAIL = 'nina@example.com';

    private LoginCodes $codes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codes = app(LoginCodes::class);
    }

    public function test_issued_code_is_accepted_once(): void
    {
        $code = $this->codes->issue(self::EMAIL, LoginCode::PURPOSE_LOGIN, '127.0.0.1');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertTrue($this->codes->consume(self::EMAIL, LoginCode::PURPOSE_LOGIN, $code));
        $this->assertFalse($this->codes->consume(self::EMAIL, LoginCode::PURPOSE_LOGIN, $code));
    }

    public function test_stores_neither_code_nor_address_in_plain_text(): void
    {
        $code = $this->codes->issue(self::EMAIL, LoginCode::PURPOSE_LOGIN, '127.0.0.1');

        $row = LoginCode::query()->sole();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertStringNotContainsString(self::EMAIL, (string) json_encode($row->getAttributes()));
        $this->assertStringNotContainsString('127.0.0.1', (string) json_encode($row->getAttributes()));
    }

    public function test_rejects_code_after_ten_minutes(): void
    {
        $code = $this->codes->issue(self::EMAIL, LoginCode::PURPOSE_LOGIN, null);

        $this->travel(10)->minutes();
        $this->travel(1)->seconds();

        $this->assertFalse($this->codes->consume(self::EMAIL, LoginCode::PURPOSE_LOGIN, $code));
    }

    public function test_locks_code_after_five_wrong_attempts(): void
    {
        $code = $this->codes->issue(self::EMAIL, LoginCode::PURPOSE_LOGIN, null);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertFalse($this->codes->consume(self::EMAIL, LoginCode::PURPOSE_LOGIN, $wrong));
        }

        $this->assertFalse($this->codes->consume(self::EMAIL, LoginCode::PURPOSE_LOGIN, $code));
    }

    public function test_new_code_invalidates_the_previous_one(): void
    {
        $first = $this->codes->issue(self::EMAIL, LoginCode::PURPOSE_LOGIN, null);
        $second = $this->codes->issue(self::EMAIL, LoginCode::PURPOSE_LOGIN, null);

        if ($first !== $second) {
            $this->assertFalse($this->codes->consume(self::EMAIL, LoginCode::PURPOSE_LOGIN, $first));
        }
        $this->assertTrue($this->codes->consume(self::EMAIL, LoginCode::PURPOSE_LOGIN, $second));
    }

    public function test_code_is_bound_to_address_and_purpose(): void
    {
        $code = $this->codes->issue(self::EMAIL, LoginCode::PURPOSE_LOGIN, null);

        $this->assertFalse($this->codes->consume('reto@example.com', LoginCode::PURPOSE_LOGIN, $code));
        $this->assertFalse($this->codes->consume(self::EMAIL, LoginCode::PURPOSE_CONFIRM, $code));
        $this->assertTrue($this->codes->consume(' NINA@example.com ', LoginCode::PURPOSE_LOGIN, $code));
    }
}
