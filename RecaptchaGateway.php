<?php

namespace Omnishield\Recaptcha;

use Omnishield\ChallengeInterface;
use Omnishield\Exception\InvalidKeyException;
use Omnishield\Exception\ProviderException;
use Omnishield\Http\Answer;
use Omnishield\Model\Attempt;
use Omnishield\Model\Capabilities;
use Omnishield\Model\Verdict;
use Omnishield\Model\Widget;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A reCAPTCHA token, checked by Google:
 *
 *   - checkbox, invisible, score: POST https://www.google.com/recaptcha/api/siteverify
 *     (secret, response, remoteip) - success, challenge_ts, hostname,
 *     error-codes; score and action for v3
 *     (https://developers.google.com/recaptcha/docs/verify, /docs/v3);
 *   - enterprise: POST https://recaptchaenterprise.googleapis.com/v1/projects/<project>/assessments?key=<api key>
 *     ({"event": {"token", "siteKey", "expectedAction", "userIpAddress"}}) -
 *     tokenProperties (valid, invalidReason, hostname, action, createTime),
 *     riskAnalysis (score, reasons)
 *     (https://docs.cloud.google.com/recaptcha/docs/create-assessment-website).
 *
 * A token lasts two minutes and serves once. The page's part - when to ask
 * Google for a token in the invisible and score modes - is a short script
 * of this package's, printed with the widget.
 */
final class RecaptchaGateway implements ChallengeInterface
{
    public const CHECKBOX = 'checkbox';
    public const INVISIBLE = 'invisible';
    public const SCORE = 'score';
    public const ENTERPRISE = 'enterprise';
    public const MODES = [self::CHECKBOX, self::INVISIBLE, self::SCORE, self::ENTERPRISE];

    public const FIELD = 'g-recaptcha-response';
    public const TEST_SITE_KEY = '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI';
    public const TEST_SECRET = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe';

    private const REASONS = [
        'missing-input-response' => Verdict::MISSING,
        'invalid-input-response' => Verdict::INVALID,
        'timeout-or-duplicate' => Verdict::SPENT,
    ];

    /** Enterprise's TokenProperties.InvalidReason, in the family's words. */
    private const INVALID_REASONS = [
        'MALFORMED' => Verdict::INVALID,
        'EXPIRED' => Verdict::EXPIRED,
        'DUPE' => Verdict::DUPLICATE,
        'MISSING' => Verdict::MISSING,
        'UNEXPECTED_ACTION' => Verdict::ACTION,
        'KEY_MISMATCH' => Verdict::INVALID,
        'DOMAIN_MISMATCH' => Verdict::HOSTNAME,
        'BROWSER_ERROR' => Verdict::INVALID,
    ];

    /**
     * When to ask for a token, in the invisible and score modes: on the
     * form's submission, once - then the form goes on.
     */
    private const SCRIPT = <<<'JS'
(function(){document.querySelectorAll('[data-omnishield-recaptcha]:not([data-omnishield-bound])').forEach(function(el){el.setAttribute('data-omnishield-bound','');var form=el.closest('form'),input=el.querySelector('input'),mode=el.getAttribute('data-omnishield-recaptcha'),key=el.getAttribute('data-sitekey'),action=el.getAttribute('data-action')||'submit',id=null;if(!form||!input)return;form.addEventListener('submit',function(e){if(input.value||!window.grecaptcha)return;e.preventDefault();var g=window.grecaptcha,done=function(token){input.value=token;form.requestSubmit?form.requestSubmit(e.submitter||undefined):form.submit();};if(mode==='invisible'){g.ready(function(){if(id===null){id=g.render(el.querySelector('div'),{sitekey:key,size:'invisible',badge:el.getAttribute('data-badge')||'bottomright',callback:done});}g.execute(id);});}else{var api=mode==='enterprise'?g.enterprise:g;api.ready(function(){api.execute(key,{action:action}).then(done);});}});});})();
JS;

    /** @param array<string, string|bool> $attributes */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $mode,
        private readonly string $siteKey,
        #[\SensitiveParameter] private readonly string $secret = '',
        private readonly ?string $projectId = null,
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        private readonly float $threshold = 0.5,
        private readonly string $defaultAction = 'submit',
        private readonly string $domain = 'www.google.com',
        private readonly ?string $language = null,
        private readonly array $attributes = [],
    ) {
    }

    public function getName(): string
    {
        return 'recaptcha';
    }

    public function getTitle(): string
    {
        return match ($this->mode) {
            self::CHECKBOX => 'Google reCAPTCHA v2 (checkbox)',
            self::INVISIBLE => 'Google reCAPTCHA v2 (invisible)',
            self::SCORE => 'Google reCAPTCHA v3',
            default => 'Google reCAPTCHA Enterprise',
        };
    }

    public function capabilities(): Capabilities
    {
        $scored = \in_array($this->mode, [self::SCORE, self::ENTERPRISE], true);

        return new Capabilities(challenge: true, thirdParty: true, cookies: true, scores: $scored, actions: $scored);
    }

    public function widget(?string $action = null): Widget
    {
        $action ??= \in_array($this->mode, [self::SCORE, self::ENTERPRISE], true) ? $this->defaultAction : null;
        $query = array_filter([
            'render' => \in_array($this->mode, [self::SCORE, self::ENTERPRISE], true) ? $this->siteKey : null,
            'hl' => $this->language,
        ]);
        $script = 'https://'.$this->domain.'/recaptcha/'.(self::ENTERPRISE === $this->mode ? 'enterprise.js' : 'api.js').($query ? '?'.http_build_query($query) : '');
        $common = ['field' => self::FIELD, 'script' => $script, 'scriptAttributes' => ['async' => true, 'defer' => true], 'siteKey' => $this->siteKey, 'action' => $action, 'thirdParty' => true, 'cookies' => true, 'origins' => ['https://'.$this->domain, 'https://www.gstatic.com']];

        if (self::CHECKBOX === $this->mode) {
            return new Widget(...$common, tag: 'div', attributes: ['class' => 'g-recaptcha', 'data-sitekey' => $this->siteKey] + $this->attributes);
        }

        return new Widget(
            ...$common,
            tag: 'div',
            attributes: ['data-omnishield-recaptcha' => $this->mode, 'data-sitekey' => $this->siteKey, 'data-action' => $action ?? false] + $this->attributes,
            content: '<input type="hidden" name="'.self::FIELD.'" value="">'.(self::INVISIBLE === $this->mode ? '<div></div>' : ''),
            inline: self::SCRIPT,
        );
    }

    public function verify(Attempt $attempt): Verdict
    {
        if ($attempt->isEmpty()) {
            // Google's test secret passes even an empty response: nothing is asked for none.
            return Verdict::fail(Verdict::MISSING);
        }

        return self::ENTERPRISE === $this->mode ? $this->assess($attempt) : $this->siteverify($attempt);
    }

    private function siteverify(Attempt $attempt): Verdict
    {
        $answer = Answer::send($this->http, 'recaptcha', 'POST', 'https://'.$this->domain.'/recaptcha/api/siteverify', ['body' => array_filter(['secret' => $this->secret, 'response' => $attempt->token, 'remoteip' => $attempt->ip])]);
        $data = $answer->json();
        $codes = array_values(array_map('strval', (array) ($data['error-codes'] ?? [])));
        if (array_intersect($codes, ['missing-input-secret', 'invalid-input-secret'])) {
            throw new InvalidKeyException('recaptcha', 'The secret was refused: '.implode(', ', $codes).'.', $codes[0]);
        }
        if (\in_array('bad-request', $codes, true)) {
            throw new ProviderException('recaptcha', 'The request was not understood: bad-request.', 'bad-request');
        }

        $score = isset($data['score']) ? (float) $data['score'] : null;
        $action = isset($data['action']) && '' !== $data['action'] ? (string) $data['action'] : null;
        $hostname = isset($data['hostname']) && '' !== $data['hostname'] ? (string) $data['hostname'] : null;
        $at = self::date($data['challenge_ts'] ?? null);
        if (true !== ($data['success'] ?? null)) {
            $reasons = array_values(array_unique(array_map(static fn (string $code) => self::REASONS[$code] ?? Verdict::INVALID, $codes ?: ['invalid-input-response'])));

            return new Verdict(false, $score, $action, $hostname, $at, $reasons, $codes);
        }

        return $this->judge($attempt, $score, $action, $hostname, $at, $codes);
    }

    private function assess(Attempt $attempt): Verdict
    {
        $answer = Answer::send($this->http, 'recaptcha', 'POST', \sprintf('https://recaptchaenterprise.googleapis.com/v1/projects/%s/assessments', rawurlencode((string) $this->projectId)), [
            'query' => ['key' => $this->apiKey],
            'json' => ['event' => array_filter(['token' => $attempt->token, 'siteKey' => $this->siteKey, 'expectedAction' => $attempt->action, 'userIpAddress' => $attempt->ip])],
        ]);
        $data = $answer->json();
        if (isset($data['error'])) {
            $status = (string) ($data['error']['status'] ?? $answer->status);
            $message = (string) ($data['error']['message'] ?? 'an error');
            if (\in_array($answer->status, [401, 403], true) || 'PERMISSION_DENIED' === $status || 'UNAUTHENTICATED' === $status || str_contains(strtolower($message), 'api key')) {
                throw new InvalidKeyException('recaptcha', 'The project or its API key was refused: '.$message, $status);
            }
            throw new ProviderException('recaptcha', $message, $status);
        }

        $token = (array) ($data['tokenProperties'] ?? []);
        $risk = (array) ($data['riskAnalysis'] ?? []);
        $score = isset($risk['score']) ? (float) $risk['score'] : null;
        $action = isset($token['action']) && '' !== $token['action'] ? (string) $token['action'] : null;
        $hostname = isset($token['hostname']) && '' !== $token['hostname'] ? (string) $token['hostname'] : null;
        $at = self::date($token['createTime'] ?? null);
        $codes = array_values(array_map('strval', (array) ($risk['reasons'] ?? [])));
        if (true !== ($token['valid'] ?? null)) {
            $invalid = (string) ($token['invalidReason'] ?? 'UNKNOWN_INVALID_REASON');

            return new Verdict(false, $score, $action, $hostname, $at, [self::INVALID_REASONS[$invalid] ?? Verdict::INVALID], [$invalid, ...$codes]);
        }

        return $this->judge($attempt, $score, $action, $hostname, $at, $codes);
    }

    /** @param list<string> $codes */
    private function judge(Attempt $attempt, ?float $score, ?string $action, ?string $hostname, ?\DateTimeImmutable $at, array $codes): Verdict
    {
        $reasons = [];
        if (\in_array($this->mode, [self::SCORE, self::ENTERPRISE], true)) {
            if (null === $score || $score < $this->threshold) {
                $reasons[] = Verdict::SCORE;
            }
            if (null !== $attempt->action && $attempt->action !== $action) {
                $reasons[] = Verdict::ACTION;
            }
        }
        // The test keys answer from testkey.google.com, whatever the site.
        if (null !== $attempt->hostname && self::TEST_SITE_KEY !== $this->siteKey && strtolower($attempt->hostname) !== strtolower((string) $hostname)) {
            $reasons[] = Verdict::HOSTNAME;
        }

        return new Verdict([] === $reasons, $score, $action, $hostname, $at, $reasons, $codes);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        try {
            return \is_string($value) && '' !== $value ? new \DateTimeImmutable($value) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
