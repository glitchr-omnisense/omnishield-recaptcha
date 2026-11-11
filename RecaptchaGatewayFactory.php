<?php

namespace Omnishield\Recaptcha;

use Omnishield\Config;
use Omnishield\Exception\InvalidConfigException;
use Omnishield\GatewayFactory;
use Omnishield\GatewayInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google reCAPTCHA: the widget from Google, its token checked by direct
 * calls - siteverify for v2 and v3, the assessments API for Enterprise.
 *
 *   options:
 *     mode: checkbox                       # checkbox (v2), invisible (v2), score (v3), enterprise
 *     site_key: '%env(RECAPTCHA_SITE_KEY)%'   # required
 *     secret: '%env(RECAPTCHA_SECRET)%'    # required but for enterprise
 *     project_id: my-project               # enterprise: required, the Google Cloud project
 *     api_key: '%env(RECAPTCHA_API_KEY)%'  # enterprise: required, an API key of that project
 *     threshold: 0.5                       # score and enterprise: under it, refused
 *     default_action: submit               # score and enterprise: the action when the form names none
 *     domain: www.google.com               # or www.recaptcha.net, where google.com's other cookies are not
 *     language: ~                          # the widget's language (hl): fr, de
 *     theme: ~                             # checkbox: light, dark
 *     size: ~                              # checkbox: normal, compact
 *     badge: ~                             # invisible: bottomright, bottomleft, inline
 *     attributes: {}                       # any other attribute of the element
 *
 * Google's test keys for v2 (site key 6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI,
 * secret 6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe) pass every token, from
 * testkey.google.com: with them the host is not checked. v3 and Enterprise
 * have none.
 */
final class RecaptchaGatewayFactory extends GatewayFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnishield.factory_name' => 'recaptcha',
            'omnishield.factory_title' => 'Google reCAPTCHA',
            'omnishield.required_options' => array_merge(['site_key'], 'enterprise' === ($c['mode'] ?? null) ? ['project_id', 'api_key'] : ['secret']),
            'mode' => RecaptchaGateway::CHECKBOX,
            'secret' => null,
            'project_id' => null,
            'api_key' => null,
            'threshold' => 0.5,
            'default_action' => 'submit',
            'domain' => 'www.google.com',
            'language' => null,
            'theme' => null,
            'size' => null,
            'badge' => null,
            'attributes' => [],
        ]);
    }

    protected function build(Config $c): GatewayInterface
    {
        $mode = self::text($c['mode']);
        if (!\in_array($mode, RecaptchaGateway::MODES, true)) {
            throw new InvalidConfigException(\sprintf('The "recaptcha" gateway has no mode "%s": %s.', $mode, implode(', ', RecaptchaGateway::MODES)));
        }
        $threshold = self::number($c['threshold']);
        if ($threshold < 0.0 || $threshold > 1.0) {
            throw new InvalidConfigException(\sprintf('The "recaptcha" gateway\'s threshold is a score, from 0.0 to 1.0: %s.', self::text($c['threshold'])));
        }

        return new RecaptchaGateway(
            http: $this->http ?? HttpClient::create(),
            mode: $mode,
            siteKey: self::text($c['site_key']),
            secret: self::text($c['secret']),
            projectId: $c->string('project_id'),
            apiKey: $c->string('api_key'),
            threshold: $threshold,
            defaultAction: $c->string('default_action') ?? 'submit',
            domain: $c->string('domain') ?? 'www.google.com',
            language: $c->string('language'),
            attributes: array_filter(['data-theme' => $c->string('theme'), 'data-size' => $c->string('size'), 'data-badge' => $c->string('badge')]) + self::attributes($c['attributes']),
            backoff: $c->backoff(),
        );
    }

    /** An option as the (string) cast read it: a scalar as text, anything else empty. */
    private static function text(mixed $value): string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /** An option as the (float) cast read it: a scalar as its number; anything else 1.0 when it holds something, 0.0 when not. */
    private static function number(mixed $value): float
    {
        return \is_scalar($value) ? (float) $value : (float) (bool) $value;
    }

    /**
     * The widget's attributes as the (array) cast gave them, printed the same: a text or a boolean as
     * it is, a number as its text; what the widget never prints (null, a nested value) left out.
     *
     * @return array<string, string|bool>
     */
    private static function attributes(mixed $value): array
    {
        $attributes = [];
        foreach ((array) $value as $name => $one) {
            if (\is_string($one) || \is_bool($one)) {
                $attributes[(string) $name] = $one;
            } elseif (\is_int($one) || \is_float($one)) {
                $attributes[(string) $name] = (string) $one;
            }
        }

        return $attributes;
    }
}
