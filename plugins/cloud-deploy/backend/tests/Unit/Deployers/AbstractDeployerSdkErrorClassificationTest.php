<?php

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Tests\TestCase;

uses(TestCase::class);

function sdkErrorClassificationDeployer(bool $terminal): AbstractDeployer
{
    return new class($terminal) extends AbstractDeployer
    {
        public function __construct(private readonly bool $terminal) {}

        public function provider(): string
        {
            return 'test';
        }

        public function product(): string
        {
            return 'classification';
        }

        public function label(): string
        {
            return 'SDK Error Classification';
        }

        public function configSchema(): array
        {
            return [];
        }

        public function bind(string|array $certRef, array $credentials, array $config): void
        {
            $this->guardSdk(fn () => throw new LogicException('RAW-SECRET'));
        }

        protected function makeClient(string $kind, array $credentials): object
        {
            return new stdClass;
        }

        protected function isTerminalSdkError(Throwable $e): bool
        {
            return $this->terminal && $e instanceof LogicException;
        }

        protected function sanitize(Throwable $e): string
        {
            return '安全的 SDK 错误';
        }
    };
}

test('结构化 SDK 终态由公共 guard 转为无原始异常链的业务异常', function () {
    try {
        sdkErrorClassificationDeployer(true)->bind('', [], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (DeployBusinessException $e) {
        expect($e->getMessage())->toBe('安全的 SDK 错误');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('RAW-SECRET');
    }
});

test('未分类 SDK 错误保持普通异常以便队列重试', function () {
    try {
        sdkErrorClassificationDeployer(false)->bind('', [], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e)->not->toBeInstanceOf(DeployBusinessException::class);
        expect($e->getMessage())->toBe('安全的 SDK 错误');
        expect($e->getPrevious())->toBeNull();
    }
});
