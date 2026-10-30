# DomainGuard Domain Watch — Craft CMS plugin

**Your domain's exposure score, live in the control panel.**

Someone registering `your-compny.com` to phish your customers is invisible —
until they strike. DomainGuard Domain Watch puts the full DomainGuard engine
inside your Craft control panel and watches the one domain you can't afford
to lose: your own.

## The killer feature: the Live Lookalike Sweep

One click runs a permutation sweep of your site's own domain against live
DNS and registration data — every **registered lookalike domain** impersonating
you — together with your **certificate-transparency history** and **CAA
posture**, rolled into one real **0–100 exposure score** with plain-language
findings. Not a demo, not sample data: the complete scan, run against the
live DomainGuard engine, in about a minute.

## What you get (Lite — free)

- The complete real scan job: lookalikes, CT history, CAA, score, findings —
  including the engine's honest coverage note when a sweep is cut short.
- A **shareable score card** (1200×630 SVG) rendered from your exact scan.
  Failed scan? No card. The card is minted only from a completed result.
- A **live footer badge** showing your current score, with a copy-paste
  embed snippet. Before your first scan it honestly reads "not watched yet".
- **Score history** of every completed scan, stored on your own install.

## Pro edition

- **Monitoring** — scheduled re-scans (`php craft domainguard/monitor/run`,
  add it to cron) with an email the moment your score drops, a *new*
  lookalike registers, a new certificate appears, or your CAA protection
  disappears.
- **Takedown Packets** — for each registered lookalike, a ready-to-send
  registrar abuse report built only from what the scan actually observed.

Lite never loses anything it has; Pro adds the depth.

## How it works

The plugin talks to the DomainGuard developer API
(`https://domainguard.getingeniousai.com/api/v1`) with **your own**
DomainGuard API key (free keys include real scans every month; get one at
domainguard.getingeniousai.com). The only data sent is your site's own
primary domain — the thing being scanned. Scan reports are stored in your
own Craft database (`domainguard_scans` table).

## Install

```bash
composer require ingeniousai/craft-domainguard
./craft plugin/install domainguard
```

Then: **Settings → Plugins → DomainGuard Domain Watch** — paste your API
key (or reference an environment variable, e.g. `$DOMAINGUARD_API_KEY`) —
and open **DomainGuard** in the control-panel nav to run your first scan.

## Requirements

- Craft CMS 4.8.1+ or 5.x
- PHP 8.0.2+
- A DomainGuard API key (free tier available)

## Support

orders@getingeniousai.com · https://domainguard.getingeniousai.com

## License

Craft License (see LICENSE.md). Copyright © Ingenious AI.
