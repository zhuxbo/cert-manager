import assert from "node:assert/strict";
import test from "node:test";

import {
  configForSchema,
  isBooleanConfigField
} from "./schemaConfig.ts";

test("bool 与 boolean schema 字段均识别为布尔开关", () => {
  assert.equal(isBooleanConfigField({ key: "a", type: "bool" }), true);
  assert.equal(isBooleanConfigField({ key: "b", type: "boolean" }), true);
  assert.equal(isBooleanConfigField({ key: "c", type: "string" }), false);
});

test("配置初始化应用 schema 默认值并保留已有 false/0", () => {
  assert.deepEqual(
    configForSchema(
      [
        { key: "auto_restart", type: "boolean", default: true },
        { key: "enable_multiple_ssl", type: "bool", default: false },
        { key: "retries", type: "number", default: 3 },
        { key: "domain", type: "string" },
        { key: "legacy_false", type: "bool" },
        { key: "legacy_true", type: "boolean" }
      ],
      {
        auto_restart: false,
        retries: 0,
        legacy_false: "false",
        legacy_true: "true"
      }
    ),
    {
      auto_restart: false,
      enable_multiple_ssl: false,
      retries: 0,
      domain: "",
      legacy_false: false,
      legacy_true: true
    }
  );
});
