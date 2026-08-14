<?php

use App\Services\Order\Utils\DomainUtil;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

// ==================== getRootDomain ====================

test('get root domain', function (string $domain, string $expected) {
    expect(DomainUtil::getRootDomain($domain))->toBe($expected);
})->with([
    '标准域名' => ['example.com', 'example.com'],
    '子域名' => ['sub.example.com', 'example.com'],
    '多级子域名' => ['a.b.c.example.com', 'example.com'],
    '通配符域名' => ['*.example.com', 'example.com'],
    '通配符子域名' => ['*.sub.example.com', 'example.com'],
    'www子域名' => ['www.example.com', 'example.com'],
    '.org域名' => ['example.org', 'example.org'],
    '.net域名' => ['sub.example.net', 'example.net'],
    '.cn域名' => ['example.cn', 'example.cn'],
    '.com.cn域名' => ['example.com.cn', 'example.com.cn'],
    '.co.uk域名' => ['example.co.uk', 'example.co.uk'],
    '子域名.co.uk' => ['sub.example.co.uk', 'example.co.uk'],
]);

test('get root domain ipv4 returns empty', function () {
    expect(DomainUtil::getRootDomain('192.168.1.1'))->toBe('');
});

test('get root domain ipv6 returns empty', function () {
    expect(DomainUtil::getRootDomain('::1'))->toBe('');
    expect(DomainUtil::getRootDomain('2001:db8::1'))->toBe('');
});

// ==================== isValidDomain ====================

test('is valid domain returns true', function (string $domain) {
    expect(DomainUtil::isValidDomain($domain))->toBeTrue();
})->with([
    '标准域名' => ['example.com'],
    '子域名' => ['sub.example.com'],
    '多级子域名' => ['a.b.c.example.com'],
    '通配符域名' => ['*.example.com'],
    '数字开头' => ['123example.com'],
    '包含连字符' => ['my-example.com'],
    '下划线域名' => ['_dmarc.example.com'],
    '短域名' => ['a.co'],
    '最大长度标签' => [str_repeat('a', 63).'.com'],
]);

test('is valid domain returns false', function (string $domain) {
    expect(DomainUtil::isValidDomain($domain))->toBeFalse();
})->with([
    '空字符串' => [''],
    '连字符开头' => ['-example.com'],
    '连字符结尾' => ['example-.com'],
    '超长标签' => [str_repeat('a', 64).'.com'],
    '总长度超过253' => [str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 63).'.com'],
    '无效字符' => ['exam ple.com'],
    '双点' => ['example..com'],
]);

// ==================== getType ====================

test('get type', function (string $input, ?string $expected) {
    expect(DomainUtil::getType($input))->toBe($expected);
})->with([
    '标准域名' => ['example.com', 'standard'],
    '子域名' => ['sub.example.com', 'standard'],
    '通配符域名' => ['*.example.com', 'wildcard'],
    'IPv4' => ['192.168.1.1', 'ipv4'],
    'IPv4 localhost' => ['127.0.0.1', 'ipv4'],
    'IPv6 短格式' => ['::1', 'ipv6'],
    'IPv6 长格式' => ['2001:0db8:85a3:0000:0000:8a2e:0370:7334', 'ipv6'],
    '无效输入' => ['not a domain', null],
    '空字符串' => ['', null],
    '无效通配符' => ['*invalid', null],
]);

// ==================== convertToAscii / convertToUnicode ====================

test('convert to ascii', function () {
    expect(DomainUtil::convertToAscii('中文.com'))->toBe('xn--fiq228c.com');
    expect(DomainUtil::convertToAscii('example.com'))->toBe('example.com');
});

test('convert to unicode', function () {
    expect(DomainUtil::convertToUnicode('xn--fiq228c.com'))->toBe('中文.com');
    expect(DomainUtil::convertToUnicode('example.com'))->toBe('example.com');
});

test('convert to ascii domains', function () {
    expect(DomainUtil::convertToAsciiDomains('中文.com,example.com'))->toBe('xn--fiq228c.com,example.com');
});

test('convert to unicode domains', function () {
    expect(DomainUtil::convertToUnicodeDomains('xn--fiq228c.com,example.com'))->toBe('中文.com,example.com');
});

// ==================== isWildcardMatched ====================

test('is wildcard matched', function (string $domain, string $baseDomain, bool $expected) {
    expect(DomainUtil::isWildcardMatched($domain, $baseDomain))->toBe($expected);
})->with([
    '一级子域名匹配' => ['sub.example.com', 'example.com', true],
    'www匹配' => ['www.example.com', 'example.com', true],
    '基础域名本身不匹配' => ['example.com', 'example.com', false],
    '多级子域名不匹配' => ['a.b.example.com', 'example.com', false],
    '不同域名不匹配' => ['sub.other.com', 'example.com', false],
    '通配符域名不匹配' => ['*.example.com', 'example.com', false],
]);

// ==================== removeGiftDomain ====================

test('remove gift domain', function (string $domains, string $expected) {
    $result = DomainUtil::removeGiftDomain($domains);
    // 排序后比较，因为顺序可能不同
    $resultArray = array_filter(explode(',', $result));
    $expectedArray = array_filter(explode(',', $expected));
    sort($resultArray);
    sort($expectedArray);
    expect($resultArray)->toBe($expectedArray);
})->with([
    '通配符移除一级子域名' => ['*.example.com,www.example.com,sub.example.com', '*.example.com'],
    '通配符移除基础域名' => ['*.example.com,example.com', '*.example.com'],
    '保留多级子域名' => ['*.example.com,a.b.example.com', '*.example.com,a.b.example.com'],
    '保留www版本' => ['example.com,www.example.com', 'www.example.com'],
    '无通配符保留所有' => ['example.com,sub.example.com', 'example.com,sub.example.com'],
    '多个通配符' => ['*.a.com,*.b.com,www.a.com,www.b.com', '*.a.com,*.b.com'],
]);

// ==================== addGiftDomain ====================

test('add gift domain', function (string $domains, string $expected) {
    $result = DomainUtil::addGiftDomain($domains);
    $resultArray = array_filter(explode(',', $result));
    $expectedArray = array_filter(explode(',', $expected));
    sort($resultArray);
    sort($expectedArray);
    expect($resultArray)->toBe($expectedArray);
})->with([
    '根域名补齐www' => ['example.com', 'example.com,www.example.com'],
    'www补齐根域名' => ['www.example.com', 'example.com,www.example.com'],
    '通配符补齐基础域名' => ['*.example.com', '*.example.com,example.com'],
    '子域名不补齐' => ['sub.example.com', 'sub.example.com'],
    '已有不重复' => ['example.com,www.example.com', 'example.com,www.example.com'],
]);

// ==================== removeWildcardBaseDomains ====================

test('remove wildcard base domains', function () {
    $domains = ['example.com', 'www.example.com', 'other.com'];
    $wildcards = ['*.example.com'];

    $result = DomainUtil::removeWildcardBaseDomains($domains, $wildcards);

    expect(array_values($result))->toBe(['other.com']);
});

// ==================== removeWildcardMatchedDomains ====================

test('remove wildcard matched domains', function () {
    $domains = ['www.example.com', 'sub.example.com', 'a.b.example.com', 'other.com'];
    $wildcards = ['*.example.com'];

    $result = DomainUtil::removeWildcardMatchedDomains($domains, $wildcards);

    expect(array_values($result))->toBe(['a.b.example.com', 'other.com']);
});

// ==================== 边界情况 ====================

test('empty domains input remove', function () {
    // 空输入返回空结果
    $removeResult = DomainUtil::removeGiftDomain('');
    expect(array_filter(explode(',', $removeResult)))->toBeEmpty();
});

test('single empty string add', function () {
    // addGiftDomain 处理空字符串时会尝试获取根域名
    // 这个测试验证不会抛出异常
    $addResult = DomainUtil::addGiftDomain('');
    expect($addResult)->toBeString();
});

test('domains with spaces', function () {
    $result = DomainUtil::removeGiftDomain(' example.com , www.example.com ');
    expect($result)->toContain('example.com');
});

test('公共后缀缓存无法写入时仍使用已下载的规则', function () {
    $originalStoragePath = storage_path();
    $storagePath = sys_get_temp_dir().'/domain_util_cache_write_failure_'.uniqid();
    $dataFile = "$storagePath/domain-rules/public_suffix_list.dat";

    mkdir($dataFile, 0755, true);
    touch($dataFile, time() - (31 * 24 * 60 * 60));
    app()->useStoragePath($storagePath);

    $rules = new ReflectionProperty(DomainUtil::class, 'rules');
    $rules->setValue(null, null);

    stream_wrapper_unregister('https');
    stream_wrapper_register('https', DomainUtilSuccessfulHttpsStream::class);
    Log::spy();

    try {
        expect(DomainUtil::getRootDomain('sub.example.codexprobe'))
            ->toBe('example.codexprobe');

        Log::shouldHaveReceived('error')
            ->once()
            ->with('DomainParser Error in loadRules: Unable to write to data file');
    } finally {
        stream_wrapper_restore('https');
        app()->useStoragePath($originalStoragePath);
        $rules->setValue(null, null);
        File::deleteDirectory($storagePath);
    }
});

final class DomainUtilSuccessfulHttpsStream
{
    public mixed $context;

    private string $data = "// test rules\ncom\ncodexprobe\n";

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return [];
    }
}
