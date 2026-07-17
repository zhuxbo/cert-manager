<?php

declare(strict_types=1);

namespace App\Services\ProductPrice;

use InvalidArgumentException;
use Throwable;

final class ProductPricePreviewToken
{
    public function issue(int $adminId, array $normalizedParams, string $stateFingerprint, int $expiresAt): string
    {
        $payload = json_encode([
            'admin_id' => $adminId,
            'params_hash' => $this->paramsHash($normalizedParams),
            'state_fingerprint' => $stateFingerprint,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $encodedPayload = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encodedPayload, $this->signingKey(), true);

        return $encodedPayload.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @return array{state_fingerprint: string, expires_at: int}
     */
    public function verify(string $token, int $adminId, array $normalizedParams): array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 2) {
                throw new InvalidArgumentException('预览令牌格式无效');
            }

            [$encodedPayload, $encodedSignature] = $parts;
            $payloadJson = $this->base64UrlDecode($encodedPayload);
            $signature = $this->base64UrlDecode($encodedSignature);
            if (strlen($signature) !== 32) {
                throw new InvalidArgumentException('预览令牌签名无效');
            }

            $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->signingKey(), true);
            if (! hash_equals($expectedSignature, $signature)) {
                throw new InvalidArgumentException('预览令牌签名无效');
            }

            $payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload)
                || ! is_int($payload['admin_id'] ?? null)
                || ! is_string($payload['params_hash'] ?? null)
                || ! is_string($payload['state_fingerprint'] ?? null)
                || ! is_int($payload['expires_at'] ?? null)) {
                throw new InvalidArgumentException('预览令牌载荷无效');
            }

            if ($payload['admin_id'] !== $adminId
                || ! hash_equals($payload['params_hash'], $this->paramsHash($normalizedParams))
                || $payload['expires_at'] <= now()->timestamp) {
                throw new InvalidArgumentException('预览令牌已失效');
            }

            return [
                'state_fingerprint' => $payload['state_fingerprint'],
                'expires_at' => $payload['expires_at'],
            ];
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidArgumentException('预览令牌无效', previous: $e);
        }
    }

    private function paramsHash(array $params): string
    {
        $levels = array_map(static fn (array $level): array => [
            'code' => $level['code'] ?? null,
            'cost_rate' => $level['cost_rate'] ?? null,
        ], is_array($params['levels'] ?? null) ? $params['levels'] : []);
        usort($levels, static fn (array $left, array $right): int => strcmp(
            (string) $left['code'],
            (string) $right['code'],
        ));

        $canonical = [
            'levels' => $levels,
            'precision' => $params['precision'] ?? null,
            'force' => $params['force'] ?? null,
            'sync_cost_rates' => $params['sync_cost_rates'] ?? null,
        ];

        return hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function signingKey(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new InvalidArgumentException('应用签名密钥未配置');
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('预览令牌编码无效');
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', $padding), true);
        if ($decoded === false || $this->base64UrlEncode($decoded) !== $value) {
            throw new InvalidArgumentException('预览令牌编码无效');
        }

        return $decoded;
    }
}
