import assert from "node:assert/strict";
import { registerHooks } from "node:module";
import test from "node:test";

registerHooks({
  resolve(specifier, context, nextResolve) {
    return nextResolve(
      specifier === "./schemaConfig" ? "./schemaConfig.ts" : specifier,
      context
    );
  }
});

const {
  configForVisibleSchema,
  isSchemaFieldRequired,
  isSchemaFieldVisible
} = await import("./schemaConditions.ts");

const fields = [
  { key: "service_type", type: "select", default: "cloudnative" },
  {
    key: "gateway_id",
    type: "string",
    required_when: { key: "service_type", equals: "cloudnative" },
    visible_when: { key: "service_type", equals: "cloudnative" }
  },
  {
    key: "group_id",
    type: "string",
    required_when: { key: "service_type", equals: "traditional" },
    visible_when: { key: "service_type", equals: "traditional" }
  }
];

test("条件 schema 以默认值判定可见与必填", () => {
  assert.equal(isSchemaFieldVisible(fields[1], {}, fields), true);
  assert.equal(isSchemaFieldRequired(fields[1], {}, fields), true);
  assert.equal(isSchemaFieldVisible(fields[2], {}, fields), false);
  assert.equal(isSchemaFieldRequired(fields[2], {}, fields), false);
});

test("条件 schema 切换分支后丢弃隐藏旧值，只提交当前分支字段", () => {
  const traditional = { service_type: "traditional", gateway_id: "old", group_id: "group-1" };

  assert.equal(isSchemaFieldVisible(fields[1], traditional, fields), false);
  assert.equal(isSchemaFieldRequired(fields[2], traditional, fields), true);
  assert.deepEqual(configForVisibleSchema(fields, traditional), {
    service_type: "traditional",
    group_id: "group-1"
  });
});

test("admin/user 共用提交载荷：隐藏字段不参与必填也不会保留在 payload", () => {
  const imdsFields = [
    { key: "auth_method", type: "select", default: "accesskey" },
    {
      key: "access_key_id",
      type: "string",
      required_when: { key: "auth_method", equals: "accesskey" },
      visible_when: { key: "auth_method", equals: "accesskey" }
    },
    {
      key: "secret_access_key",
      type: "string",
      required_when: { key: "auth_method", equals: "accesskey" },
      visible_when: { key: "auth_method", equals: "accesskey" }
    }
  ];
  const values = {
    auth_method: "imds",
    access_key_id: "old-access-key",
    secret_access_key: "old-secret"
  };

  assert.equal(isSchemaFieldVisible(imdsFields[1], values, imdsFields), false);
  assert.equal(isSchemaFieldRequired(imdsFields[1], values, imdsFields), false);
  assert.deepEqual(configForVisibleSchema(imdsFields, values), {
    auth_method: "imds"
  });
});

test("Oracle principal 分支会裁剪 API Key 字段且不触发隐藏字段必填", () => {
  const fields = [
    { key: "auth_method", type: "select", default: "apikey" },
    {
      key: "tenancy_ocid",
      required_when: { key: "auth_method", equals: "apikey" },
      visible_when: { key: "auth_method", equals: "apikey" }
    },
    {
      key: "private_key",
      required_when: { key: "auth_method", equals: "apikey" },
      visible_when: { key: "auth_method", equals: "apikey" }
    }
  ];
  const values = {
    auth_method: "resourceprincipal",
    tenancy_ocid: "STALE-TENANCY",
    private_key: "STALE-PRIVATE-KEY"
  };

  assert.equal(isSchemaFieldVisible(fields[1], values, fields), false);
  assert.equal(isSchemaFieldRequired(fields[2], values, fields), false);
  assert.deepEqual(configForVisibleSchema(fields, values), {
    auth_method: "resourceprincipal"
  });
});
