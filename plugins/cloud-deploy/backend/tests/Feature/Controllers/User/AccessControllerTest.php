<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;
use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;
use Tests\Traits\ActsAsUser;

uses(TestCase::class, RefreshDatabase::class, ActsAsUser::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->payload = [
        'name' => '我的阿里云',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK123', 'access_key_secret' => 'SECRET456'],
    ];
});

test('创建凭证：落库密文、响应不回显 credentials', function () {
    $res = $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', $this->payload)
        ->assertOk()
        ->assertJson(['code' => 1]);

    $access = CloudDeployAccess::withoutGlobalScopes()->where('user_id', $this->user->id)->first();
    expect($access)->not->toBeNull();

    $raw = DB::table('cloud_deploy_accesses')->where('id', $access->id)->value('credentials');
    expect($raw)->not->toContain('AK123')->not->toContain('SECRET456');
    expect(json_encode($res->json()))->not->toContain('SECRET456'); // 响应不回显明文（success() 无 data 键，对整体 JSON 断言）
});

test('EO Makers API Token 作为腾讯加密凭证保存且所有凭证接口不回显', function () {
    $token = 'makers-token-never-leak';

    $create = $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => '腾讯 EdgeOne Makers',
            'provider' => 'tencent',
            'credentials' => [
                'secret_id' => 'SID',
                'secret_key' => 'SKEY',
                'api_token' => $token,
            ],
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $access = CloudDeployAccess::withoutGlobalScopes()->firstOrFail();
    $raw = (string) DB::table('cloud_deploy_accesses')->where('id', $access->id)->value('credentials');
    $show = $this->actingAsUser($this->user)->getJson("/api/cloud-deploy/access/{$access->id}")->assertOk();
    $list = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/access')->assertOk();

    expect($access->credentials['api_token'])->toBe($token)
        ->and($raw)->not->toContain($token)
        ->and(json_encode([$create->json(), $show->json(), $list->json()]))->not->toContain($token)
        ->not->toContain('credentials');
});

test('credentials 缺 schema required 字段被拒（aliyun 缺 access_key_secret）', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => 'X', 'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'AK'], // 缺 access_key_secret
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->count())->toBe(0);
});

test('credentials required 字段为空串被拒（与 deployer requireConfig 同口径）', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => 'X', 'provider' => 'tencent',
            'credentials' => ['secret_id' => 'SID', 'secret_key' => ''],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->count())->toBe(0);
});

test('credentials 含 schema 外字段被拒（白名单校验，防额外字段静默落库）', function () {
    // 加固 2：credentials 的 key 集必须 ⊆ provider credentialSchema 的 key 集。
    // aliyun schema 仅 access_key_id / access_key_secret；多塞一个字段即拒绝。
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => 'X', 'provider' => 'aliyun',
            'credentials' => [
                'access_key_id' => 'AK', 'access_key_secret' => 'SK',
                'sts_token' => 'EXTRA', // schema 外字段
            ],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->count())->toBe(0);
});

test('条件 schema：select 默认/合法/非法值与隐藏凭证字段均按同一语义校验', function () {
    app(Registry::class)->registerProvider(new class implements ProviderInterface
    {
        public function key(): string
        {
            return 'conditional';
        }

        public function label(): string
        {
            return '条件测试';
        }

        public function credentialSchema(): array
        {
            return [
                ['key' => 'auth_method', 'label' => '认证方式', 'type' => 'select', 'default' => 'accesskey', 'options' => [
                    ['label' => '静态密钥', 'value' => 'accesskey'],
                    ['label' => '实例角色', 'value' => 'imds'],
                ]],
                ['key' => 'access_key', 'label' => 'Access Key', 'required_when' => ['key' => 'auth_method', 'equals' => 'accesskey'], 'visible_when' => ['key' => 'auth_method', 'equals' => 'accesskey']],
            ];
        }
    });

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => '缺默认 Access Key', 'provider' => 'conditional', 'credentials' => [],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => '默认静态密钥', 'provider' => 'conditional', 'credentials' => ['access_key' => 'AK'],
        ])
        ->assertOk()->assertJson(['code' => 1]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => '实例角色', 'provider' => 'conditional', 'credentials' => ['auth_method' => 'imds'],
        ])
        ->assertOk()->assertJson(['code' => 1]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => '非法认证方式', 'provider' => 'conditional', 'credentials' => ['auth_method' => 'unexpected'],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    // 前端切换为 imds 时会裁剪 access_key；直调保存入口带回旧值也必须被拒绝，不能落库。
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => '隐藏旧值', 'provider' => 'conditional', 'credentials' => ['auth_method' => 'imds', 'access_key' => 'STALE'],
        ])
        ->assertOk()->assertJson(['code' => 0]);
});

test('AWS 凭证兼容旧 accesskey 默认分支并允许纯 IMDS，拒绝分支外静态密钥', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => '旧 AWS 静态凭证',
            'provider' => 'aws',
            'credentials' => ['access_key_id' => 'AK', 'secret_access_key' => 'SK'],
        ])
        ->assertOk()->assertJson(['code' => 1]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => 'AWS 实例角色',
            'provider' => 'aws',
            'credentials' => ['auth_method' => 'imds'],
        ])
        ->assertOk()->assertJson(['code' => 1]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => 'AWS IMDS 带旧密钥',
            'provider' => 'aws',
            'credentials' => [
                'auth_method' => 'imds',
                'access_key_id' => 'STALE-AK',
                'secret_access_key' => 'STALE-SK',
            ],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => 'AWS 静态凭证缺密钥',
            'provider' => 'aws',
            'credentials' => ['auth_method' => 'accesskey'],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => 'AWS 非法认证方式',
            'provider' => 'aws',
            'credentials' => ['auth_method' => 'default-chain'],
        ])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->where('provider', 'aws')->count())->toBe(2);
});

test('Oracle 凭证兼容旧 API Key 默认分支并允许两种 principal，拒绝跨分支旧密钥', function () {
    $apiKey = [
        'tenancy_ocid' => 'ocid1.tenancy.oc1..t',
        'user_ocid' => 'ocid1.user.oc1..u',
        'fingerprint' => 'aa:bb',
        'private_key' => 'PRIVATE-KEY',
        'region' => 'ap-tokyo-1',
    ];

    foreach ([
        ['name' => 'Oracle 旧 API Key', 'credentials' => $apiKey, 'code' => 1],
        ['name' => 'Oracle Instance Principal', 'credentials' => ['auth_method' => 'instanceprincipal'], 'code' => 1],
        ['name' => 'Oracle Resource Principal', 'credentials' => ['auth_method' => 'resourceprincipal'], 'code' => 1],
        ['name' => 'Oracle principal 带旧密钥', 'credentials' => ['auth_method' => 'instanceprincipal'] + $apiKey, 'code' => 0],
        ['name' => 'Oracle API Key 缺字段', 'credentials' => ['auth_method' => 'apikey'], 'code' => 0],
        ['name' => 'Oracle 非法方式', 'credentials' => ['auth_method' => 'configfile'], 'code' => 0],
    ] as $case) {
        $this->actingAsUser($this->user)
            ->postJson('/api/cloud-deploy/access', [
                'name' => $case['name'],
                'provider' => 'oraclecloud',
                'credentials' => $case['credentials'],
            ])
            ->assertOk()->assertJson(['code' => $case['code']]);
    }

    expect(CloudDeployAccess::withoutGlobalScopes()->where('provider', 'oraclecloud')->count())->toBe(3);
});

test('创建凭证时拒绝指向回环地址的部署服务', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => '本机 SamWaf',
            'provider' => 'samwaf',
            'credentials' => [
                'server_url' => 'http://127.0.0.1:26666/api',
                'api_key' => 'secret',
            ],
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->count())->toBe(0);
});

test('创建凭证时允许公共地址且不会误伤 allow_insecure 设计取舍', function () {
    $this->actingAsUser($this->user)
        ->postJson('/api/cloud-deploy/access', [
            'name' => '公网 SamWaf',
            'provider' => 'samwaf',
            'credentials' => [
                'server_url' => 'https://1.1.1.1:9443/api',
                'api_key' => 'secret',
                'allow_insecure' => true,
            ],
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $access = CloudDeployAccess::withoutGlobalScopes()->firstOrFail();
    expect($access->credentials['allow_insecure'])->toBeTrue();
});

test('更新凭证不允许修改 provider', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]); // aliyun

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/access/{$access->id}", ['provider' => 'tencent'])
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->find($access->id)->provider)->toBe('aliyun'); // 未改
});

test('列表不含 credentials', function () {
    CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/access')->assertOk();
    $json = json_encode($res->json());
    expect($json)->not->toContain('SECRET456');
});

test('更新时 credentials 留空不覆盖原凭证', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/access/{$access->id}", ['name' => '改名了'])
        ->assertOk()->assertJson(['code' => 1]);

    $fresh = CloudDeployAccess::withoutGlobalScopes()->find($access->id);
    expect($fresh->name)->toBe('改名了');
    expect($fresh->credentials)->toBe(['access_key_id' => 'AK123', 'access_key_secret' => 'SECRET456']); // 未被清空
});

test('不能查看他人凭证', function () {
    $other = User::factory()->create();
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $other->id]);

    $this->actingAsUser($this->user)
        ->getJson("/api/cloud-deploy/access/{$access->id}")
        ->assertOk()->assertJson(['code' => 0]); // 查无（被 UserScope 挡）→ 业务失败
});

test('查看自己的凭证：响应不回显 credentials 明文', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);

    $res = $this->actingAsUser($this->user)
        ->getJson("/api/cloud-deploy/access/{$access->id}")
        ->assertOk()->assertJson(['code' => 1]);

    // show 走 toArray()，唯一防泄露屏障是 $hidden——HTTP 层钉死它
    expect(json_encode($res->json()))->not->toContain('AK123')->not->toContain('SECRET456');
    expect($res->json('data.credentials'))->toBeNull();
});

test('删除被 target 引用的凭证应被拒绝（删除生命周期守卫）', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);
    $order = Order::factory()->create(['user_id' => $this->user->id]);
    CloudDeployTarget::create([
        'user_id' => $this->user->id, 'access_id' => $access->id, 'order_id' => $order->id,
        'product' => 'cdn', 'config' => ['domain' => 'cdn.example.com'],
    ]);

    $this->actingAsUser($this->user)
        ->deleteJson("/api/cloud-deploy/access/{$access->id}")
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployAccess::withoutGlobalScopes()->whereKey($access->id)->exists())->toBeTrue();
});

test('删除未被引用的凭证成功', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);

    $this->actingAsUser($this->user)
        ->deleteJson("/api/cloud-deploy/access/{$access->id}")
        ->assertOk()->assertJson(['code' => 1]);

    expect(CloudDeployAccess::withoutGlobalScopes()->whereKey($access->id)->exists())->toBeFalse();
});

test('更新时显式传 credentials:null 也不覆盖原凭证', function () {
    $access = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);

    $this->actingAsUser($this->user)
        ->putJson("/api/cloud-deploy/access/{$access->id}", ['credentials' => null])
        ->assertOk()->assertJson(['code' => 1]);

    $fresh = CloudDeployAccess::withoutGlobalScopes()->find($access->id);
    expect($fresh->credentials)->toBe(['access_key_id' => 'AK123', 'access_key_secret' => 'SECRET456']);
});

test('按 provider 过滤凭证列表', function () {
    CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]); // aliyun
    CloudDeployAccess::create(['user_id' => $this->user->id, 'name' => '腾讯', 'provider' => 'tencent', 'credentials' => ['secret_id' => 'SID', 'secret_key' => 'SK']]);

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/access?provider=tencent')->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.provider'))->toBe('tencent');
});

test('按 name 模糊过滤凭证列表', function () {
    CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]); // name=我的阿里云
    CloudDeployAccess::create(['user_id' => $this->user->id, 'name' => '生产腾讯云', 'provider' => 'tencent', 'credentials' => ['secret_id' => 'SID', 'secret_key' => 'SK']]);

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/access?name='.urlencode('阿里'))->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.name'))->toBe('我的阿里云');
});

test('按 created_at 时间范围过滤凭证列表', function () {
    $old = CloudDeployAccess::create([...$this->payload, 'user_id' => $this->user->id]);
    $old->forceFill(['created_at' => '2020-01-01 00:00:00'])->saveQuietly();
    $new = CloudDeployAccess::create(['user_id' => $this->user->id, 'name' => '新', 'provider' => 'tencent', 'credentials' => ['secret_id' => 'SID', 'secret_key' => 'SK']]);
    $new->forceFill(['created_at' => '2026-06-01 00:00:00'])->saveQuietly();

    $res = $this->actingAsUser($this->user)->getJson('/api/cloud-deploy/access?created_at_start=2026-01-01 00:00:00&created_at_end=2026-12-31 23:59:59')->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.name'))->toBe('新');
});
