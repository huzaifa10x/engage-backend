# Meta Click-to-WhatsApp referral fixtures

`messages[].referral` objects used by `tests/Feature/Growth/MetaAdLeadsTest.php`.

How the chat starts: the customer taps the WhatsApp call-to-action button ("Send message" /
"WhatsApp") on a Click-to-WhatsApp ad or boosted post. WhatsApp opens with the business number, the
customer sends the first message, and Meta attaches `referral` to that message only. `media_type`
(`image`/`video`) and `image_url`/`video_url`/`thumbnail_url` describe the ad's creative, not
something the customer tapped; Growth Hub stores `media_type` (v1.2 candidate field) and never shows
it. Only `source_id`, `source_type`, `source_url`, `headline`, `body`, `ctwa_clid` and `media_type`
are kept; anything else in the object is ignored.

**Status: synthetic.** These follow the shape in Meta's Cloud API webhook reference. Sprint 0 check 5
replaces them with real payloads captured from three test ads (Instagram-only, Facebook-only, both
placements; 5+ each) using:

    php artisan engage:growth:export-referrals --since=2026-11-01 --out=storage/app/referrals.json

The command removes personal data: no phone numbers, names or message text, and the ad click ID
(`ctwa_clid`) and media URLs are replaced. Name new files `<placement>_<n>.json` and note in
this README which fields were missing and whether `source_url` showed the platform.
