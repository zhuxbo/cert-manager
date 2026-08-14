import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

test("订单详情凭证选择使用可清空下拉框和状态化内联按钮", async () => {
  const component = await readFile(
    new URL("./TargetForm.vue", import.meta.url),
    "utf8"
  );
  const start = component.indexOf('<el-form-item label="云凭证">');
  const end = component.indexOf(
    '<el-form-item v-if="!hideOrder" label="订单">',
    start
  );
  const accessField = component.slice(start, end);

  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  assert.match(accessField, /<el-select[\s\S]*?\bclearable\b/);
  assert.doesNotMatch(accessField, /#footer/);
  assert.match(accessField, /<\/el-select>\s*<template v-if="hideOrder">/);
  assert.match(accessField, /<el-button[\s\S]*?v-if="!selectedAccess"[\s\S]*?>\s*新增\s*</);
  assert.match(accessField, /<el-button[\s\S]*?v-else[\s\S]*?>\s*编辑\s*</);
});
