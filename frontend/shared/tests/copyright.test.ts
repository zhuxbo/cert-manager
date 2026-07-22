import assert from "node:assert/strict";
import test from "node:test";
import { resolveCopyrightStart } from "../src/utils/copyright.ts";

test("版权起始年份缺失或无效时回落 2017", () => {
  assert.equal(resolveCopyrightStart(undefined, 2026), 2017);
  assert.equal(resolveCopyrightStart("", 2026), 2017);
  assert.equal(resolveCopyrightStart("future", 2026), 2017);
  assert.equal(resolveCopyrightStart(2027, 2026), 2017);
});

test("版权起始年份接受系统设置中的数字或数字字符串", () => {
  assert.equal(resolveCopyrightStart(2020, 2026), 2020);
  assert.equal(resolveCopyrightStart(" 2019 ", 2026), 2019);
});
