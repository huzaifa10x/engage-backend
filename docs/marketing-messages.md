# Marketing Messages API (MM API)

Campaign **marketing** templates of an onboarded WhatsApp Business Account go through Meta's
Marketing Messages API (`POST /<PHONE_NUMBER_ID>/marketing_messages`, optimised delivery). Everything
else uses the Cloud API: utility and authentication templates, inbox and chatbot messages, and every
account that is not onboarded. MM status never blocks a campaign.

## Status (per WABA, `waba_accounts.mm_status`)

| Status | Meaning | Marketing route |
|---|---|---|
| `not_started` | not checked yet / Meta reports nothing | Cloud API |
| `eligible` | can onboard; terms not accepted | Cloud API |
| `request_sent` | the business was asked to accept | Cloud API |
| `onboarded` | terms accepted (`MM_LITE_TERMS_SIGNED`) | **MM API** |
| `not_eligible` | Meta does not offer it to this account | Cloud API |

Only Meta moves an account to `onboarded` (webhook or status read). Every change is stored in
`mm_onboarding_events`; every sent message records `messages.send_route`
(`mm_api` · `cloud_api` · `mm_fallback` = sent to the MM API, delivered by Meta through the Cloud API).

## How accounts get onboarded

- **New clients:** Embedded Signup carries `extras.features = [{name: marketing_messages_lite}]`, so
  Meta shows the terms in the popup. A minute after onboarding the status is read; if the business has
  not accepted, Meta's Intent API (`POST /<BUSINESS_ID>/onboard_partners_to_mm_lite`) emails its
  portfolio admins.
- **Existing clients:** run once after deploying —
  ```bash
  dc exec app php artisan engage:mm:enroll          # check every account, ask every eligible business
  dc exec app php artisan engage:mm:enroll --check  # status only
  ```
  The hourly `engage:mm:sync` does the same from then on (asks again after 7 days if unanswered).
- **When Meta refuses the request** (result `awaiting_client`): a portfolio admin of the business
  opens **WhatsApp Manager › Overview › Alerts › Accept terms**. Channels shows this to the client.
  A partner system-user token in `META_PARTNER_SYSTEM_TOKEN` is used for the Intent API when set.

A Tech Provider cannot accept the terms for a business; the platform asks and tracks.

## Settings

`META_MM_ENABLED` (off = everything through the Cloud API), `META_MM_IN_SIGNUP`,
`META_MM_SHARE_ACTIVITY` (`message_activity_sharing`), `META_PARTNER_SYSTEM_TOKEN`,
`META_MM_REQUEST_RETRY_DAYS`.

Webhook: the app must be subscribed to `account_update` (already required for onboarding).
