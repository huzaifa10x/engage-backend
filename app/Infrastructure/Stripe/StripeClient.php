<?php

declare(strict_types=1);

namespace App\Infrastructure\Stripe;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin Stripe REST client (form-encoded requests, pinned API version). The API version is
 * pinned so responses keep one shape whatever the Stripe account's default is; webhook payloads
 * are therefore only used as triggers and the object is always re-read through this client.
 */
final class StripeClient
{
    private const BASE = 'https://api.stripe.com/v1/';

    public function configured(): bool
    {
        return (string) config('engage.stripe.secret') !== '';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function get(string $path, array $params = []): array
    {
        return $this->send('GET', $path, $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function post(string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        return $this->send('POST', $path, $params, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function delete(string $path): array
    {
        return $this->send('DELETE', $path);
    }

    /**
     * Verifies a webhook's Stripe-Signature header ("t=…,v1=…") and returns the event.
     *
     * @return array<string, mixed>
     */
    public function verifyWebhook(string $payload, string $header, int $tolerance = 300): array
    {
        $secret = (string) config('engage.stripe.webhook_secret');
        if ($secret === '') {
            throw new StripeException('The Stripe webhook secret is not configured.', 503);
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || $signatures === [] || abs(time() - $timestamp) > $tolerance) {
            throw new StripeException('Invalid Stripe signature.', 400);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $valid = false;
        foreach ($signatures as $signature) {
            $valid = $valid || hash_equals($expected, $signature);
        }
        $event = $valid ? json_decode($payload, true) : null;
        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            throw new StripeException('Invalid Stripe signature.', 400);
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        if (! $this->configured()) {
            throw StripeException::notConfigured();
        }

        $request = Http::baseUrl(self::BASE)
            ->withToken((string) config('engage.stripe.secret'))
            ->withHeaders(array_filter(['Stripe-Version' => (string) config('engage.stripe.api_version'), 'Idempotency-Key' => $idempotencyKey]))
            ->acceptJson()->asForm()->timeout(30)->connectTimeout(5);

        try {
            $response = match ($method) {
                'GET' => $request->get($path, $params),
                'DELETE' => $request->delete($path),
                default => $request->post($path, $params),
            };
        } catch (ConnectionException $e) {
            throw new StripeException('The payment provider could not be reached. Please try again in a moment.', 503);
        }

        $body = $response->json();
        if ($response->failed() || ! is_array($body)) {
            $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

            throw new StripeException((string) ($error['message'] ?? 'The payment provider rejected the request.'), $response->status() === 503 ? 502 : $response->status(), isset($error['code']) ? (string) $error['code'] : null);
        }

        return $body;
    }
}
