<?php

declare(strict_types=1);

namespace App\Notifications\Contracts;

/**
 * An email the recipient may switch off in Settings › Email notifications (EmailPreferences).
 * Emails without a category (sign-in codes, verification, password resets, security changes,
 * invitations) are always sent.
 */
interface HasEmailCategory
{
    public function emailCategory(): string;
}
