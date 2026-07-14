import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { isTextTruncated } from "./schemaLabel.ts";

test("isTextTruncated 仅在内容宽度超过可见宽度时返回 true", () => {
  assert.equal(isTextTruncated({ scrollWidth: 121, clientWidth: 120 }), true);
  assert.equal(isTextTruncated({ scrollWidth: 120, clientWidth: 120 }), false);
  assert.equal(isTextTruncated({ scrollWidth: 80, clientWidth: 120 }), false);
});

test("未声明插件 CSS 时标签布局样式保持内联", async () => {
  const manifest = JSON.parse(
    await readFile(new URL("../../plugin.json", import.meta.url), "utf8")
  );
  const component = await readFile(
    new URL("./SchemaFieldLabel.vue", import.meta.url),
    "utf8"
  );

  assert.equal(manifest.user_css, undefined);
  assert.equal(manifest.admin_css, undefined);
  assert.doesNotMatch(component, /<style(?:\s|>)/);
  assert.match(component, /style="[\s\S]*?display:\s*inline-flex;/);
  assert.match(component, /style="[\s\S]*?text-overflow:\s*ellipsis;/);
});
