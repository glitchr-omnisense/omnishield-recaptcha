# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release. Omnishield reCAPTCHA: Google's captcha - v2 checkbox and invisible, v3 score with its threshold and action, and reCAPTCHA Enterprise assessments - its token checked by direct calls, no Google library.

### Changed

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnishield: ^1.0`: stable versions, so `composer require omnishield/recaptcha` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).
- `phpstan-baseline.neon`, empty: the shared workflow requires the file; nothing is accepted.
