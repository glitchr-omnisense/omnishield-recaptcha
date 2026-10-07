<?php

namespace Omniguard\Recaptcha\Tests;

use Omniguard\ChallengeInterface;
use Omniguard\Exception\InvalidConfigException;
use Omniguard\Exception\InvalidKeyException;
use Omniguard\Exception\UnreachableException;
use Omniguard\Model\Attempt;
use Omniguard\Model\Verdict;
use Omniguard\Recaptcha\RecaptchaGateway;
use Omniguard\Recaptcha\RecaptchaGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * siteverify-test-keys.json and siteverify-unknown-secret.json were
 * recorded on 2026-10-07 (Google's v2 test secret; a made-up secret, which
 * Google answers with invalid-input-response, not invalid-input-secret).
 * The v3 and Enterprise answers (siteverify-v3-*, assessment-*) are written
 * from Google's documentation: there is no test key for either, and no
 * account was used.
 */
final class RecaptchaGatewayTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $calls = [];

    /** @param list<string|MockResponse> $answers */
    private function gateway(array $answers, array $options = []): ChallengeInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            if (isset($options['body']) && \is_string($options['body']) && !str_starts_with($options['body'], '{')) {
                parse_str($options['body'], $body);
            } else {
                $body = json_decode((string) ($options['body'] ?? 'null'), true);
            }
            $this->calls[] = [$method, $url, $body];
            $answer = array_shift($answers) ?? throw new \LogicException('No answer left.');
            if ($answer instanceof MockResponse) {
                return $answer;
            }
            [$file, $status] = explode(':', $answer.':200');

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file), ['http_code' => (int) $status, 'response_headers' => ['content-type' => 'application/json; charset=utf-8']]);
        });

        return (new RecaptchaGatewayFactory($http))->create($options + ['site_key' => RecaptchaGateway::TEST_SITE_KEY, 'secret' => RecaptchaGateway::TEST_SECRET]);
    }

    public function testTheTestKeysPassAnyTokenWhateverTheHost(): void
    {
        $verdict = $this->gateway(['siteverify-test-keys.json'])->verify(new Attempt('any-token', '192.0.2.10', hostname: 'www.example.org'));

        self::assertTrue($verdict->passed, implode(', ', $verdict->reasons));
        self::assertSame(['testkey.google.com', '2026-10-07T00:26:47+00:00', null], [$verdict->hostname, $verdict->at?->format(\DATE_ATOM), $verdict->score]);
        self::assertSame(['POST', 'https://www.google.com/recaptcha/api/siteverify', ['secret' => RecaptchaGateway::TEST_SECRET, 'response' => 'any-token', 'remoteip' => '192.0.2.10']], $this->calls[0]);
    }

    public function testAnEmptyTokenIsNotSentEvenThoughTheTestSecretWouldPassIt(): void
    {
        self::assertSame([Verdict::MISSING], $this->gateway([])->verify(new Attempt(''))->reasons);
        self::assertSame([], $this->calls);
    }

    public function testAnUnknownSecretLooksToGoogleLikeAnInvalidToken(): void
    {
        $verdict = $this->gateway(['siteverify-unknown-secret.json'], ['secret' => 'not-a-secret'])->verify(new Attempt('any-token'));

        self::assertSame([[Verdict::INVALID], ['invalid-input-response']], [$verdict->reasons, $verdict->codes]);
        self::assertSame([Verdict::SPENT], $this->gateway(['siteverify-spent.json'])->verify(new Attempt('t'))->reasons);
    }

    public function testASecretGoogleSaysIsInvalidIsThrown(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->gateway([new MockResponse('{"success": false, "error-codes": ["invalid-input-secret"]}')])->verify(new Attempt('t'));
    }

    public function testAV3ScoreUnderTheThresholdOrForAnotherActionIsRefused(): void
    {
        $options = ['mode' => 'score', 'site_key' => '6Lexample-v3-key', 'secret' => 'v3-secret'];

        $human = $this->gateway(['siteverify-v3-human.json'], $options)->verify(new Attempt('t', action: 'contact', hostname: 'www.example.org'));
        self::assertTrue($human->passed);
        self::assertSame([0.9, 'contact'], [$human->score, $human->action]);

        $bot = $this->gateway(['siteverify-v3-bot.json'], $options)->verify(new Attempt('t', action: 'contact'));
        self::assertSame([Verdict::SCORE], $bot->reasons);
        self::assertTrue($this->gateway(['siteverify-v3-bot.json'], $options + ['threshold' => 0.1])->verify(new Attempt('t'))->passed, 'a threshold of the site\'s');
        self::assertSame([Verdict::ACTION, Verdict::HOSTNAME], $this->gateway(['siteverify-v3-human.json'], $options)->verify(new Attempt('t', action: 'signup', hostname: 'evil.example'))->reasons);
    }

    public function testAnEnterpriseAssessment(): void
    {
        $options = ['mode' => 'enterprise', 'site_key' => '6Lexample-enterprise-key', 'project_id' => 'my-project', 'api_key' => 'AIzaExample'];

        $verdict = $this->gateway(['assessment-valid.json'], $options)->verify(new Attempt('TOKEN', '192.0.2.10', 'contact', 'www.example.org'));
        self::assertTrue($verdict->passed);
        self::assertSame([0.9, 'contact', 'www.example.org'], [$verdict->score, $verdict->action, $verdict->hostname]);
        self::assertSame('POST', $this->calls[0][0]);
        self::assertSame('https://recaptchaenterprise.googleapis.com/v1/projects/my-project/assessments?key=AIzaExample', $this->calls[0][1]);
        self::assertSame(['event' => ['token' => 'TOKEN', 'siteKey' => '6Lexample-enterprise-key', 'expectedAction' => 'contact', 'userIpAddress' => '192.0.2.10']], $this->calls[0][2]);

        $bot = $this->gateway(['assessment-automation.json'], $options)->verify(new Attempt('TOKEN', action: 'contact'));
        self::assertSame([[Verdict::SCORE], ['AUTOMATION']], [$bot->reasons, $bot->codes]);

        $dupe = $this->gateway(['assessment-dupe.json'], $options)->verify(new Attempt('TOKEN'));
        self::assertSame([[Verdict::DUPLICATE], ['DUPE']], [$dupe->reasons, $dupe->codes]);
    }

    public function testAnEnterpriseProjectThatRefusesIsTheSitesError(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage('[recaptcha] The project or its API key was refused: reCAPTCHA Enterprise API has not been used in project 123456789');
        $this->gateway(['assessment-permission-denied.json:403'], ['mode' => 'enterprise', 'project_id' => 'p', 'api_key' => 'k'])->verify(new Attempt('TOKEN'));
    }

    public function testGoogleSilentIsUnreachable(): void
    {
        $this->expectException(UnreachableException::class);
        $this->gateway([new MockResponse('', ['http_code' => 503])])->verify(new Attempt('t'));
    }

    public function testTheWidgetOfEachMode(): void
    {
        $checkbox = $this->gateway([], ['language' => 'fr', 'theme' => 'dark'])->widget('contact');
        self::assertSame('<script src="https://www.google.com/recaptcha/api.js?hl=fr" async defer></script><div class="g-recaptcha" data-sitekey="'.RecaptchaGateway::TEST_SITE_KEY.'" data-theme="dark"></div>', $checkbox->html());
        self::assertSame(['g-recaptcha-response', true, true, ['https://www.google.com', 'https://www.gstatic.com']], [$checkbox->field, $checkbox->thirdParty, $checkbox->cookies, $checkbox->origins]);

        $score = $this->gateway([], ['mode' => 'score', 'site_key' => 'v3key', 'domain' => 'www.recaptcha.net'])->widget();
        self::assertSame('https://www.recaptcha.net/recaptcha/api.js?render=v3key', $score->script);
        self::assertSame(['data-omniguard-recaptcha' => 'score', 'data-sitekey' => 'v3key', 'data-action' => 'submit'], $score->attributes, 'the default action');
        self::assertSame('<input type="hidden" name="g-recaptcha-response" value="">', $score->content);
        self::assertStringContainsString('api.execute(key,{action:action})', (string) $score->inline);

        $invisible = $this->gateway([], ['mode' => 'invisible', 'badge' => 'inline'])->widget();
        self::assertSame(['data-omniguard-recaptcha' => 'invisible', 'data-sitekey' => RecaptchaGateway::TEST_SITE_KEY, 'data-action' => false, 'data-badge' => 'inline'], $invisible->attributes);
        self::assertStringEndsWith('<div></div>', (string) $invisible->content);

        self::assertSame('https://www.google.com/recaptcha/enterprise.js?render=ekey', $this->gateway([], ['mode' => 'enterprise', 'site_key' => 'ekey', 'project_id' => 'p', 'api_key' => 'k'])->widget('login')->script);
    }

    public function testWhatIsMisconfigured(): void
    {
        foreach ([[['mode' => 'v4'], 'no mode "v4"'], [['threshold' => 2], 'from 0.0 to 1.0'], [['mode' => 'enterprise', 'project_id' => 'p'], 'needs: api_key']] as [$options, $message]) {
            try {
                (new RecaptchaGatewayFactory(new MockHttpClient()))->create($options + ['site_key' => 'k', 'secret' => 's']);
                self::fail($message);
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertSame([true, false], [(new RecaptchaGatewayFactory())->create(['mode' => 'score', 'site_key' => 'k', 'secret' => 's'])->capabilities()->scores, $this->gateway([])->capabilities()->scores]);
    }
}
