<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

use Illuminate\Validation\ValidationException;

/**
 * Everything the engine touches outside itself. Two implementations: LiveRuntime (a real
 * conversation: WhatsApp messages, contact writes, assignment) and TestRuntime (the builder's
 * Test chat: messages are collected, writes stay in memory).
 *
 * $state is the session: current_node, awaiting_input, retry_count, vars, status, steps,
 * resume_at, ended_reason, ended_node.
 */
abstract class BotRuntime
{
    /** @var array<string, mixed> current_node, awaiting_input, retry_count, vars, status, steps, resume_at, ended_reason, ended_node, preempted */
    public array $state;

    /** @var array<string, mixed> id, name, first_name, email, phone, fields, tags, consent */
    public array $contact;

    /**
     * Sends one message (SendMessage payload shape).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BotPreempted when a person took over meanwhile
     * @throws BotSendFailed when WhatsApp rules do not allow it
     */
    abstract public function send(array $data): void;

    /**
     * Writes contact data through the normal contact write path.
     *
     * @param  array{name?: ?string, email?: ?string, fields?: array<string, mixed>, tags?: list<string>}  $changes
     *
     * @throws ValidationException when a value does not fit its field
     */
    abstract public function updateContact(array $changes): void;

    /** @return ?string who the conversation went to, in words (for notes), or null */
    abstract public function assign(string $mode, ?string $memberId, string $reason): ?string;

    abstract public function closeConversation(): void;

    abstract public function inSegment(string $segment): bool;

    abstract public function businessOpen(): bool;

    abstract public function secret(string $name): ?string;

    abstract public function event(string $nodeId, string $kind, ?string $detail = null): void;

    /** A line for the Test chat ("Saved “Gold” to Membership tier"); ignored in live conversations. */
    public function note(string $text): void {}

    /** Live API calls are made for real in both modes; the Test chat labels them. */
    public function isTest(): bool
    {
        return false;
    }
}
