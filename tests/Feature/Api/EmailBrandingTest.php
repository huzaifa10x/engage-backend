<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Notifications\PaymentFailedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

final class EmailBrandingTest extends TestCase
{
    public function test_system_emails_render_with_the_brand_theme(): void
    {
        $to = (new AnonymousNotifiable)->route('mail', 'jane@example.com');
        $html = (string) (new VerifyEmailNotification('482913', 'Jane', 10))->toMail($to)->render();

        $this->assertStringContainsString('482913', $html);                       // content untouched
        $this->assertStringContainsString('>10X</span>', $html);                  // brand wordmark instead of the Laravel logo
        $this->assertStringNotContainsString('laravel.com/img', $html);
        $this->assertStringContainsString('border-top: 4px solid #a9e324', $html); // lime accent on the card
        $this->assertStringContainsString('10X Digital', $html);                  // branded footer

        // Buttons are lime with dark text (readable), not white-on-lime.
        $button = (string) (new PaymentFailedNotification('Acme', 'ENG-1', 'USD 82.95', null))->toMail($to)->render();
        $this->assertMatchesRegularExpression('/class="button button-error"[^>]*background-color: #dc2626/', $button);
        $verify = (string) (new ResetPasswordNotification('https://app.test/reset', 60))->toMail($to)->render();
        $this->assertMatchesRegularExpression('/class="button button-primary"[^>]*background-color: #a9e324[^>]*color: #101a02/', $verify);
    }
}
