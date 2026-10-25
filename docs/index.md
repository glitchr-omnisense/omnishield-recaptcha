# omnishield/recaptcha

Google reCAPTCHA as an Omnishield captcha, in four modes: v2 checkbox, v2 invisible, v3 score, and
Enterprise. The widget is Google's script (`https://www.google.com/recaptcha/api.js`, or
`enterprise.js`; `www.recaptcha.net` with the `domain` option); the token is checked by
`POST https://<domain>/recaptcha/api/siteverify` (v2, v3) or by an assessment,
`POST https://recaptchaenterprise.googleapis.com/v1/projects/<project_id>/assessments?key=<api_key>`
(Enterprise). The visitor's browser talks to Google and Google sets the `_GRECAPTCHA` cookie: load
it after consent ([privacy](https://github.com/glitchr-studio/omnishield/blob/1.x/docs/privacy.md)).

## Installation

```sh
composer require omnishield/recaptcha
```

PHP 8.2 or later, `omniscient/omnishield` and `symfony/http-client` (give the factory your
application's client, a `MockHttpClient` in a test).

## Credentials

- v2 and v3: a **site key** and a **secret** of a reCAPTCHA key made for the site's domains in
  Google's reCAPTCHA admin console (a v2 key for `checkbox` and `invisible`, a v3 key for `score`).
- Enterprise: the **site key**, the Google Cloud **project id** and an **API key** of that project.
- Test keys: v2 only (below).

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

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `mode` | string | `checkbox` | no | `checkbox`, `invisible`, `score`, `enterprise`; another: `InvalidConfigException` |
| `site_key` | string | - | yes | |
| `secret` | string | `null` | yes, but for `enterprise` | |
| `project_id`, `api_key` | string | `null` | yes for `enterprise` | the Google Cloud project, an API key of it |
| `threshold` | float, 0.0 to 1.0 | `0.5` | no | `score`, `enterprise`: a score under it is refused (`score`); outside 0..1: `InvalidConfigException` |
| `default_action` | string | `submit` | no | `score`, `enterprise`: the widget's action when the form names none |
| `domain` | string | `www.google.com` | no | `www.recaptcha.net`: the script and `siteverify` there, where google.com's other cookies are not |
| `language` | string | `null` | no | the widget's language (`hl`) |
| `theme`, `size` | string | `null` | no | `checkbox`: `light`, `dark` (`data-theme`); `normal`, `compact` (`data-size`) |
| `badge` | string | `null` | no | `invisible`: `bottomright`, `bottomleft`, `inline` (`data-badge`). To hide v3's badge, Google asks for "This site is protected by reCAPTCHA" in the page |
| `attributes` | map | `{}` | no | any other attribute of the element |

`new RecaptchaGatewayFactory(?HttpClientInterface $http = null)`: the application's HTTP client.
The gateway's title says its mode: `Google reCAPTCHA v2 (checkbox)`, `v2 (invisible)`, `v3`,
`Enterprise`. Constants: `RecaptchaGateway::FIELD` (`g-recaptcha-response`), `MODES`,
`TEST_SITE_KEY`, `TEST_SECRET`.

## Capabilities

| | `checkbox`, `invisible` | `score`, `enterprise` |
|---|---|---|
| is this token valid (`ChallengeInterface`) | yes | yes |
| a score in the verdict | no | yes, against `threshold` |
| the action checked | no | yes, when the attempt names one |
| the host checked | yes, when the attempt names one (not with the test keys) | the same |
| third party, cookies | Google, `_GRECAPTCHA` | the same |
| is this content spam, is this identity known | no | no |

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

## Example

A v3 score above and under the threshold, then an Enterprise assessment of a token used twice,
Google's answers given by a `MockHttpClient` (run in omnishield's workspace on 2026-10-26, offline):

```php
<?php

require 'vendor/autoload.php';

use Omnishield\Model\Attempt;
use Omnishield\Recaptcha\RecaptchaGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$json = static fn (array $answer) => new MockResponse(json_encode($answer));
$http = new MockHttpClient([
    $json(['success' => true, 'score' => 0.9, 'action' => 'contact', 'challenge_ts' => '2026-10-25T08:00:00Z', 'hostname' => 'www.example.org']),
    $json(['success' => true, 'score' => 0.2, 'action' => 'contact', 'challenge_ts' => '2026-10-25T08:00:00Z', 'hostname' => 'www.example.org']),
    $json(['tokenProperties' => ['valid' => false, 'invalidReason' => 'DUPE'], 'riskAnalysis' => ['score' => 0.0, 'reasons' => []]]),
]);
$v3 = (new RecaptchaGatewayFactory($http))->create(['mode' => 'score', 'site_key' => '6Lc-example', 'secret' => 'example-secret', 'threshold' => 0.5]);
echo $v3->getTitle(), ': ', $v3->widget('contact')->script, "\n";
foreach ([1, 2] as $i) {
    $verdict = $v3->verify(new Attempt('03AFcWeA-token', '192.0.2.10', 'contact'));
    printf("score %.1f: %s\n", $verdict->score, $verdict->passed ? 'passed' : 'refused ('.implode(', ', $verdict->reasons).')');
}
$enterprise = (new RecaptchaGatewayFactory($http))->create(['mode' => 'enterprise', 'site_key' => '6Lc-example', 'project_id' => 'my-project', 'api_key' => 'AIza-example']);
$verdict = $enterprise->verify(new Attempt('03AFcWeA-token', '192.0.2.10', 'contact'));
echo $enterprise->getTitle(), ': refused (', implode(', ', $verdict->reasons), '; ', implode(', ', $verdict->codes), ")\n";
```

```
Google reCAPTCHA v3: https://www.google.com/recaptcha/api.js?render=6Lc-example
score 0.9: passed
score 0.2: refused (score)
Google reCAPTCHA Enterprise: refused (duplicate; DUPE)
```

In Symfony:

```yaml
omnishield:
    gateways:
        google:
            factory: recaptcha
            options: { mode: score, site_key: '%env(RECAPTCHA_SITE_KEY)%', secret: '%env(RECAPTCHA_SECRET)%', threshold: 0.5 }
```

## Errors

Every failure of Google is an exception whose provider is `recaptcha`: the table of
[the answers](#the-answers) says which; and at `create()`, an `InvalidConfigException` for a key
missing for the mode, an unknown mode or a threshold outside 0..1. An empty token is a `missing`
verdict, never sent.

> [!NOTE]
> A made-up secret was seen answered `invalid-input-response` by Google's `siteverify` (2026-10-07),
> not `invalid-input-secret`: a wrong secret then reads as a wrong token (`invalid`), not as an
> `InvalidKeyException`. Check a new secret with a real token before going live.

## Verified, and not

| | |
|---|---|
| The family's conformance suite (`Omnishield\Testing\GatewayContractTests`) | **green on 2026-10-25**, offline: `Tests/RecaptchaContractTest.php` on the recorded v2 and written v3 answers; HTTP 401/403 now an `InvalidKeyException`, siteverify and Enterprise alike |
| v2 test keys against `siteverify` | **done on 2026-10-07** (`docker compose run --rm omnishield bare --live`, the recorded answers): passes, from `testkey.google.com`, through `www.google.com` and `www.recaptcha.net` alike |
| A made-up secret | **seen on 2026-10-07**: Google answers `invalid-input-response`, not `invalid-input-secret` - a wrong secret looks like a wrong token. Check a new secret with a real token before going live |
| v2 with a real key, the widget in a browser | **not verified**: no Google account, no browser run |
| v3 (`score`) | **not verified in real: no key** - the answers are written from the documentation's fields |
| Enterprise | **not verified in real: no project, no key** - the assessment and the error are written from the documentation and Google's API error format |
| The inline script of the invisible and score modes | not run in a browser |

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Google LLC. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence covers
this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with reCAPTCHA's terms ([Google
APIs Terms of Service](https://developers.google.com/terms)), for obtaining whatever they require
(an account, a key, a licence or subscription, an attribution or a citation, a rate limit to
respect), and for any use made of what it returns. The authors publish it free of charge, make no
commercial use of it, and accept no liability for how it is used (see the package's LICENSE).
