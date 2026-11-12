<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Chatbots\Engine\FlowEngine;
use App\Domain\Chatbots\Engine\Placeholders;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How the chatbot reads dates and numbers customers type. The Flow Builder's test phone implements
 * the same rules (engine.js parseDate / numberText); the parity harness checks both agree.
 */
final class ChatbotAnswersTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string, ?string}> today, typed, date, reason */
    public static function dates(): iterable
    {
        yield 'day/month this year' => ['2026-11-12', '18/11', '2026-11-18', null];
        yield 'day/month already passed → next year' => ['2026-12-20', '5/1', '2027-01-05', null];
        yield 'today stays this year' => ['2026-11-12', '12/11', '2026-11-12', null];
        yield 'full date' => ['2026-11-12', '25/12/2026', '2026-12-25', null];
        yield 'two-digit year' => ['2026-11-12', '18-11-26', '2026-11-18', null];
        yield 'dots' => ['2026-11-12', '18.11.2026', '2026-11-18', null];
        yield 'ISO' => ['2026-11-12', '2026-12-01', '2026-12-01', null];
        yield 'day month name' => ['2026-11-12', '21 Dec', '2026-12-21', null];
        yield 'month name day with year' => ['2026-11-12', 'December 21, 2027', '2027-12-21', null];
        yield 'ordinal' => ['2026-11-12', '3rd Jan', '2027-01-03', null];
        yield 'Arabic digits' => ['2026-11-12', '١٨/١١', '2026-11-18', null];
        yield 'tomorrow' => ['2026-12-31', 'tomorrow', '2027-01-01', null];
        yield '31 April does not exist' => ['2026-11-12', '31/04/2027', null, 'impossible'];
        yield '31/4 without a year' => ['2026-11-12', '31/4', null, 'impossible'];
        yield '29 Feb in a non-leap year' => ['2026-11-12', '29/2/2027', null, 'impossible'];
        yield '29 Feb in a leap year' => ['2026-11-12', '29/2/2028', '2028-02-29', null];
        yield 'month first is refused' => ['2026-11-12', '11/18', null, 'us_order'];
        yield 'free text is refused' => ['2026-11-12', 'next friday', null, 'not_a_date'];
    }

    #[DataProvider('dates')]
    public function test_dates_are_read_day_first_and_never_guessed(string $today, string $typed, ?string $date, ?string $reason): void
    {
        $this->assertSame([$date, $reason], FlowEngine::parseDate($typed, Carbon::parse($today, 'Asia/Dubai')->startOfDay()));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function numbers(): iterable
    {
        yield 'commas' => ['1,800,000', '1800000'];
        yield 'spaces' => ['1 800 000', '1800000'];
        yield 'plain' => ['95000', '95000'];
        yield 'decimal' => ['2.5', '2.5'];
        yield 'thousands' => ['120k', '120000'];
        yield 'AED prefix and k' => ['AED 950k', '950000'];
        yield 'millions' => ['1.2m', '1200000'];
        yield 'mn' => ['1.2 mn', '1200000'];
        yield 'million in words' => ['3 million', '3000000'];
        yield 'dhs suffix' => ['500 dhs', '500'];
        yield 'Arabic digits' => ['١٢٠٠٠٠', '120000'];
        yield 'words' => ['two million', null];
        yield 'two numbers' => ['1-2m', null];
    }

    #[DataProvider('numbers')]
    public function test_numbers_accept_the_shorthands_customers_use(string $typed, ?string $value): void
    {
        $this->assertSame($value, FlowEngine::number($typed));
    }

    public function test_saved_dates_read_naturally_in_messages_only(): void
    {
        $this->assertSame('Wed 18 Nov 2026', Placeholders::humanDate('2026-11-18'));
        $this->assertSame('2026-02-30', Placeholders::humanDate('2026-02-30'));
        $this->assertSame('JVC', Placeholders::humanDate('JVC'));
    }
}
