<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('api-doc 返回三套 surface 的 Markdown 原文', function (string $surface, string $needle) {
    $res = $this->get("/api/meta/api-doc?surface=$surface");

    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('text/markdown');
    expect($res->getContent())->toContain($needle);
})->with([
    'v2' => ['v2', '/api/v2'],
    'acme' => ['acme', 'eab_kid'],
    'deploy' => ['deploy', '/api/deploy'],
]);

test('api-doc 非法 surface 返回 404', function () {
    $this->get('/api/meta/api-doc?surface=bogus')->assertNotFound();
    $this->get('/api/meta/api-doc')->assertNotFound();
});
