<?php

use App\Models\ApiToken;
use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Models\Contact;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Models\UserLevel;
use App\Models\UserRefreshToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

test('用户有多个订单', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    Order::factory()->count(3)->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    expect($user->orders)->toHaveCount(3);
});

test('用户有多个联系人', function () {
    $user = User::factory()->create();
    Contact::factory()->count(2)->create(['user_id' => $user->id]);

    expect($user->contacts)->toHaveCount(2);
});

test('用户有多个组织', function () {
    $user = User::factory()->create();
    Organization::factory()->count(2)->create(['user_id' => $user->id]);

    expect($user->organizations)->toHaveCount(2);
});

test('用户有多个 API 令牌', function () {
    $user = User::factory()->create();
    ApiToken::factory()->count(2)->create(['user_id' => $user->id]);

    expect($user->apiTokens)->toHaveCount(2);
});

test('用户有多个 CNAME 委托', function () {
    $user = User::factory()->create();
    CnameDelegation::factory()->count(2)->create(['user_id' => $user->id]);

    expect($user->cnameDelegations)->toHaveCount(2);
});

test('用户关联用户等级', function () {
    UserLevel::factory()->standard()->create();
    $user = User::factory()->create(['level_code' => 'standard']);

    expect($user->level)->toBeInstanceOf(UserLevel::class);
    expect($user->level->code)->toBe('standard');
});

test('密码自动哈希存储', function () {
    $user = User::factory()->create(['password' => 'testpassword']);

    expect(Hash::check('testpassword', $user->getRawOriginal('password')))->toBeTrue();
    expect($user->getRawOriginal('password'))->not->toBe('testpassword');
});

test('密码字段在序列化时隐藏', function () {
    $user = User::factory()->create();
    $array = $user->toArray();

    expect($array)->not->toHaveKey('password');
});

test('balance 为 decimal:2 格式', function () {
    $user = User::factory()->create(['balance' => '123.456']);
    $user->refresh();

    expect($user->balance)->toBe('123.46');
});

test('credit_limit 始终为负值', function () {
    $user = User::factory()->create(['credit_limit' => '500']);
    $user->refresh();

    expect((float) $user->credit_limit)->toBeLessThanOrEqual(0);
});

test('credit_limit 正值自动转为负值', function () {
    $user = User::factory()->create();
    $user->credit_limit = '100.00';
    $user->save();
    $user->refresh();

    expect((float) $user->credit_limit)->toBe(-100.0);
});

test('notification_settings 为 JSON cast', function () {
    $user = User::factory()->create([
        'notification_settings' => [],
    ]);
    $user->refresh();

    expect($user->notification_settings)->toBeArray();
});

test('auto_settings 为 JSON cast', function () {
    $user = User::factory()->create([
        'auto_settings' => ['auto_renew' => true, 'auto_reissue' => false],
    ]);
    $user->refresh();

    expect($user->auto_settings)->toBeArray();
    expect($user->auto_settings['auto_renew'])->toBeTrue();
    expect($user->auto_settings['auto_reissue'])->toBeFalse();
});

test('allowsNotification 默认返回 true', function () {
    $user = User::factory()->create(['notification_settings' => []]);

    expect($user->allowsNotification('cert_issued'))->toBeTrue();
});

test('allowsNotification 根据扁平设置返回', function () {
    config(['notification.user_default_preferences' => [
        'cert_issued' => true,
        'cert_expire' => true,
    ]]);

    $user = User::factory()->create([
        'notification_settings' => [
            'cert_issued' => false,
            'cert_expire' => true,
        ],
    ]);

    expect($user->allowsNotification('cert_issued'))->toBeFalse();
    expect($user->allowsNotification('cert_expire'))->toBeTrue();
});

test('allowsNotification 兼容老的嵌套 mail 结构', function () {
    config(['notification.user_default_preferences' => [
        'cert_issued' => true,
        'cert_expire' => true,
    ]]);

    $user = User::factory()->create([
        'notification_settings' => [
            'mail' => ['cert_issued' => false, 'cert_expire' => true],
            'sms' => ['cert_issued' => true],
        ],
    ]);

    expect($user->allowsNotification('cert_issued'))->toBeFalse();
    expect($user->allowsNotification('cert_expire'))->toBeTrue();
});

test('JWT 标识符返回主键', function () {
    $user = User::factory()->create();

    expect($user->getJWTIdentifier())->toBe($user->getKey());
});

test('JWT 自定义声明包含 token_version', function () {
    $user = User::factory()->create(['token_version' => 5]);

    $claims = $user->getJWTCustomClaims();
    expect($claims)->toHaveKey('token_version');
    expect($claims['token_version'])->toBe(5);
});

test('revokeAllSessions 自增 token_version 并落地 logout_at', function () {
    $user = User::factory()->create([
        'token_version' => 3,
        'logout_at' => null,
    ]);

    $user->revokeAllSessions();
    $user->refresh();

    // token_version 自增 1（旧 access token 进入黑名单宽限期）
    expect($user->token_version)->toBe(4);
    // logout_at 落地（中间件 checkTokenVersionGraceful 据此起算宽限期，不能漏）
    expect($user->logout_at)->not->toBeNull();
});

test('revokeAllSessions 清除该用户所有 refresh token', function () {
    $user = User::factory()->create(['token_version' => 0]);
    UserRefreshToken::createToken($user->id);
    UserRefreshToken::createToken($user->id);

    // 另一个用户的 refresh token 不应被波及
    $other = User::factory()->create();
    UserRefreshToken::createToken($other->id);

    expect(UserRefreshToken::where('user_id', $user->id)->count())->toBe(2);

    $user->revokeAllSessions();

    expect(UserRefreshToken::where('user_id', $user->id)->count())->toBe(0);
    expect(UserRefreshToken::where('user_id', $other->id)->count())->toBe(1);
});

test('revokeAllSessions 从 token_version 为 0 起步自增到 1', function () {
    $user = User::factory()->create(['token_version' => 0]);

    $user->revokeAllSessions();
    $user->refresh();

    expect($user->token_version)->toBe(1);
});

test('日期字段正确转换', function () {
    $user = User::factory()->loggedIn()->create();
    $user->refresh();

    expect($user->last_login_at)->toBeInstanceOf(Carbon::class);
    expect($user->join_at)->toBeInstanceOf(Carbon::class);
});

test('用户通过订单关联证书', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    Cert::factory()->count(2)->create(['order_id' => $order->id]);

    expect($user->certs)->toHaveCount(2);
});
