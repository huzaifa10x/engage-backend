# Meta Click-to-WhatsApp referral fixtures

`messages[].referral` objects used by `tests/Feature/Growth/MetaAdLeadsTest.php`.

How the chat starts: the customer taps the WhatsApp button on a Click-to-WhatsApp ad (or a boosted
post). WhatsApp opens with the business number, the customer sends the first message, and Meta
attaches `referral` to that message in the normal webhook. Every field is kept against the contact
and the conversation: `source_type` (`ad` | `post`), `source_id` (the ad ID, groups leads by ad),
`source_url`, `headline`, `body`, `media_type` with `image_url` / `video_url` / `thumbnail_url` (the
ad creative, shown in Source details) and `ctwa_clid` (the click ID, stored privately, never shown).
The referral has no platform field: "Meta ad · Instagram" needs proof (an Instagram `source_url`,
or the ad tagged as Instagram-only in Tracking Setup).

**Status: synthetic.** These follow the shape in Meta's Cloud API webhook reference. Sprint 0 check 5
replaces them with real payloads captured from three test ads (Instagram-only, Facebook-only, both
placements; 5+ each) using:

    php artisan engage:growth:export-referrals --since=2026-11-01 --out=storage/app/referrals.json

The command removes personal data: no phone numbers, names or message text, and the ad click ID
(`ctwa_clid`) and media URLs are replaced. Name new files `<placement>_<n>.json` and note in
this README which fields were missing and whether `source_url` showed the platform.
