<?php

namespace Omnishield\Recaptcha\Tests;

use Omnisense\Contracts\Policy\UsagePolicy;
use Omnishield\Exception\UnreachableException;
use Omnishield\Model\Attempt;
use Omnishield\Recaptcha\RecaptchaGateway;
use Omnishield\Recaptcha\RecaptchaGatewayFactory;
use Omnishield\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Its usage policy: declared as data, read by the gateway, enforced through the registry. */
final class PolicyTest extends TestCase
{
    public function testThePackageDeclaresItsPolicy(): void
    {
        $policy = UsagePolicy::fromFile(RecaptchaGateway::POLICY);
        $gateway = (new RecaptchaGatewayFactory(new MockHttpClient()))->create(['site_key' => RecaptchaGateway::TEST_SITE_KEY, 'secret' => RecaptchaGateway::TEST_SECRET]);

        self::assertSame('recaptcha', $policy->provider, 'named after the factory');
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame('allowed', $policy->rights->commercial->value);
        self::assertSame([1000, 1000000], array_map(static fn ($l) => $l->max, $policy->rateLimits ?? []));
        self::assertEquals($policy, $gateway->capabilities()->policy());
        self::assertMatchesRegularExpression('~"omnisense": \{\s*"policy": "policy\.json"~', (string) file_get_contents(__DIR__.'/../composer.json'), 'composer.json names it');
    }

    public function testA429AndItsRetryAfterHoldTheNextCallThroughTheRegistry(): void
    {
        $requests = 0;
        $http = new MockHttpClient(static function () use (&$requests) {
            ++$requests;

            return new MockResponse('Too Many Requests', ['http_code' => 429, 'response_headers' => ['retry-after' => '60']]);
        });
        $registry = new Registry([new RecaptchaGatewayFactory($http)], ['site' => ['factory' => 'recaptcha', 'options' => ['site_key' => RecaptchaGateway::TEST_SITE_KEY, 'secret' => RecaptchaGateway::TEST_SECRET]]]);
        foreach ([60, null] as $i => $wait) {
            try {
                $registry->challenge('site')->verify(new Attempt('a-token'));
                self::fail('refused');
            } catch (UnreachableException $e) {
                null === $wait ? self::assertGreaterThan(0, $e->retryAfter()) : self::assertSame($wait, $e->retryAfter());
            }
        }
        self::assertSame(1, $requests, 'the second call was held, nothing sent');
    }
}
