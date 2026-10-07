---
title: omniguard/recaptcha
order: 1
---

# omniguard/recaptcha

## Installation

```sh
composer require omniguard/recaptcha
```

PHP 8.2 or later, `glitchr/omniguard` and `symfony/http-client` (give the factory your
application's client, a `MockHttpClient` in a test).

## The sources

Written from Google's documentation as it read on 2026-10-07:
[verifying the user's response](https://developers.google.com/recaptcha/docs/verify)
(`siteverify`, its answer, its error codes; a token lasts two minutes and serves once),
[reCAPTCHA v3](https://developers.google.com/recaptcha/docs/v3) (`render=<key>`,
`grecaptcha.execute(key, {action})`, the score, the 0.5 threshold to start from, the actions'
characters), the [FAQ](https://developers.google.com/recaptcha/docs/faq) (the v2 test keys, the
`_GRECAPTCHA` cookie, `www.recaptcha.net`, the badge), and for Enterprise
[creating an assessment](https://docs.cloud.google.com/recaptcha/docs/create-assessment-website)
and the [`projects.assessments` reference](https://docs.cloud.google.com/recaptcha/docs/reference/rest/v1/projects.assessments)
(`tokenProperties`, `invalidReason`, `riskAnalysis`).

## The four modes

| `mode` | The widget | The check | Score |
|---|---|---|---|
| `checkbox` (v2) | `api.js`, `<div class="g-recaptcha" data-sitekey>`: Google adds `g-recaptcha-response` | `siteverify` | no |
| `invisible` (v2) | `api.js`, an element of this package's and a short script: on submit it renders the invisible widget, runs it, and submits once Google gives a token | `siteverify` | no |
| `score` (v3) | `api.js?render=<key>` and the same script: on submit, `grecaptcha.execute(key, {action})` | `siteverify`, then the score against `threshold`, the action against the one expected | yes |
| `enterprise` | `enterprise.js?render=<key>`, `grecaptcha.enterprise.execute` | an assessment: `POST https://recaptchaenterprise.googleapis.com/v1/projects/<project_id>/assessments?key=<api_key>` | yes |

The script asks for a token at submission, not at page load: a token lasts two minutes. It is
printed inline after the element (`Widget::$inline`): give `html()` the page's CSP nonce.

## Options

| Option | Default | |
|---|---|---|
| `mode` | `checkbox` | `checkbox`, `invisible`, `score`, `enterprise` |
| `site_key` | required | |
| `secret` | required, but for `enterprise` | |
| `project_id`, `api_key` | required for `enterprise` | the Google Cloud project, an API key of it |
| `threshold` | 0.5 | `score`, `enterprise`: a score under it is refused (`score`) |
| `default_action` | `submit` | `score`, `enterprise`: the widget's action when the form names none |
| `domain` | `www.google.com` | `www.recaptcha.net`: the script and `siteverify` there, where google.com's other cookies are not |
| `language` | | the widget's language (`hl`) |
| `theme`, `size` | | `checkbox`: `light`, `dark`; `normal`, `compact` |
| `badge` | | `invisible`: `bottomright`, `bottomleft`, `inline`. To hide v3's badge, Google asks for "This site is protected by reCAPTCHA" in the page |
| `attributes` | `{}` | any other attribute of the element |

## The answers

| Google answers | Verdict / exception |
|---|---|
| `success: true` (v2) | passed; the host checked when one is expected, but not for the test keys (`testkey.google.com`) |
| `success: true`, `score`, `action` (v3) | passed when the score reaches the threshold (`score`) and the action is the one expected (`action`) |
| `missing-input-response` / `invalid-input-response` / `timeout-or-duplicate` | `missing` / `invalid` / `spent` |
| `missing-input-secret`, `invalid-input-secret` | `InvalidKeyException` |
| `bad-request` | `ProviderException` |
| `tokenProperties.valid: false` (Enterprise) | by `invalidReason`: `MALFORMED`, `KEY_MISMATCH`, `BROWSER_ERROR` → `invalid`; `EXPIRED` → `expired`; `DUPE` → `duplicate`; `MISSING` → `missing`; `UNEXPECTED_ACTION` → `action`; `DOMAIN_MISMATCH` → `hostname` |
| `riskAnalysis` (Enterprise) | `score` against the threshold; its `reasons` (`AUTOMATION`, `UNEXPECTED_ENVIRONMENT`...) in the verdict's codes |
| an `error` object, HTTP 401/403, `PERMISSION_DENIED` (Enterprise) | `InvalidKeyException`; another error: `ProviderException` |
| no answer, HTTP 5xx, 429 | `UnreachableException` |

An empty token is refused at once, never sent: Google's test secret would pass it.

## Test keys

v2 only (Google's FAQ): site key `6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI`, secret
`6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe` - every check passes, from `testkey.google.com`, and the
widget shows a warning. v3 and Enterprise have none: Google advises a key of its own for testing.

## Verified, and not

| | |
|---|---|
| v2 test keys against `siteverify` | **done on 2026-10-07** (`docker compose run --rm omniguard bare --live`, the recorded answers): passes, from `testkey.google.com`, through `www.google.com` and `www.recaptcha.net` alike |
| A made-up secret | **seen on 2026-10-07**: Google answers `invalid-input-response`, not `invalid-input-secret` - a wrong secret looks like a wrong token. Check a new secret with a real token before going live |
| v2 with a real key, the widget in a browser | **not verified**: no Google account, no browser run |
| v3 (`score`) | **not verified in real: no key** - the answers are written from the documentation's fields |
| Enterprise | **not verified in real: no project, no key** - the assessment and the error are written from the documentation and Google's API error format |
| The inline script of the invisible and score modes | not run in a browser |
