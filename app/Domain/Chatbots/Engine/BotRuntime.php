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
 * resume_at, timeout_at, ended_reason, ended_node.
 */
abstract class BotRuntime
{
    /** @var array<string, mixed> current_node, awaiting_input, retry_count, vars, status, steps, resume_at, timeout_at, ended_reason, ended_node, preempted */
    public array $state;

    /** @var array<string, mixed> id, name, first_name, email, phone, fields, tags, consent */
    public array $contact;

    /**
     * Sends one message (SendMessage payload shape).
     *
     * @param  array<string, mixed>  $data
     * @return ?string the message's idempotency key (live), to follow its delivery
     *
     * @throws BotPreempted when a person took over meanwhile
     * @throws BotSendFailed when WhatsApp rules do not allow it
     */
    abstract public function send(array $data): ?string;

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

    /** @return list<string> mon … sun: the days the business is open (inbox business hours) */
    abstract public function openDays(): array;

    abstract public function timezone(): string;

    abstract public function secret(string $name): ?string;

    abstract public function event(string $nodeId, string $kind, ?string $detail = null): void;

    /** The published graph of a subflow (null when it does not exist or was never published). */
    abstract public function graph(string $flowId): ?FlowGraph;

    /** Marketing opt-out ("marketing") or a full opt-out ("all") through the consent service. */
    abstract public function optOut(string $scope): void;

    /**
     * The status of one of this customer's orders in the connected store.
     *
     * @return ?array<string, mixed> number, status, total, updated_at, tracking_url…
     */
    abstract public function order(string $number): ?array;

    /** An internal note on the conversation, signed with the bot's name. */
    abstract public function internalNote(string $text): void;

    abstract public function botName(): string;

    /** @return array<string, string> the workspace's business variables ({{business.name}} …) */
    abstract public function business(): array;

    /** A line for the Test chat ("Saved “Gold” to Membership tier"); ignored in live conversations. */
    public function note(string $text): void {}

    /** Live API calls are made for real in both modes; the Test chat labels them. */
    public function isTest(): bool
    {
        return false;
    }
}
