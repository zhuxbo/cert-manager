<?php

use Plugins\CloudDeploy\Deployers\Aliyun\AliyunApigwDeployer;
use Tests\TestCase;

uses(TestCase::class);

function aliyunDomainMatcher(): object
{
    return new class extends AliyunApigwDeployer
    {
        public function matches(string $pattern, string $hostname): bool
        {
            return $this->hostnameMatches($pattern, $hostname);
        }
    };
}

test('Aliyun wildcard 匹配与 Certimate 的 hostname.IsMatch 对齐', function (string $pattern, string $hostname, bool $expected) {
    expect(aliyunDomainMatcher()->matches($pattern, $hostname))->toBe($expected);
})->with([
    '单层子域' => ['*.example.com', 'api.example.com', true],
    '等价根候选' => ['*.example.com', '.example.com', true],
    '等价泛域候选' => ['*.example.com', '*.example.com', true],
    '不扩大至多层子域' => ['*.example.com', 'deep.api.example.com', false],
    '不匹配裸根域' => ['*.example.com', 'example.com', false],
    '不匹配空后缀通配' => ['*.', '.', false],
    '精确匹配忽略大小写与末尾点' => ['Api.Example.com.', 'api.example.com', true],
]);
