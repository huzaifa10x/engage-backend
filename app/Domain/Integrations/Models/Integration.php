<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A store connected to a workspace.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property string $public_id
 * @property string $name
 * @property string $external_id
 * @property string $status
 * @property ?string $secret_id
 * @property ?string $webhook_secret
 * @property ?array<string, mixed> $settings
 * @property ?Carbon $last_event_at
 * @property ?string $last_error
 * @property ?Carbon $created_at
 */
class Integration extends Model
{
    use BelongsToTenant, HasUuids;

    public const SHOPIFY = 'shopify';

    public const WOOCOMMERCE = 'woocommerce';

    /** Store events a message can be attached to, and which stores report them. */
    public const EVENTS = [
        'order_created' => ['label' => 'Order placed', 'hint' => 'Confirm the order as soon as it is placed.', 'providers' => [self::SHOPIFY, self::WOOCOMMERCE]],
        'order_fulfilled' => ['label' => 'Order shipped', 'hint' => 'Tell the customer the order is on its way (Shopify: fulfilled, WooCommerce: completed).', 'providers' => [self::SHOPIFY, self::WOOCOMMERCE]],
        'order_cancelled' => ['label' => 'Order cancelled', 'hint' => 'Let the customer know the order was cancelled.', 'providers' => [self::SHOPIFY, self::WOOCOMMERCE]],
        'checkout_abandoned' => ['label' => 'Abandoned checkout', 'hint' => 'Remind customers who entered their number at checkout but did not finish the order.', 'providers' => [self::SHOPIFY]],
    ];

    /** Values a template variable can be filled with: {{order_number}} and so on. */
    public const FIELDS = [
        'customer_first_name' => 'Customer first name',
        'customer_name' => 'Customer full name',
        'order_number' => 'Order number',
        'order_total' => 'Order total with currency',
        'items' => 'Items in the order',
        'first_item' => 'First item name',
        'store_name' => 'Store name',
        'tracking_number' => 'Tracking number',
        'tracking_company' => 'Courier',
        'tracking_url' => 'Tracking link',
        'order_status_url' => 'Order status link',
        'order_status_path' => 'Order status link without the domain (for a button)',
        'checkout_url' => 'Link back to the checkout',
        'checkout_path' => 'Checkout link without the domain (for a button)',
    ];

    protected $fillable = ['tenant_id', 'provider', 'public_id', 'name', 'external_id', 'status', 'secret_id', 'webhook_secret', 'settings', 'last_event_at', 'last_error', 'connected_by_membership_id'];

    protected $hidden = ['webhook_secret', 'secret_id'];

    protected function casts(): array
    {
        return ['webhook_secret' => 'encrypted', 'settings' => 'array', 'last_event_at' => 'datetime'];
    }

    /** @return HasMany<IntegrationRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(IntegrationRule::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /** @return list<string> the events this kind of store reports */
    public function events(): array
    {
        return array_keys(array_filter(self::EVENTS, fn (array $e) => in_array($this->provider, $e['providers'], true)));
    }
}
