<?php

use App\Models\Organization;
use App\Models\User;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

test('用户更新组织时无法将企业转移给其他用户', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();

    $org = Organization::factory()->create(['user_id' => $owner->id]);

    $this->actingAsUser($owner)
        ->putJson("/api/organization/$org->id", [
            'user_id' => $attacker->id,
            'name' => 'Hijacked Organization',
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

    expect($org->fresh()->user_id)->toBe($owner->id);
});
