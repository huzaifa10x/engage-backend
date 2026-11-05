<?php

declare(strict_types=1);

namespace Tests\Feature\Meta;

use App\Infrastructure\Meta\MetaApiException;
use App\Infrastructure\Meta\MetaUsage;
use GuzzleHttp\Psr7\Response as Psr;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

/**
 * Our own call budget at Meta: usage headers are read on every call; background work waits from
 * 75 %; a "regain access" time holds calls for that ID; customer sends ignore app-wide holds.
 */
final class MetaUsageTest extends TestCase
{
    private function response(array $headers): Response
    {
        return new Response(new Psr(200, $headers, '{}'));
    }

    public function test_usage_headers_are_read_and_background_work_waits_from_75_percent(): void
    {
        MetaUsage::record($this->response(['X-App-Usage' => json_encode(['call_count' => 20, 'total_time' => 10, 'total_cputime' => 5])]));
        $this->assertSame(20, MetaUsage::percent());
        $this->assertFalse(MetaUsage::shouldDefer('102290129340398'));

        MetaUsage::record($this->response(['X-Business-Use-Case-Usage' => json_encode(['102290129340398' => [
            ['type' => 'whatsapp_business_management', 'call_count' => 40, 'total_time' => 81, 'total_cputime' => 12, 'estimated_time_to_regain_access' => 0],
        ]])]));
        $this->assertSame(81, MetaUsage::percent('102290129340398'));
        $this->assertTrue(MetaUsage::shouldDefer('102290129340398'));
        $this->assertFalse(MetaUsage::shouldDefer('999'), 'another business keeps its own budget');
        $this->assertSame(0, MetaUsage::blockedFor('102290129340398'));
    }

    public function test_a_regain_time_or_a_rate_limit_error_holds_calls_for_that_id(): void
    {
        MetaUsage::record($this->response(['X-Business-Use-Case-Usage' => json_encode(['555' => [['call_count' => 100, 'estimated_time_to_regain_access' => 3]]])]));
        $this->assertGreaterThan(170, MetaUsage::blockedFor('555'));

        MetaUsage::limited('777', new MetaApiException('Too many calls', 400, 80007, null, null, ['code' => 80007, 'error_data' => ['estimated_time_to_regain_access' => 2]]));
        $this->assertGreaterThan(100, MetaUsage::blockedFor('777', app: false));
        $this->assertSame(0, MetaUsage::blockedFor('888', app: false));

        // An app-wide refusal (code 4) holds background work everywhere, but never customer sends.
        MetaUsage::limited('888', new MetaApiException('Application request limit reached', 400, 4));
        $this->assertTrue(MetaUsage::shouldDefer('123'));
        $this->assertSame(0, MetaUsage::blockedFor('123', app: false));

        // Other errors change nothing.
        MetaUsage::limited('999', new MetaApiException('Invalid parameter', 400, 100));
        $this->assertSame(0, MetaUsage::blockedFor('999', app: false));
    }
}
