import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { fileURLToPath } from "node:url";

const readSource = relativePath =>
  readFileSync(fileURLToPath(new URL(relativePath, import.meta.url)), "utf8");

test("产品批量处理提供开启和关闭委托操作", () => {
  const page = readSource("./index.vue");
  const hook = readSource("./hook.tsx");
  const api = readSource("../../api/product.ts");

  assert.match(page, />\s*开启委托\s*</);
  assert.match(page, />\s*关闭委托\s*</);
  assert.match(page, /handleBatchDelegation\(selectedIds, true\)/);
  assert.match(page, /handleBatchDelegation\(selectedIds, false\)/);
  assert.match(hook, /productApi[\s\S]*?\.batchSetDelegation\(ids, enabled\)/);
  assert.match(api, /"\/product\/batch-delegation"/);
});
