<?php

declare(strict_types=1);

namespace App\Support\Queue;

/**
 * Named queues (see config/horizon.php for worker supervisors). Add a case only when a module
 * needs isolation — e.g. `campaigns` arrives with the Campaigns module.
 */
enum QueueName: string
{
    case Critical = 'critical';
    case Webhooks = 'webhooks';
    case Messaging = 'messaging';
    case Default = 'default';
    case Notifications = 'notifications';
    case Maintenance = 'maintenance';
}
