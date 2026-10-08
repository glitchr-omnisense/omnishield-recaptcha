# omniguard/recaptcha

**Google reCAPTCHA** for [glitchr/omniguard](https://github.com/glitchr-studio/omniguard), in its
four forms - v2 checkbox, v2 invisible, v3 score, Enterprise - checked by direct calls to Google:
`siteverify` for v2 and v3, the assessments API for Enterprise. No Google library (`google/recaptcha`
wants PHP 8.4 and takes no HTTP client of yours).

```php
use Omniguard\Model\Attempt;
use Omniguard\Recaptcha\RecaptchaGatewayFactory;

$gateway = (new RecaptchaGatewayFactory($httpClient))->create(['mode' => 'score', 'site_key' => $siteKey, 'secret' => $secret, 'threshold' => 0.5]);

$widget = $gateway->widget('contact');
echo $widget->html();         // api.js?render=<key>, a hidden g-recaptcha-response, a short script that asks for a token on submit

$verdict = $gateway->verify(Attempt::fromPost($_POST, $widget, $ip));
$verdict->score;              // 0.9; under the threshold: reasons ['score']
```

```yaml
omniguard:
    gateways:
        google:
            factory: recaptcha
            options: { mode: checkbox, site_key: '%env(RECAPTCHA_SITE_KEY)%', secret: '%env(RECAPTCHA_SECRET)%' }
```

reCAPTCHA reaches Google and sets a cookie (`_GRECAPTCHA`): load it after the visitor's consent,
and guard the form otherwise when they refuse (ALTCHA) - see omniguard's
[privacy](https://github.com/glitchr-studio/omniguard/blob/1.x/docs/privacy.md).
`glitchr/ux-google` keeps its own reCAPTCHA: this package neither touches nor needs it.

[Documentation](docs/index.md): the four modes, the options, the answers, the test keys, what was
verified - v2 against Google with its test keys; v3 and Enterprise on answers written from
Google's documentation, no key.

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
