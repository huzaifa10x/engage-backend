<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Services;

use RuntimeException;

/** A store event that correctly results in no message. The text is shown in the Activity list. */
final class Skip extends RuntimeException {}
