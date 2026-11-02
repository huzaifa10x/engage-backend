# Meta Click-to-WhatsApp referral fixtures

`messages[].referral` objects used by `tests/Feature/Growth/MetaAdLeadsTest.php`.

**Status: synthetic.** These follow the shape in Meta's Cloud API webhook reference. Sprint 0 check 5
replaces them with real payloads captured from three test ads (Instagram-only, Facebook-only, both
placements; 5+ each) using:

    php artisan engage:growth:export-referrals --since=2026-11-01 --out=storage/app/referrals.json

The command removes personal data: no phone numbers, names or message text, and the ad click ID
(`ctwa_clid`) and media URLs are replaced. Name new files `<placement>_<n>.json` and note in
this README which fields were missing and whether `source_url` showed the platform.
