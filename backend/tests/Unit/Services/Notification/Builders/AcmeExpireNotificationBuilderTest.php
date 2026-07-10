<?php

use App\Models\Acme;
use App\Models\NotificationTemplate;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\Builders\AcmeExpireNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

afterEach(function () {
    Mockery::close();
});

function buildAcmePartialBuilder(Collection $acmes): AcmeExpireNotificationBuilder&MockInterface
{
    $builder = Mockery::mock(AcmeExpireNotificationBuilder::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $builder->shouldReceive('fetchExpiringAcmes')->andReturn($acmes);

    // build() 顶部调 get_system_setting('site', ...)，预填 cache 短路真实查询
    Cache::put('setting:group_name:site', ['url' => 'https://ssl.test/', 'name' => 'SSL证书管理系统'], 3600);

    return $builder;
}

function makeAcmeUser(?string $email = 'acme-user@example.com'): User
{
    return User::factory()->create(['email' => $email, 'username' => 'acmeuser']);
}

test('接收者非 User 时抛出异常', function () {
    $builder = new AcmeExpireNotificationBuilder;
    $intent = new NotificationIntent('acme_expire', 'user', 1);
    $notifiable = Mockery::mock(Model::class);

    $builder->build($intent, $notifiable);
})->throws(RuntimeException::class, '通知接收者必须为用户');

test('邮箱为空时抛出异常', function () {
    $user = makeAcmeUser(email: null);
    $builder = new AcmeExpireNotificationBuilder;
    $intent = new NotificationIntent('acme_expire', 'user', $user->id);

    $builder->build($intent, $user);
})->throws(RuntimeException::class, '邮箱为空');

test('无到期订阅 → 返回 null', function () {
    $user = makeAcmeUser();
    $builder = buildAcmePartialBuilder(new Collection);
    $intent = new NotificationIntent('acme_expire', 'user', $user->id, ['email' => 'acme-user@example.com']);

    expect($builder->build($intent, $user))->toBeNull();
});

test('订阅列表进 payload + site_url 注入 + 携密不入库（不含 eab_hmac、eab_kid 仅前缀）', function () {
    $user = makeAcmeUser();
    $product = Product::factory()->create(['name' => 'Certum ACME']);
    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays(7),
        'eab_kid' => 'kid-1234567890abcdef',
        'eab_hmac' => 'super-secret-hmac',
    ]);

    $builder = buildAcmePartialBuilder(new Collection([$acme->load('product')]));
    $intent = new NotificationIntent('acme_expire', 'user', $user->id, ['email' => 'acme-user@example.com']);

    $result = $builder->build($intent, $user);

    expect($result)->toBeInstanceOf(NotificationPayload::class);
    expect($result->data['subscriptions'])->toHaveCount(1);
    expect($result->data['subscriptions'][0]['product_name'])->toBe('Certum ACME');
    expect($result->data['site_url'])->toBe('https://ssl.test/');
    expect($result->data['username'])->toBe('acmeuser');
    expect($result->data['_meta']['subject'])->toContain('ACME 订阅到期提醒');

    // 携密不入库：payload 不含 eab_hmac 明文；eab_kid 仅前缀（截断）
    $flat = json_encode($result->data, JSON_UNESCAPED_UNICODE);
    expect($flat)->not->toContain('super-secret-hmac');
    expect($result->data['subscriptions'][0]['eab_kid'])->not->toContain('abcdef');
});

test('连续 14 天超集窗口：节点空隙（10 天后到期）订阅也被列入，非 active 不列（真实查询路径）', function () {
    // period_till 10 天后落节点 14/7 空隙——离散窗口会漏，连续超集恒覆盖
    $user = makeAcmeUser();
    $product = Product::factory()->create();
    Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays(10),
    ]);
    // cancelled 订阅即使落窗口内也不列（status=active 口径与派发一致）
    Acme::factory()->cancelled()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays(5),
    ]);

    $builder = new AcmeExpireNotificationBuilder;
    Cache::put('setting:group_name:site', ['url' => 'https://ssl.test/', 'name' => 'SSL证书管理系统'], 3600);
    $intent = new NotificationIntent('acme_expire', 'user', $user->id, ['email' => 'acme-user@example.com']);

    $result = $builder->build($intent, $user);

    expect($result)->toBeInstanceOf(NotificationPayload::class);
    expect($result->data['subscriptions'])->toHaveCount(1);
});

test('⑱：重查窗口上界由 max(EXPIRE_NOTIFY_NODES) 单一源派生（非硬编码 14），随节点集演进', function () {
    // 边界锁：把测试窗口耦合到 Builder 复用的同一常量。若 Builder 改回硬编码字面量而节点集含 >14 天，
    // 内侧订阅落 [now, now+max(NODES)] 内、硬编码窗口会漏掉 → count 变 0 → 本测试失败（捕获漂移）。
    $maxNode = max(AcmeExpireNotificationBuilder::EXPIRE_NOTIFY_NODES);

    $user = makeAcmeUser();
    $product = Product::factory()->create();

    // 恰落窗口上界内侧（max 节点前 1h）→ 应列入
    Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays($maxNode)->subHour(),
    ]);
    // 越过窗口上界（max 节点 + 1 天）→ 不应列入
    Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period_till' => now()->addDays($maxNode + 1),
    ]);

    $builder = new AcmeExpireNotificationBuilder;
    Cache::put('setting:group_name:site', ['url' => 'https://ssl.test/', 'name' => 'SSL证书管理系统'], 3600);
    $intent = new NotificationIntent('acme_expire', 'user', $user->id, ['email' => 'acme-user@example.com']);

    $result = $builder->build($intent, $user);

    // 窗口 = [now, now + max(NODES)]：内侧订阅列入、越界订阅排除
    expect($result)->toBeInstanceOf(NotificationPayload::class);
    expect($result->data['subscriptions'])->toHaveCount(1);
});

test('M-A 回归：产品已硬删的订阅被 whereHas 守卫过滤，不炸且不阻塞该 user 其他订阅的通知', function () {
    // Product 无 SoftDeletes、acmes.product_id 无外键，Admin destroy 硬删无引用守卫——产品可被删成孤儿。
    // 无 whereHas('product') 时解引用 $acme->product->name 抛 ErrorException → 整封 acme_expire 静默漏发。
    $user = makeAcmeUser();
    $aliveProduct = Product::factory()->create(['name' => 'Alive ACME']);
    $deadProduct = Product::factory()->create();

    // 正常订阅（应照常列出）
    Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $aliveProduct->id,
        'period_till' => now()->addDays(7),
    ]);
    // 产品被硬删的孤儿订阅（应被守卫过滤，而非炸掉整封邮件）
    Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $deadProduct->id,
        'period_till' => now()->addDays(5),
    ]);
    $deadProduct->delete();

    $builder = new AcmeExpireNotificationBuilder;
    Cache::put('setting:group_name:site', ['url' => 'https://ssl.test/', 'name' => 'SSL证书管理系统'], 3600);
    $intent = new NotificationIntent('acme_expire', 'user', $user->id, ['email' => 'acme-user@example.com']);

    $result = $builder->build($intent, $user);

    // 不炸；孤儿订阅被过滤，正常订阅照常通知（不被同封孤儿阻塞）
    expect($result)->toBeInstanceOf(NotificationPayload::class);
    expect($result->data['subscriptions'])->toHaveCount(1);
    expect($result->data['subscriptions'][0]['product_name'])->toBe('Alive ACME');
});

test('三件套端到端：seeder 幂等创建 acme_expire 模板 + config 命中 builder/偏好', function () {
    // builders + user_default_preferences 命中（缺一即端到端静默 drop）
    expect(config('notification.builders')['acme_expire'])->toBe(AcmeExpireNotificationBuilder::class);
    expect(config('notification.user_default_preferences')['acme_expire'])->toBeTrue();

    // seeder 幂等（firstOrCreate，保留 admin 自定义）
    (new NotificationTemplateSeeder)->run();
    (new NotificationTemplateSeeder)->run();

    $templates = NotificationTemplate::where('code', 'acme_expire')->get();
    expect($templates)->toHaveCount(1);
    expect($templates->first()->status)->toBe(1);
});
