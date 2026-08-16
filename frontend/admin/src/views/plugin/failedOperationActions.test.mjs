import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

test("失败更新在重试旁提供取消入口并明确保留当前版本", async () => {
  const component = await readFile(new URL("./index.vue", import.meta.url), "utf8");
  const api = await readFile(new URL("../../api/plugin.ts", import.meta.url), "utf8");

  assert.match(api, /export function cancelFailedPluginUpdate/);
  assert.match(api, /operations\/\$\{uuid\}\/cancel/);
  assert.match(component, /const canCancelUpdateOperation/);
  assert.match(component, /handleCancelUpdateOperation/);
  assert.match(component, /确定取消本次更新并保留当前已安装版本吗/);
  assert.match(component, />\s*取消\s*</);
});
