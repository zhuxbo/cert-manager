<?php

use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Order\Utils\ChainVerifier;
use Tests\TestCase;
use Tests\Traits\GeneratesCertChains;

uses(TestCase::class, GeneratesCertChains::class);

/**
 * ChainVerifier 三态签名校验（F2-4 P1-8）。
 *
 * 命令：openssl verify -partial_chain -no_check_time [-vfyopt distid:...(SM2)] -CAfile <inter> <leaf>
 * 判定纪律：stdout 含 ": OK" → 'ok'；含验签否决（verification failed / error N at M depth）→ 'bad'；
 *          其余（不可用/运行性失败/未知输出）→ 'unverifiable'（fail-open）。
 */

// 1. RSA 正链 → 'ok'
test('RSA 正链（CA 签发 leaf）→ ok', function () {
    $chain = $this->makeRsaChain();

    $verdict = app(ChainVerifier::class)->verifyIssued($chain['leaf'], $chain['ca'], 'RSA');

    expect($verdict)->toBe('ok');
});

// 2. RSA 错配 intermediate（另一 CA）→ 'bad'
test('RSA 错配 intermediate（同 CN 不同密钥的 CA）→ bad', function () {
    $chain = $this->makeRsaChain('leaf.example.com', 'Test RSA CA Shared');
    // 另造一张同 CN 不同密钥的 CA 作为错配 intermediate
    [, , $wrongCaPem] = $this->makeRsaCa('Test RSA CA Shared');

    $verdict = app(ChainVerifier::class)->verifyIssued($chain['leaf'], $wrongCaPem, 'RSA');

    expect($verdict)->toBe('bad');
});

// 3. openssl 不可用（BinaryLocator 抛 BinaryNotFoundException）→ 'unverifiable'（禁 skip）
test('BinaryLocator 抛 BinaryNotFoundException → unverifiable（fail-open）', function () {
    $chain = $this->makeRsaChain();

    $locator = Mockery::mock(BinaryLocator::class);
    $locator->shouldReceive('openssl')->andThrow(new BinaryNotFoundException(
        tool: 'openssl',
        triedPaths: ['/nonexistent'],
        diagnose: ['mock: openssl 不可用'],
    ));

    $verifier = new ChainVerifier($locator);
    $verdict = $verifier->verifyIssued($chain['leaf'], $chain['ca'], 'RSA');

    expect($verdict)->toBe('unverifiable');
});

// 4. SM2 正链 → 'ok'（catch distid 坑的正向硬门 C2）
test('SM2 正链（真实 SM2 CA 签 SM2 leaf，distid 一致）→ ok', function () {
    $chain = $this->makeSm2Chain();

    $verdict = app(ChainVerifier::class)->verifyIssued($chain['leaf'], $chain['ca'], 'SM2');

    expect($verdict)->toBe('ok');
});

// 5. SM2 破坏链 → 'bad'（反向硬门，防 distid 使两方向任一出假象）
test('SM2 破坏链（同 CN 错配 CA）→ bad', function () {
    $chain = $this->makeSm2MismatchedChain();

    $verdict = app(ChainVerifier::class)->verifyIssued($chain['leaf'], $chain['ca'], 'SM2');

    expect($verdict)->toBe('bad');
});

// 5b. 回归（impl-r1-chain Major）：subject 含 ": OK" 的坏链必须判 bad——
// openssl verify 失败时回显 leaf subject DN，若 subject 含 ": OK" 字样，
// ok 判据先于 bad 判据会被 subject 回显抢先误判通过。可识别否决必须优先。
test('subject 含 ": OK" 字样的坏链 → bad（否决判据优先于 ok 判据）', function () {
    $sharedCn = 'Bad CA For OK Subject';
    // leaf subject CN 故意含 ": OK"（verify 失败回显 "CN = leaf: OK example.com"）
    $chain = $this->makeRsaChain('leaf: OK example.com', $sharedCn);
    [, , $wrongCaPem] = $this->makeRsaCa($sharedCn);

    $verdict = app(ChainVerifier::class)->verifyIssued($chain['leaf'], $wrongCaPem, 'RSA');

    expect($verdict)->toBe('bad');
});

// 6. 多级 bundle 正链（签发 inter 不在首位）→ 'ok'（catch I1）
test('多级 bundle 正链（签发 CA 不在 bundle 首位）→ ok', function () {
    $chain = $this->makeRsaChainWithBundle();

    $verdict = app(ChainVerifier::class)->verifyIssued($chain['leaf'], $chain['ca'], 'RSA');

    expect($verdict)->toBe('ok');
});

// 7. 运行性失败（openssl 输出为空/不可解析）→ 'unverifiable'（三态纪律）
test('openssl 输出不可解析（intermediate 非法 PEM）→ unverifiable', function () {
    $chain = $this->makeRsaChain();

    // intermediate 传入垃圾串：openssl 既不输出 ": OK" 也不输出验签否决 → 归 unverifiable
    $verdict = app(ChainVerifier::class)->verifyIssued($chain['leaf'], 'not-a-valid-pem-garbage', 'RSA');

    expect($verdict)->toBe('unverifiable');
});

// 8. 过期链 + -no_check_time → 'ok'（边界固化：门禁只验签发关系不验有效期）
test('过期链 + -no_check_time → ok（不验有效期）', function () {
    $leafPem = file_get_contents(base_path('tests/Fixtures/expired_rsa_leaf.pem'));
    $caPem = file_get_contents(base_path('tests/Fixtures/expired_rsa_ca.pem'));

    $verdict = app(ChainVerifier::class)->verifyIssued($leafPem, $caPem, 'RSA');

    expect($verdict)->toBe('ok');
});
