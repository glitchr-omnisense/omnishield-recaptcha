<?php

namespace Omnishield\Recaptcha\Tests;

use Omnishield\GatewayFactoryInterface;
use Omnishield\Model\Attempt;
use Omnishield\Recaptcha\RecaptchaGateway;
use Omnishield\Recaptcha\RecaptchaGatewayFactory;
use Omnishield\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The family's contract, on siteverify's answers to Google's v2 test keys (Fixtures/, recorded). */
final class RecaptchaContractTest extends TestCase
{
    use GatewayContractTests;

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new RecaptchaGatewayFactory($http);
    }

    protected function options(): array
    {
        return ['site_key' => RecaptchaGateway::TEST_SITE_KEY, 'secret' => RecaptchaGateway::TEST_SECRET];
    }

    protected function recordedCases(): iterable
    {
        yield 'test keys: passes' => [new Attempt('any-token', '192.0.2.10', hostname: 'www.example.org'), true, [self::recorded('siteverify-test-keys.json')]];
        yield 'an unknown secret: refused' => [new Attempt('any-token'), false, [self::recorded('siteverify-unknown-secret.json')]];
        yield 'spent' => [new Attempt('any-token'), false, [self::recorded('siteverify-spent.json')]];
        yield 'v3: a person' => [new Attempt('any-token', action: 'contact'), true, [self::recorded('siteverify-v3-human.json')], ['mode' => 'score', 'site_key' => 'a-v3-site-key', 'secret' => 'a-v3-secret']];
        yield 'v3: a bot' => [new Attempt('any-token', action: 'contact'), false, [self::recorded('siteverify-v3-bot.json')], ['mode' => 'score', 'site_key' => 'a-v3-site-key', 'secret' => 'a-v3-secret']];
    }

    private static function recorded(string $file): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file), ['response_headers' => ['content-type' => 'application/json; charset=utf-8']]);
    }
}
