# 10X Engage — Backend

## Local development (start here)

```bash
./dev setup     # once per computer
./dev start     # every day
```

Full guide: [docs/local-development.md](docs/local-development.md) — prerequisites, demo logins, fake Meta mode.


Multi-tenant WhatsApp Cloud API SaaS. **Laravel 13 (PHP 8.4)** modular monolith: the sole backend for
the Next.js client (`/api/v1`), the Inertia React Super Admin (web routes, `admin` guard), and all
workers (Horizon). PostgreSQL 17 + Valkey 8.

This repository contains **Phase 0 (Foundation)**, **Phase 1 (Core SaaS & Tenancy)** and **Super Admin v0**.
Plan catalog follows **Implementation Blueprint v2** ($29 / $79 / $139, uncapped contacts, metered campaign reach).

## Quick start

```bash
cp .env.example .env
docker compose up -d                          # postgres, valkey, mailpit, app, horizon, scheduler
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan test
```

Super Admin: http://localhost:8000/admin (assets served by the `vite` service on :5173 with HMR;
or run `npm run build` once and stop the `vite` service).

Local demo (APP_ENV=local): `owner@engage.test` / `Password123!` (tenant owner, Pro trial) and
`admin@engage.test` / `Password123!` (platform super admin; enrols TOTP two-factor on first sign-in).
Production has no seeded admin: `php artisan engage:admin:create you@10xdigital.ae --name="Waqar"`.

> **The database role must NOT be SUPERUSER or BYPASSRLS.** Row-level security is silently skipped for
> such roles. `docker/postgres/init/01-engage.sql` creates the correct `engage` role; the test suite
> refuses to run otherwise.

## Request pipeline

```
AssignRequestId → (Sanctum stateful) → auth:sanctum → ResolveTenant → SubstituteBindings → can:<permission> → action (entitlement checks) → audit
```

* **Tenant resolution is server-side only**: SPA session `active_tenant_id` → `users.last_active_tenant_id`
  → the single active membership. It is re-validated against an active membership on every request.
  There is no `X-Tenant-ID` header and no client-supplied tenant id is trusted.
* `ResolveTenant` runs **before** route-model binding, so `{member}` from another tenant is a 404.
* Errors use one envelope: `{"error": {"code", "message", "details", "request_id"}}` (`App\Support\Api\ErrorCode`).

## Tenant isolation — three layers

| Layer | Mechanism | Failure mode |
|---|---|---|
| Application | `BelongsToTenant` + `TenantScope` on every tenant model | **Throws** `TenantContextMissing` if queried without a tenant |
| Authorization | Every `Permission` is a Gate ability evaluated against the *active membership's* role | 403 |
| Database | PostgreSQL RLS, `ENABLE` + `FORCE`, policies on `app.tenant_id` / `app.user_id` / `app.rls_bypass` | No rows / insert rejected |

`TenantContext` is the single source of truth and mirrors itself into Postgres session variables
(`PostgresSessionVariables`, both read and write PDO, re-applied on reconnect).

* `TenantContext::run($tenant, fn)` — execute as a tenant (jobs, CLI). Suspends any outer bypass.
* `TenantContext::bypass(fn)` — platform operations only (Super Admin, webhook routing, schedulers).
* **Queued jobs** inherit the dispatching tenant automatically through Laravel Context; `JobTenantContext`
  restores it before `handle()` and restores the caller's state afterwards (safe for `sync` jobs inside requests).
* New tenant table checklist: `tenant_id` column → `TenantSchema::enableRls()` in the migration →
  `use BelongsToTenant` on the model → composite FK via `TenantSchema::tenantForeign()` for tenant-owned parents.
  `RowLevelSecurityTest` fails CI if any table with `tenant_id` lacks forced RLS.
* Do not switch tenants inside an open DB transaction: a rollback also rolls back the session variables.

## Plans & entitlements

`plans → plan_versions (immutable once active) → plan_version_features → subscriptions → tenant_entitlement_overrides`
resolve into an `EntitlementSet` (cached in Valkey, invalidated on subscription/override change).

* Gate by **feature key** (`App\Domain\Plans\FeatureKey`), never by plan name.
* `EntitlementService::withinLimit()` performs check-then-act under a lock (no concurrent over-allocation).
* `402 plan_limit_reached` / `403 feature_not_available` let the client render upgrade prompts.
* **Never gate inbound messages or inbox replies** (blueprint non-gating rule).
* New workspaces: 14-day Pro trial → `engage:subscriptions:expire-trials` downgrades to Free.
* Overrides are writable only by the platform (RLS `WITH CHECK engage_rls_bypass()`).

## Super Admin (Inertia React, `admin` guard)

* **Sign-in:** password → TOTP (mandatory enrolment on first login, 8 one-time recovery codes, replay-protected).
* **Platform roles** (`PlatformRole` → `AdminAbility`): Super Admin (everything), Operations (all but team),
  Support (view + impersonate + queues), Finance (view + billing). Enforced by `admin.can:<ability>` route middleware.
* **Pages:** Overview (MRR, plan mix, trials, Tech Partner eligibility), Companies (search/filter, detail with
  plan change, trial extension, suspend/reactivate, per-feature overrides, members, activity), Users (disable/enable),
  Subscriptions, Audit log, Platform team, Queues (Horizon).
* Every admin request runs inside `TenantContext::bypass()` (`admin.context`); tenant mutations go through
  `TenantContext::run()`. Every action is audited with actor `admin`.
* **Impersonation:** "Log in as a member" (reason required) creates a 60-minute `impersonation_sessions` row and a
  2-minute one-time token → `FRONTEND_URL/impersonate?token=…` → Next.js `POST /api/v1/auth/impersonation`.
  During the session the tenant is pinned, switching is blocked, `/me` returns an `impersonation` block for the
  banner, and all audit entries are attributed to the admin (`meta.impersonated_user_id`). Ending or expiry logs out.

## Queues (Horizon)

`critical`, `webhooks`, `messaging`, `default` + `notifications`, `maintenance` — separate supervisors in
`config/horizon.php`. `after_commit` is on for every connection. Horizon dashboard: platform admins only.

## Scheduled

`engage:partitions` (daily — monthly partitions for `audit_log`, warns on default-partition rows),
`engage:subscriptions:expire-trials` (15 min), `horizon:snapshot`, `queue:prune-failed`.

## API v1 (Phase 1)

| Method | Path | Guard |
|---|---|---|
| POST | `/api/v1/auth/register`, `/auth/login` | guest, `throttle:auth` |
| POST | `/api/v1/auth/logout` | auth |
| GET | `/api/v1/me` | auth — user, memberships, active tenant, permissions, entitlements |
| PUT | `/api/v1/me/active-tenant` | auth |
| POST | `/api/v1/invitations/{token}/accept` | auth |
| GET/PATCH | `/api/v1/tenant` | tenant, `settings.manage` for PATCH |
| GET | `/api/v1/tenant/entitlements` | tenant |
| GET | `/api/v1/roles` | `team.view` |
| GET / PATCH / DELETE | `/api/v1/team/members[/{member}]` | `team.view` / `team.manage` |
| GET / POST / DELETE | `/api/v1/team/invitations[/{invitation}]` | `team.view` / `team.manage` |
| GET | `/api/v1/audit-logs` | `audit.view` (window = plan retention) |
| POST | `/api/v1/auth/impersonation` | guest (one-time support token) |
| DELETE | `/api/v1/auth/impersonation` | auth (ends the support session) |

Next.js: call `GET /sanctum/csrf-cookie` first, then send requests with `credentials: 'include'`.
Production: API `api.engage.10xdigital.ae`, client `app.engage.10xdigital.ae`,
`SESSION_DOMAIN=.engage.10xdigital.ae`, `SANCTUM_STATEFUL_DOMAINS=app.engage.10xdigital.ae`.

## Production hardening (before go-live)

* Split DB roles: `engage_migrator` (owns tables, runs migrations) and `engage_app` (DML only). Both NOBYPASSRLS.
* Serve HTTP with PHP-FPM/FrankenPHP behind Nginx or a load balancer (the compose `app` service is dev-only).
* `LOG_STACK=json`, `SESSION_SECURE_COOKIE=true`, PgBouncer in **session** mode (session variables require it)
  or transaction mode only after moving `set_config` to `SET LOCAL` per transaction.

## File structure

```
engage-backend/
├── .github/
│   └── workflows/
│       └── ci.yml
├── app/
│   ├── Application/
│   │   ├── Admin/
│   │   │   ├── ManageEntitlementOverride.php
│   │   │   ├── ManagePlatformAdmins.php
│   │   │   └── ManageTenant.php
│   │   ├── Team/
│   │   │   ├── AcceptInvitation.php
│   │   │   ├── ChangeMemberRole.php
│   │   │   ├── InviteMember.php
│   │   │   ├── OwnerGuard.php
│   │   │   ├── RemoveMember.php
│   │   │   └── RevokeInvitation.php
│   │   └── Tenancy/
│   │       ├── RegisterWorkspace.php
│   │       └── SwitchActiveTenant.php
│   ├── Console/
│   │   └── Commands/
│   │       ├── CreatePlatformAdmin.php
│   │       ├── EnsurePartitions.php
│   │       └── ExpireTrials.php
│   ├── Domain/
│   │   ├── Access/
│   │   │   ├── Models/
│   │   │   │   └── Role.php
│   │   │   ├── Scopes/
│   │   │   │   └── RoleVisibilityScope.php
│   │   │   ├── Permission.php
│   │   │   └── SystemRole.php
│   │   ├── Audit/
│   │   │   ├── Models/
│   │   │   │   └── AuditLog.php
│   │   │   └── AuditLogger.php
│   │   ├── Billing/
│   │   │   ├── Enums/
│   │   │   │   ├── BillingProvider.php
│   │   │   │   └── SubscriptionStatus.php
│   │   │   ├── Models/
│   │   │   │   ├── Subscription.php
│   │   │   │   └── SubscriptionEvent.php
│   │   │   └── SubscriptionService.php
│   │   ├── Identity/
│   │   │   ├── Enums/
│   │   │   │   ├── AdminAbility.php
│   │   │   │   └── PlatformRole.php
│   │   │   ├── Models/
│   │   │   │   ├── PlatformAdmin.php
│   │   │   │   └── User.php
│   │   │   └── TwoFactor/
│   │   │       └── Totp.php
│   │   ├── Plans/
│   │   │   ├── Entitlements/
│   │   │   │   ├── Entitlement.php
│   │   │   │   ├── EntitlementService.php
│   │   │   │   └── EntitlementSet.php
│   │   │   ├── Enums/
│   │   │   │   ├── FeatureType.php
│   │   │   │   └── PlanVersionStatus.php
│   │   │   ├── Exceptions/
│   │   │   │   ├── FeatureNotAvailable.php
│   │   │   │   └── PlanLimitReached.php
│   │   │   ├── Models/
│   │   │   │   ├── Feature.php
│   │   │   │   ├── Plan.php
│   │   │   │   ├── PlanVersion.php
│   │   │   │   ├── PlanVersionFeature.php
│   │   │   │   └── TenantEntitlementOverride.php
│   │   │   ├── Usage/
│   │   │   │   ├── TeamSeatsCounter.php
│   │   │   │   ├── UsageCounter.php
│   │   │   │   └── UsageCounterRegistry.php
│   │   │   └── FeatureKey.php
│   │   ├── Platform/
│   │   │   ├── Exceptions/
│   │   │   │   ├── ImpersonationActive.php
│   │   │   │   └── ImpersonationInvalid.php
│   │   │   ├── Models/
│   │   │   │   └── ImpersonationSession.php
│   │   │   ├── {Models,Exceptions}/
│   │   │   └── Impersonation.php
│   │   └── Tenancy/
│   │       ├── Concerns/
│   │       │   └── BelongsToTenant.php
│   │       ├── Database/
│   │       │   └── PostgresSessionVariables.php
│   │       ├── Enums/
│   │       │   ├── MembershipStatus.php
│   │       │   └── TenantStatus.php
│   │       ├── Exceptions/
│   │       │   ├── CrossTenantWriteDetected.php
│   │       │   ├── InvitationInvalid.php
│   │       │   ├── LastOwner.php
│   │       │   ├── MembershipConflict.php
│   │       │   ├── MembershipSuspended.php
│   │       │   ├── TenantContextMissing.php
│   │       │   ├── TenantRequired.php
│   │       │   ├── TenantSelectionRequired.php
│   │       │   └── TenantUnavailable.php
│   │       ├── Models/
│   │       │   ├── Invitation.php
│   │       │   ├── Tenant.php
│   │       │   └── TenantMembership.php
│   │       ├── Queue/
│   │       │   └── JobTenantContext.php
│   │       ├── Scopes/
│   │       │   └── TenantScope.php
│   │       └── TenantContext.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   │   ├── Auth/
│   │   │   │   │   ├── LoginController.php
│   │   │   │   │   └── TwoFactorController.php
│   │   │   │   ├── AuditLogController.php
│   │   │   │   ├── CompanyController.php
│   │   │   │   ├── ImpersonationController.php
│   │   │   │   ├── OverviewController.php
│   │   │   │   ├── SubscriptionController.php
│   │   │   │   ├── TeamController.php
│   │   │   │   └── UserController.php
│   │   │   ├── Api/
│   │   │   │   └── V1/
│   │   │   │       ├── ActiveTenantController.php
│   │   │   │       ├── AuditLogController.php
│   │   │   │       ├── AuthController.php
│   │   │   │       ├── EntitlementController.php
│   │   │   │       ├── ImpersonationController.php
│   │   │   │       ├── InvitationController.php
│   │   │   │       ├── MeController.php
│   │   │   │       ├── RoleController.php
│   │   │   │       ├── TeamMemberController.php
│   │   │   │       └── TenantController.php
│   │   │   └── Controller.php
│   │   ├── Middleware/
│   │   │   ├── Admin/
│   │   │   │   ├── EnsureAbility.php
│   │   │   │   ├── HandleInertiaRequests.php
│   │   │   │   └── PlatformContext.php
│   │   │   ├── AssignRequestId.php
│   │   │   ├── EnsureFeature.php
│   │   │   ├── ForceJsonResponse.php
│   │   │   ├── ResolveTenant.php
│   │   │   └── SecurityHeaders.php
│   │   ├── Requests/
│   │   │   ├── Admin/
│   │   │   └── Api/
│   │   │       └── V1/
│   │   │           ├── InviteMemberRequest.php
│   │   │           ├── LoginRequest.php
│   │   │           ├── RegisterRequest.php
│   │   │           ├── SwitchActiveTenantRequest.php
│   │   │           ├── UpdateMemberRoleRequest.php
│   │   │           └── UpdateTenantRequest.php
│   │   └── Resources/
│   │       └── Api/
│   │           └── V1/
│   │               ├── AuditLogResource.php
│   │               ├── InvitationResource.php
│   │               ├── MembershipResource.php
│   │               ├── RoleResource.php
│   │               ├── TenantResource.php
│   │               └── UserResource.php
│   ├── Infrastructure/
│   │   └── Database/
│   │       └── MonthlyPartitionManager.php
│   ├── Notifications/
│   │   └── TenantInvitationNotification.php
│   ├── Providers/
│   │   ├── AccessServiceProvider.php
│   │   ├── AppServiceProvider.php
│   │   ├── HorizonServiceProvider.php
│   │   └── TenancyServiceProvider.php
│   └── Support/
│       ├── Admin/
│       │   └── PlatformMetrics.php
│       ├── Api/
│       │   ├── ApiExceptionRenderer.php
│       │   └── ErrorCode.php
│       ├── Exceptions/
│       │   └── DomainException.php
│       └── Queue/
│           └── QueueName.php
├── bootstrap/
│   ├── cache/
│   │   └── .gitignore
│   ├── app.php
│   └── providers.php
├── config/
│   ├── app.php
│   ├── auth.php
│   ├── cache.php
│   ├── cors.php
│   ├── database.php
│   ├── engage.php
│   ├── filesystems.php
│   ├── horizon.php
│   ├── inertia.php
│   ├── logging.php
│   ├── mail.php
│   ├── queue.php
│   ├── sanctum.php
│   ├── services.php
│   └── session.php
├── database/
│   ├── factories/
│   │   ├── TenantFactory.php
│   │   └── UserFactory.php
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php
│   │   ├── 0001_01_01_000002_create_jobs_table.php
│   │   ├── 2026_10_01_000001_create_rls_functions.php
│   │   ├── 2026_10_01_000010_create_platform_admins_table.php
│   │   ├── 2026_10_01_000020_create_tenants_table.php
│   │   ├── 2026_10_01_000030_create_roles_table.php
│   │   ├── 2026_10_01_000040_create_tenant_memberships_table.php
│   │   ├── 2026_10_01_000050_create_invitations_table.php
│   │   ├── 2026_10_01_000060_create_plan_catalog_tables.php
│   │   ├── 2026_10_01_000070_create_subscriptions_tables.php
│   │   ├── 2026_10_01_000080_create_audit_log_table.php
│   │   ├── 2026_10_01_000090_create_personal_access_tokens_table.php
│   │   └── 2026_10_02_000100_add_super_admin_support.php
│   ├── seeders/
│   │   ├── DatabaseSeeder.php
│   │   ├── PlanCatalogSeeder.php
│   │   └── SystemRoleSeeder.php
│   ├── support/
│   │   └── TenantSchema.php
│   └── .gitignore
├── docker/
│   ├── php/
│   │   └── Dockerfile
│   └── postgres/
│       └── init/
│           └── 01-engage.sql
├── resources/
│   ├── css/
│   │   └── admin.css
│   ├── js/
│   │   └── admin/
│   │       ├── components/
│   │       │   └── ui.tsx
│   │       ├── layouts/
│   │       │   ├── AdminLayout.tsx
│   │       │   └── AuthLayout.tsx
│   │       ├── lib/
│   │       │   └── format.ts
│   │       ├── pages/
│   │       │   ├── audit/
│   │       │   │   └── Index.tsx
│   │       │   ├── auth/
│   │       │   │   ├── Login.tsx
│   │       │   │   ├── RecoveryCodes.tsx
│   │       │   │   ├── TwoFactorChallenge.tsx
│   │       │   │   └── TwoFactorSetup.tsx
│   │       │   ├── companies/
│   │       │   │   ├── Index.tsx
│   │       │   │   └── Show.tsx
│   │       │   ├── subscriptions/
│   │       │   │   └── Index.tsx
│   │       │   ├── team/
│   │       │   │   └── Index.tsx
│   │       │   ├── users/
│   │       │   │   └── Index.tsx
│   │       │   └── Overview.tsx
│   │       ├── {components,layouts,lib,pages/
│   │       │   └── auth,pages/
│   │       │       └── companies,pages/
│   │       │           └── users,pages/
│   │       │               └── subscriptions,pages/
│   │       │                   └── audit,pages/
│   │       │                       └── team}/
│   │       ├── app.tsx
│   │       └── types.ts
│   └── views/
│       └── admin.blade.php
├── routes/
│   ├── api/
│   │   └── v1.php
│   ├── admin.php
│   ├── api.php
│   ├── console.php
│   └── web.php
├── tests/
│   ├── Feature/
│   │   ├── Admin/
│   │   │   ├── AdminAuthTest.php
│   │   │   ├── CompanyManagementTest.php
│   │   │   └── ImpersonationTest.php
│   │   ├── Api/
│   │   │   ├── AuthTest.php
│   │   │   ├── TeamTest.php
│   │   │   └── TenantResolutionTest.php
│   │   ├── Plans/
│   │   │   └── EntitlementTest.php
│   │   └── Tenancy/
│   │       ├── RowLevelSecurityTest.php
│   │       └── TenantScopeTest.php
│   ├── Unit/
│   │   ├── EntitlementValueTest.php
│   │   └── TotpTest.php
│   └── TestCase.php
├── .env.example
├── README.md
├── artisan
├── composer.json
├── docker-compose.yml
├── package.json
├── phpstan.neon
├── phpunit.xml
├── pint.json
├── tsconfig.json
└── vite.config.ts
```
