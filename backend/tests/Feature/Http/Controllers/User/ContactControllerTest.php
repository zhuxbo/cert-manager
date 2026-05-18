<?php

use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

test('获取联系人列表', function () {
    $user = User::factory()->create();
    Contact::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->getJson('/api/contact')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('获取联系人列表-快速搜索', function () {
    $user = User::factory()->create();
    Contact::factory()->create([
        'user_id' => $user->id,
        'first_name' => 'TestName',
    ]);

    $this->actingAsUser($user)
        ->getJson('/api/contact?quickSearch=TestName')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('创建联系人', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->postJson('/api/contact', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '13800138000',
            'title' => 'CEO',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Contact::where('user_id', $user->id)->count())->toBe(1);
});

test('获取联系人详情', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->getJson("/api/contact/$contact->id")
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['id', 'first_name', 'last_name', 'email']]);
});

test('获取联系人详情-不存在', function () {
    $user = User::factory()->create();

    $this->actingAsUser($user)
        ->getJson('/api/contact/99999')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('更新联系人', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->putJson("/api/contact/$contact->id", [
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => 'updated@example.com',
            'phone' => '13900139000',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($contact->fresh()->first_name)->toBe('Updated');
});

test('删除联系人', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->create(['user_id' => $user->id]);

    $this->actingAsUser($user)
        ->deleteJson("/api/contact/$contact->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Contact::find($contact->id))->toBeNull();
});

test('批量获取联系人', function () {
    $user = User::factory()->create();
    $contacts = Contact::factory()->count(3)->create(['user_id' => $user->id]);
    $ids = $contacts->pluck('id')->toArray();

    $this->actingAsUser($user)
        ->getJson('/api/contact/batch?ids='.implode(',', $ids))
        ->assertOk()
        ->assertJson(['code' => 1]);
});

test('批量删除联系人', function () {
    $user = User::factory()->create();
    $contacts = Contact::factory()->count(3)->create(['user_id' => $user->id]);
    $ids = $contacts->pluck('id')->toArray();

    $this->actingAsUser($user)
        ->deleteJson('/api/contact/batch', ['ids' => $ids])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(Contact::whereIn('id', $ids)->count())->toBe(0);
});

test('联系人列表-未认证', function () {
    $this->getJson('/api/contact')
        ->assertUnauthorized();
});

test('destroy rejects when contact is referenced by organization', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->create(['user_id' => $user->id]);
    Organization::factory()->create([
        'user_id' => $user->id,
        'contact_id' => $contact->id,
    ]);
    $resp = $this->actingAsUser($user)->deleteJson("/api/contact/{$contact->id}");
    expect($resp->json('code'))->toBe(0);
    expect($resp->json('msg'))->toContain('正被');
    expect(Contact::find($contact->id))->not->toBeNull();
});

test('batchDestroy rejects any contact still referenced', function () {
    $user = User::factory()->create();
    $c1 = Contact::factory()->create(['user_id' => $user->id]);
    $c2 = Contact::factory()->create(['user_id' => $user->id]);
    Organization::factory()->create(['user_id' => $user->id, 'contact_id' => $c2->id]);
    $resp = $this->actingAsUser($user)->deleteJson('/api/contact/batch', ['ids' => [$c1->id, $c2->id]]);
    expect($resp->json('code'))->toBe(0);
    expect(Contact::count())->toBe(2);  // 整批未删
});
