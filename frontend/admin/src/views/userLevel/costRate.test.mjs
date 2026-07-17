import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { formatCostRate, toCostRateNumber } from "./costRate.ts";

test("会员级别倍率兼容 decimal API 字符串和表单数字", () => {
  assert.equal(formatCostRate("1.2500"), "1.2500");
  assert.equal(formatCostRate(2), "2.0000");
  assert.equal(formatCostRate("invalid"), "-");

  assert.equal(toCostRateNumber("1.2500"), 1.25);
  assert.equal(toCostRateNumber(2), 2);
  assert.equal(toCostRateNumber("invalid"), 0);
});

test("用户级别 DTO 和两个价格消费面显式遵守字符串倍率契约", async () => {
  const api = await readFile(
    new URL("../../api/userLevel.ts", import.meta.url),
    "utf8"
  );
  const table = await readFile(new URL("./table.tsx", import.meta.url), "utf8");
  const productPrice = await readFile(
    new URL("../productPrice/levels.vue", import.meta.url),
    "utf8"
  );

  assert.match(api, /export interface UserLevelDto[\s\S]*cost_rate: string;/);
  assert.match(
    table,
    /formatter: \(\{ cost_rate \}\) => formatCostRate\(cost_rate\)/
  );
  assert.match(productPrice, /item: UserLevelDto/);
  assert.match(
    productPrice,
    /userLevelCostRates\.value\[item\.code\]\s*=\s*toCostRateNumber\(\s*item\.cost_rate\s*\)/
  );
});
