<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialsInterface;
use Aws\Credentials\CredentialSources;
use Aws\Exception\CredentialsException;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use JsonException;
use Plugins\CloudDeploy\Support\CloudMetadataHttpClient;
use RuntimeException;
use Throwable;

/** AWS SDK credential callable：仅 IMDSv2，不回退默认 credential chain 或 IMDSv1。 */
final class AwsImdsCredentialProvider
{
    private const REFRESH_BEFORE_SECONDS = 300;

    private ?CredentialsInterface $cached = null;

    /** @var Closure():int */
    private readonly Closure $clock;

    /** @param null|callable():int $clock */
    public function __construct(
        private readonly CloudMetadataHttpClient $metadata,
        ?callable $clock = null,
    ) {
        $this->clock = $clock === null
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
    }

    public function __invoke(): PromiseInterface
    {
        try {
            $credentials = $this->credentials();

            return Create::promiseFor($credentials);
        } catch (Throwable) {
            // 上游 body/token/临时密钥均不进入异常消息或 previous。
            return Create::rejectionFor(new CredentialsException('AWS IMDSv2 获取临时凭证失败'));
        }
    }

    private function credentials(): CredentialsInterface
    {
        $now = ($this->clock)();
        $expiration = $this->cached?->getExpiration();
        if ($this->cached !== null
            && is_int($expiration)
            && $expiration > $now + self::REFRESH_BEFORE_SECONDS) {
            return $this->cached;
        }

        $token = $this->metadata->request(AwsMetadataRoute::Token);
        if (! $this->validOpaqueValue($token, 4096)) {
            throw new RuntimeException('Invalid IMDS token');
        }

        $role = trim($this->metadata->request(
            AwsMetadataRoute::RoleList,
            context: ['token' => $token],
        ));
        if (preg_match('/\A[A-Za-z0-9_+=,.@-]{1,64}\z/D', $role) !== 1) {
            throw new RuntimeException('Invalid IMDS role');
        }

        $body = $this->metadata->request(
            AwsMetadataRoute::RoleCredentials,
            ['role' => $role],
            ['token' => $token],
        );
        $data = $this->decodeCredentials($body);
        $expiresAt = $this->expirationTimestamp($data['Expiration']);
        if ($expiresAt <= $now + self::REFRESH_BEFORE_SECONDS) {
            throw new RuntimeException('IMDS credentials expire too soon');
        }

        return $this->cached = new Credentials(
            $data['AccessKeyId'],
            $data['SecretAccessKey'],
            $data['Token'],
            $expiresAt,
            source: CredentialSources::IMDS,
        );
    }

    /**
     * @return array{AccessKeyId:string,SecretAccessKey:string,Token:string,Expiration:string}
     */
    private function decodeCredentials(string $body): array
    {
        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Invalid IMDS credentials JSON');
        }

        if (! is_array($data) || ($data['Code'] ?? null) !== 'Success') {
            throw new RuntimeException('IMDS credentials were not successful');
        }

        foreach (['AccessKeyId', 'SecretAccessKey', 'Token', 'Expiration'] as $key) {
            if (! is_string($data[$key] ?? null) || ! $this->validOpaqueValue($data[$key], 16384)) {
                throw new RuntimeException('Invalid IMDS credential field');
            }
        }

        /** @var array{AccessKeyId:string,SecretAccessKey:string,Token:string,Expiration:string} $data */
        return $data;
    }

    private function expirationTimestamp(string $expiration): int
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $expiration) !== 1) {
            throw new RuntimeException('Invalid IMDS expiration');
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $expiration,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException('Invalid IMDS expiration');
        }

        return $date->getTimestamp();
    }

    private function validOpaqueValue(string $value, int $maxLength): bool
    {
        return $value !== ''
            && strlen($value) <= $maxLength
            && preg_match('/[\x00-\x20\x7f]/', $value) !== 1;
    }
}
