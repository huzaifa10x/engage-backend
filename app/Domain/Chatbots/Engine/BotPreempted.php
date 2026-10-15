<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

use RuntimeException;

/** A person took the conversation over (or the session ended) while the bot was working: its reply is dropped. */
final class BotPreempted extends RuntimeException {}
