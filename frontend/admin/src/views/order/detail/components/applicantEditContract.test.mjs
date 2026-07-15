import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const componentRoot = new URL("./", import.meta.url);
const orderComponents = [
  "ssl/order.vue",
  "smime/order.vue",
  "codesign/order.vue",
  "docsign/order.vue"
];

test("四类管理端订单详情仅为 unpaid 和 pending 的已有申请信息提供修改入口", async () => {
  for (const component of orderComponents) {
    const source = await readFile(new URL(component, componentRoot), "utf8");

    assert.match(
      source,
      /import ApplicantEditor from "\.\.\/applicantEditor\.vue"/
    );
    assert.match(source, /const canEditApplicant = computed\(/);
    assert.match(
      source,
      /\["unpaid", "pending"\]\.includes\(order\.latest_cert\?\.status\)/
    );
    assert.match(
      source,
      /<tr v-if="order\.organization">[\s\S]*?v-if="canEditApplicant"[\s\S]*?editApplicant\(["']organization["']\)/
    );
    assert.match(
      source,
      /<tr v-if="order\.contact">[\s\S]*?v-if="canEditApplicant"[\s\S]*?editApplicant\(["']contact["']\)/
    );
    assert.match(source, /<ApplicantEditor/);
  }
});

test("管理端订单 API 通过专用 PATCH 接口更新申请信息快照", async () => {
  const source = await readFile(
    new URL("../../../../api/order.ts", import.meta.url),
    "utf8"
  );

  assert.match(source, /export function updateApplicant\(/);
  assert.match(source, /`\/order\/applicant\/\$\{id\}`/);
  assert.match(source, /http\.patch/);
});

test("申请信息弹窗沿用下单快照的关键字段约束", async () => {
  const source = await readFile(
    new URL("applicantEditor.vue", componentRoot),
    "utf8"
  );

  assert.match(source, /"organization\.country": \[[\s\S]*?len: 2[\s\S]*?\]/);
  assert.match(
    source,
    /"organization\.postcode": \[[\s\S]*?required: true[\s\S]*?min: 4[\s\S]*?max: 16/
  );
  assert.equal(source.split("pattern: /^\\d{5,15}$/").length - 1, 2);
});
