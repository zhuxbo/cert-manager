<?php

use App\Models\Admin;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create();
});

test('管理员可以获取联系人列表', function () {
    Contact::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/contact');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
});

test('管理员可以快速搜索联系人', function () {
    Contact::factory()->create(['user_id' => $this->user->id, 'first_name' => 'John']);
    Contact::factory()->create(['user_id' => $this->user->id, 'first_name' => 'Jane']);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/contact?quickSearch=John');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以按用户ID筛选联系人', function () {
    Contact::factory()->create(['user_id' => $this->user->id]);
    $otherUser = User::factory()->create();
    Contact::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/contact?user_id={$this->user->id}");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
});

test('管理员可以查看联系人详情', function () {
    $contact = Contact::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/contact/$contact->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $contact->id);
});

test('查看不存在的联系人返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/contact/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

test('管理员可以添加联系人', function () {
    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/contact', [
        'user_id' => $this->user->id,
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'contact@test.com',
        'phone' => '13800138000',
        'title' => 'Manager',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Contact::where('email', 'contact@test.com')->exists())->toBeTrue();
});

test('管理员可以更新联系人', function () {
    $contact = Contact::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->putJson("/api/admin/contact/$contact->id", [
        'user_id' => $this->user->id,
        'first_name' => 'Updated',
        'last_name' => 'Name',
        'email' => 'updated@test.com',
        'phone' => '13800138000',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $contact->refresh();
    expect($contact->first_name)->toBe('Updated');
});

test('管理员可以删除联系人', function () {
    $contact = Contact::factory()->create(['user_id' => $this->user->id]);

    $response = $this->actingAsAdmin($this->admin)->deleteJson("/api/admin/contact/$contact->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Contact::find($contact->id))->toBeNull();
});

test('管理员可以批量删除联系人', function () {
    $contacts = Contact::factory()->count(3)->create(['user_id' => $this->user->id]);
    $ids = $contacts->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->deleteJson('/api/admin/contact/batch', [
        'ids' => $ids,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect(Contact::whereIn('id', $ids)->count())->toBe(0);
});

test('管理员可以批量获取联系人', function () {
    $contacts = Contact::factory()->count(3)->create(['user_id' => $this->user->id]);
    $ids = $contacts->pluck('id')->toArray();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/contact/batch?ids[]='.implode('&ids[]=', $ids));

    $response->assertOk()->assertJson(['code' => 1]);
});

test('未认证用户无法访问联系人管理', function () {
    $response = $this->getJson('/api/admin/contact');

    $response->assertUnauthorized();
});
