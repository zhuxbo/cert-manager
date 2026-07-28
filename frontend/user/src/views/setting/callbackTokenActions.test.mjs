import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { fileURLToPath } from "node:url";

const callbackSource = readFileSync(
  fileURLToPath(new URL("./callback.tsx", import.meta.url)),
  "utf8"
);

test("回调 Token 提供生成和复制按钮", () => {
  const tokenColumn = callbackSource.match(
    /label: "回调 Token"[\s\S]*?\n    \},\n    \{\n      label: "状态"/
  )?.[0];

  assert.ok(tokenColumn);
  assert.match(tokenColumn, /callbackValues\.value\.token = uuid\(32\)/);
  assert.match(tokenColumn, /navigator\.clipboard/);
  assert.match(tokenColumn, /writeText\(callbackValues\.value\.token\)/);
  assert.match(tokenColumn, /icon: "ep\/circle-plus"/);
  assert.match(tokenColumn, /icon: "ep\/copy-document"/);
  assert.match(tokenColumn, /message\("请先生成Token"/);
});
