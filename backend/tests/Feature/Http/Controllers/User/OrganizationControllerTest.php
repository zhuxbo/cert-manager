<?php

use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

test('获取组织列表', function () {
    $user = User::factory()->create();
    Organization::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->getJson('/api/organization')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('获取组织列表-快速搜索', function () {
    $user = User::factory()->create();
    Organization::factory()->create([
        'user_id' => $user->id,
        'name' => 'TestOrg',
    ]);

    $this->actingAsUser($user)
        ->getJson('/api/organization?quickSearch=TestOrg')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('创建组织', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->postJson('/api/organization', [
            'name' => 'Test Organization',
            'registration_number' => '1234567890',
            'country' => 'CN',
            'state' => 'Shanghai',
            'city' => 'Shanghai',
            'address' => 'Test Address',
            'postcode' => '200000',
            'phone' => '021-12345678',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Organization::where('user_id', $user->id)->count())->toBe(1);
});

test('获取组织详情', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->getJson("/api/organization/$org->id")
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['id', 'name', 'registration_number', 'country']]);
});

test('获取组织详情-不存在', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/organization/99999')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('更新组织', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->putJson("/api/organization/$org->id", [
            'name' => 'Updated Organization',
            'registration_number' => '9876543210',
            'country' => 'CN',
            'state' => 'Beijing',
            'city' => 'Beijing',
            'address' => 'Test Address',
            'postcode' => '100000',
            'phone' => '010-12345678',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($org->fresh()->name)->toBe('Updated Organization');
});

test('更新组织时未传联系人字段会保留原绑定', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->create(['user_id' => $user->id]);
    $org = Organization::factory()->create([
        'user_id' => $user->id,
        'contact_id' => $contact->id,
    ]);

    $this->actingAsUser($user)
        ->putJson("/api/organization/$org->id", [
            'name' => 'Updated Organization',
            'registration_number' => '9876543210',
            'country' => 'CN',
            'state' => 'Beijing',
            'city' => 'Beijing',
            'address' => 'Test Address',
            'postcode' => '100000',
            'phone' => '010-12345678',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($org->fresh()->contact_id)->toBe($contact->id);
});

test('删除组织', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->deleteJson("/api/organization/$org->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Organization::find($org->id))->toBeNull();
});

test('批量获取组织', function () {
    $user = User::factory()->create();
    $orgs = Organization::factory()->count(3)->create(['user_id' => $user->id]);
    $ids = $orgs->pluck('id')->toArray();

    $this->actingAsUser($user)
        ->getJson('/api/organization/batch?ids='.implode(',', $ids))
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('批量删除组织', function () {
    $user = User::factory()->create();
    $orgs = Organization::factory()->count(3)->create(['user_id' => $user->id]);
    $ids = $orgs->pluck('id')->toArray();

    $this->actingAsUser($user)
        ->deleteJson('/api/organization/batch', ['ids' => $ids])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Organization::whereIn('id', $ids)->count())->toBe(0);
});

test('组织列表-未认证', function () {
    $this->getJson('/api/organization')
        ->assertUnauthorized();
});

test('store creates organization with new contact in single transaction', function () {
    $user = User::factory()->create();

    $payload = [
        'name' => '示例企业',
        'registration_number' => '91110000XXXXXXXX01',
        'country' => 'CN', 'state' => '北京', 'city' => '北京',
        'address' => '海淀区中关村大街 1 号', 'postcode' => '100080', 'phone' => '010-12345678',
        'contact' => [
            'first_name' => '张三', 'last_name' => '',
            'identification_number' => '110101199001011234',
            'title' => '法人', 'email' => 'z@x.cn', 'phone' => '13800138000',
        ],
    ];
    $resp = $this->actingAsUser($user)->postJson('/api/organization', $payload);
    $resp->assertOk();
    expect(Organization::count())->toBe(1);
    expect(Contact::count())->toBe(1);
    expect(Organization::first()->contact_id)->toBe(Contact::first()->id);
});

test('store reuses existing contact when contact_id provided and no contact diff', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->create(['user_id' => $user->id]);

    $payload = [
        'name' => '示例企业 B', 'registration_number' => '91110000XXXXXXXX02',
        'country' => 'CN', 'state' => '北京', 'city' => '北京',
        'address' => '朝阳区 1 号', 'postcode' => '100020', 'phone' => '010-22222222',
        'contact_id' => $contact->id,
    ];
    $resp = $this->actingAsUser($user)->postJson('/api/organization', $payload);
    $resp->assertOk();
    expect(Contact::count())->toBe(1);
    expect(Organization::first()->contact_id)->toBe($contact->id);
});

test('store rejects contact_id belonging to other user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $contact = Contact::factory()->create(['user_id' => $other->id]);

    $payload = [
        'name' => '示例企业 C', 'registration_number' => '91110000XXXXXXXX03',
        'country' => 'CN', 'state' => '北京', 'city' => '北京', 'address' => 'X',
        'contact_id' => $contact->id,
    ];
    $resp = $this->actingAsUser($user)->postJson('/api/organization', $payload);
    expect($resp->json('code'))->toBe(0);
});

test('store rolls back when contact creation fails', function () {
    $user = User::factory()->create();

    // 用 saving 钩子在 DB 层抛异常，绕过 HTTP 验证层，验证事务真正回滚
    Contact::saving(function () {
        throw new RuntimeException('DB error simulation');
    });

    $payload = [
        'name' => '示例企业 D', 'registration_number' => '91110000XXXXXXXX04',
        'country' => 'CN', 'state' => '北京', 'city' => '北京', 'address' => 'X',
        'contact' => ['first_name' => '张三', 'email' => 'test@example.com'],
    ];
    $this->actingAsUser($user)->postJson('/api/organization', $payload);

    // 清理本测试注册的 saving 监听器，避免污染后续测试
    Contact::getEventDispatcher()->forget('eloquent.saving: '.Contact::class);

    expect(Organization::count())->toBe(0);
    expect(Contact::count())->toBe(0);
});

test('store with contact_id updates existing contact when fields change', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->create([
        'user_id' => $user->id,
        'first_name' => '旧名',
        'email' => 'old@example.com',
    ]);

    $payload = [
        'name' => '示例企业 E', 'registration_number' => '91110000XXXXXXXX05',
        'country' => 'CN', 'state' => '北京', 'city' => '北京',
        'address' => '海淀区 1 号', 'postcode' => '100080', 'phone' => '010-12345678',
        'contact_id' => $contact->id,
        'contact' => [
            'first_name' => '新名',
            'email' => 'new@example.com',
            'phone' => '13900139000',
        ],
    ];
    $resp = $this->actingAsUser($user)->postJson('/api/organization', $payload);
    $resp->assertOk()->assertJson(['code' => 1]);

    expect(Contact::count())->toBe(1);
    expect(Contact::first()->first_name)->toBe('新名');
});
