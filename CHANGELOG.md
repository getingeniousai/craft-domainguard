# DomainGuard Domain Watch Changelog

All notable changes to this plugin will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/), and this project adheres to [Semantic Versioning](http://semver.org/).

## 1.0.0 - 2026-10-29

### Added

- Initial release for Craft CMS 4.8+ and 5.x.
- One-click exposure scan of the site's own primary domain from the control panel, powered by the live DomainGuard engine: registered lookalike domains (permutation sweep), certificate-transparency history, and CAA posture, rolled into one real 0–100 exposure score with findings.
- Shareable 1200×630 score card (SVG) rendered only from a completed scan — a failed or unfinished scan never produces a card.
- Live footer badge (SVG) showing the current score, with a copy-paste embed snippet; the badge honestly reads "not watched yet" before the first completed scan.
- Score history of completed scans, stored in the plugin's own table.
- Pro edition: monitoring with email alerts (score drops, newly registered lookalikes, new certificates, lost CAA protection) via the `craft domainguard/monitor/run` console command, and one-click Takedown Packets — a ready-to-send registrar abuse report per registered lookalike.
