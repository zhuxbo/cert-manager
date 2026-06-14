<?php

use App\Models\Cert;

test('ENC_FIELDS 常量锁定国密加密三元组列名顺序', function () {
    expect(Cert::ENC_FIELDS)->toBe(['enc_cert', 'enc_key', 'enc_key2']);
});

test('fillable 通过 ENC_FIELDS 展开仍包含国密三元组', function () {
    $fillable = (new Cert)->getFillable();

    foreach (Cert::ENC_FIELDS as $field) {
        expect($fillable)->toContain($field);
    }
});
