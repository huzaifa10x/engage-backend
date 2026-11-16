<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Infrastructure\Monitoring\SentryReporter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/** Unhandled errors reach Sentry, with what is needed to fix them and nothing private. */
final class SentryReportingTest extends TestCase
{
    private const DSN = 'https://publickey123@o4501.ingest.de.sentry.io/4507001';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['o4501.ingest.de.sentry.io/*' => Http::response(['id' => 'x'])]);
        Route::middleware('api')->get('api/v1/_boom', fn () => throw new RuntimeException('Something broke'));
        Route::middleware('api')->get('api/v1/_invalid', fn () => throw ValidationException::withMessages(['x' => 'bad']));
    }

    /** @return list<array<string, mixed>> the event of each envelope sent */
    private function events(): array
    {
        return Http::recorded()->map(fn ($pair) => json_decode(explode("\n", $pair[0]->body())[2], true))->values()->all();
    }

    public function test_the_dsn_is_read_like_sentrys_own_sdks(): void
    {
        $this->assertSame(['key' => 'publickey123', 'endpoint' => 'https://o4501.ingest.de.sentry.io/api/4507001/envelope/'], SentryReporter::parseDsn(self::DSN));
        $this->assertSame('https://sentry.example.com:9000/prefix/api/7/envelope/', SentryReporter::parseDsn('https://k@sentry.example.com:9000/prefix/7')['endpoint']);
        $this->assertNull(SentryReporter::parseDsn(''));
        $this->assertNull(SentryReporter::parseDsn('not a dsn'));
    }

    public function test_an_unhandled_error_is_sent_once_without_the_query_string_or_private_data(): void
    {
        config(['engage.sentry.dsn' => self::DSN, 'engage.sentry.environment' => 'staging', 'engage.sentry.release' => 'abc123']);

        $this->getJson('/api/v1/_boom?token=EAA-secret&phone=971501234567', ['X-Request-Id' => 'req-12345678', 'Authorization' => 'Bearer secret-api-key'])->assertStatus(500);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://o4501.ingest.de.sentry.io/api/4507001/envelope/'
            && str_contains($r->header('X-Sentry-Auth')[0] ?? '', 'sentry_key=publickey123'));
        $event = $this->events()[0];
        $this->assertSame('RuntimeException', $event['exception']['values'][0]['type']);
        $this->assertSame('Something broke', $event['exception']['values'][0]['value']);
        $this->assertNotEmpty($event['exception']['values'][0]['stacktrace']['frames']);
        $this->assertSame(['staging', 'abc123', 'req-12345678'], [$event['environment'], $event['release'], $event['tags']['request_id']]);
        $this->assertSame(['url' => url('/api/v1/_boom'), 'method' => 'GET'], $event['request']);
        $raw = Http::recorded()->first()[0]->body();
        foreach (['EAA-secret', '971501234567', 'secret-api-key', 'Authorization'] as $private) {
            $this->assertStringNotContainsString($private, $raw);
        }

        // The same error again within a minute is not sent twice.
        $this->getJson('/api/v1/_boom')->assertStatus(500);
        $this->assertCount(1, Http::recorded());
    }

    public function test_nothing_is_sent_without_a_dsn_or_for_expected_errors(): void
    {
        config(['engage.sentry.dsn' => null]);
        $this->getJson('/api/v1/_boom')->assertStatus(500);
        Http::assertNothingSent();

        config(['engage.sentry.dsn' => self::DSN]);
        $this->getJson('/api/v1/_invalid')->assertStatus(422); // validation errors are not bugs
        Http::assertNothingSent();
    }

    public function test_sentry_being_down_never_breaks_the_app(): void
    {
        config(['engage.sentry.dsn' => self::DSN]);
        Http::fake(['o4501.ingest.de.sentry.io/*' => fn () => throw new RuntimeException('Connection refused')]);

        $this->getJson('/api/v1/_boom')->assertStatus(500)->assertJsonPath('error.code', 'server_error');
    }
}
