<?php

use App\Services\Acme\Api\AcmeSourceApiInterface;
use App\Services\Acme\Api\Api;
use Tests\TestCase;

uses(TestCase::class);

test('interface defines 4 methods', function () {
    $reflection = new ReflectionClass(AcmeSourceApiInterface::class);
    $methods = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

    expect($methods)->toHaveCount(4);
    expect($methods)->toContain('new');
    expect($methods)->toContain('get');
    expect($methods)->toContain('cancel');
    expect($methods)->toContain('getProducts');
});

test('default api implements interface', function () {
    $reflection = new ReflectionClass(App\Services\Acme\Api\default\Api::class);

    expect($reflection->implementsInterface(AcmeSourceApiInterface::class))->toBeTrue();
});

test('router has new get cancel getProducts methods', function () {
    $reflection = new ReflectionClass(Api::class);
    $methods = array_map(fn ($m) => $m->getName(), $reflection->getMethods(ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('new');
    expect($methods)->toContain('get');
    expect($methods)->toContain('cancel');
    expect($methods)->toContain('getProducts');
});
