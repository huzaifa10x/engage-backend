<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Enums;

/** Embedded Signup v4 session-logging `event` values (WA_EMBEDDED_SIGNUP message). */
enum SignupEvent: string
{
    case Finish = 'FINISH';
    case FinishOnlyWaba = 'FINISH_ONLY_WABA';
    case FinishBusinessApp = 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING';
    case FinishOboMigration = 'FINISH_OBO_MIGRATION';
    case FinishGrantOnlyApiAccess = 'FINISH_GRANT_ONLY_API_ACCESS';
    case Cancel = 'CANCEL';
    case Error = 'ERROR';

    public function isFinish(): bool
    {
        return str_starts_with($this->value, 'FINISH');
    }
}
