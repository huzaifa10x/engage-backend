<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\TwoFactor\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** RFC 6238 Appendix B, SHA-1 seed "12345678901234567890" (base32 below), truncated to 6 digits. */
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_rfc6238_vectors(): void
    {
        $this->assertSame('287082', Totp::codeAt(self::SECRET, intdiv(59, 30)));
        $this->assertSame('081804', Totp::codeAt(self::SECRET, intdiv(1111111109, 30)));
        $this->assertSame('005924', Totp::codeAt(self::SECRET, intdiv(1234567890, 30)));
    }

    public function test_verify_allows_one_step_of_drift_and_rejects_replay(): void
    {
        $now = 1_700_000_000;
        $step = Totp::currentStep($now);
        $previous = Totp::codeAt(self::SECRET, $step - 1);

        $this->assertSame($step - 1, Totp::verify(self::SECRET, $previous, null, $now));
        $this->assertNull(Totp::verify(self::SECRET, $previous, $step - 1, $now), 'replayed step must be rejected');
        $this->assertNull(Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step - 3), null, $now));
        $this->assertNull(Totp::verify(self::SECRET, 'abcdef', null, $now));
    }

    public function test_generated_secret_round_trips(): void
    {
        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(Totp::currentStep(), Totp::verify($secret, Totp::codeAt($secret, Totp::currentStep())));
    }
}
