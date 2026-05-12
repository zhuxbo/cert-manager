<?php

use App\Models\DeployToken;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
});

/**
 * 创建测试用的 DeployToken
 */
function createDeployToken(array $overrides = []): DeployToken
{
    $user = test()->createTestUser();

    return DeployToken::create(array_merge([
        'user_id' => $user->id,
        'token' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        'status' => 1,
    ], $overrides));
}

/**
 * 测试 token 存储为加密值，同时生成 token_hash
 */
test('token is encrypted on save', function () {
    $rawToken = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
    $token = createDeployToken(['token' => $rawToken]);

    // 数据库中 token 字段不是原始值
    $dbRaw = $token->getRawOriginal('token');
    expect($dbRaw)->not->toBe($rawToken);

    // 数据库中 token_hash 是 SHA256 哈希
    expect($token->getRawOriginal('token_hash'))->toBe(hash('sha256', $rawToken));

    // 加密值可以被解密回原始值
    expect(Crypt::decryptString($dbRaw))->toBe($rawToken);
});

/**
 * 测试 getter 自动解密 token
 */
test('token getter decrypts', function () {
    $rawToken = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
    $token = createDeployToken(['token' => $rawToken]);

    // 从数据库重新加载
    $token->refresh();

    expect($token->token)->toBe($rawToken);
});

/**
 * 测试 token 为空时 setter 将属性设为 null
 */
test('empty token sets null attributes', function () {
    $token = new DeployToken;
    $token->token = '';

    expect($token->getAttributes()['token'])->toBeNull();
    expect($token->getAttributes()['token_hash'])->toBeNull();
});

/**
 * 测试 getter 处理无法解密的数据返回 null
 */
test('getter returns null on decrypt failure', function () {
    $token = createDeployToken();

    // 直接写入无效的加密数据
    DeployToken::where('id', $token->id)->update(['token' => 'invalid_encrypted_data']);
    $token->refresh();

    expect($token->token)->toBeNull();
});

/**
 * 测试 findByToken 通过原始 token 查找记录
 */
test('find by token', function () {
    $rawToken = 'x1y2z3w4x1y2z3w4x1y2z3w4x1y2z3w4';
    createDeployToken(['token' => $rawToken]);

    $found = DeployToken::findByToken($rawToken);
    expect($found)->not->toBeNull();
    expect($found->token)->toBe($rawToken);
});

/**
 * 测试 findByToken 查找不存在的 token 返回 null
 */
test('find by token returns null for unknown', function () {
    createDeployToken();

    $found = DeployToken::findByToken('nonexistent_token_value_32chars0');
    expect($found)->toBeNull();
});

/**
 * 测试 deleteTokenByToken 删除指定 token
 */
test('delete token by token', function () {
    $rawToken = 'd1e2f3a4d1e2f3a4d1e2f3a4d1e2f3a4';
    $token = createDeployToken(['token' => $rawToken]);
    $tokenId = $token->id;

    DeployToken::deleteTokenByToken($rawToken);

    expect(DeployToken::find($tokenId))->toBeNull();
});

/**
 * 测试 toArray 包含解密后的 token，不包含 token_hash
 */
test('to array includes token excludes hash', function () {
    $rawToken = 'b1c2d3e4b1c2d3e4b1c2d3e4b1c2d3e4';
    $token = createDeployToken(['token' => $rawToken]);
    $token->refresh();

    $array = $token->toArray();

    expect($array)->toHaveKey('token');
    expect($array['token'])->toBe($rawToken);
    expect($array)->not->toHaveKey('token_hash');
});

/**
 * 测试每次加密产生不同密文（随机 IV），但 token_hash 相同
 */
test('encryption produces different ciphertext', function () {
    $rawToken = 'f1e2d3c4f1e2d3c4f1e2d3c4f1e2d3c4';
    $token1 = createDeployToken(['token' => $rawToken]);
    $cipher1 = $token1->getRawOriginal('token');

    // 重新设置同一个 token
    $token1->token = $rawToken;
    $token1->save();
    $token1->refresh();
    $cipher2 = $token1->getRawOriginal('token');

    // 密文不同但都能解密为同一个值
    expect($cipher1)->not->toBe($cipher2);
    expect(Crypt::decryptString($cipher1))->toBe(Crypt::decryptString($cipher2));
});
