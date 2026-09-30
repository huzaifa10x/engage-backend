<?php

declare(strict_types=1);

namespace App\Support\Api;

/**
 * Stable, machine-readable error codes. The Next.js client and public API consumers switch on
 * these — never rename a case value once shipped.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'validation_failed';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case Conflict = 'conflict';
    case RateLimited = 'rate_limited';
    case CsrfMismatch = 'csrf_mismatch';

    case TenantRequired = 'tenant_required';
    case TenantSelectionRequired = 'tenant_selection_required';
    case TenantUnavailable = 'tenant_unavailable';
    case MembershipSuspended = 'membership_suspended';

    case PlanLimitReached = 'plan_limit_reached';
    case FeatureNotAvailable = 'feature_not_available';

    case InvitationInvalid = 'invitation_invalid';
    case LastOwner = 'last_owner';
    case ImpersonationInvalid = 'impersonation_invalid';
    case ImpersonationActive = 'impersonation_active';

    case ServerError = 'server_error';
    case ServiceUnavailable = 'service_unavailable';
}
