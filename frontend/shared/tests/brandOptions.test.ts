import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { normalizeBrandOptions } from "../src/utils/brandOptions.ts";

interface BrandNormalizeCase {
  name: string;
  input: unknown;
  expected: Array<{ label: string; value: string }>;
}

// 与后端 PlatformConfigService::brandOptions 共享同一夹具锁定对称副本等价
// （PHP 侧：backend/tests/Unit/Services/PlatformConfigServiceTest.php）
const sharedCases: BrandNormalizeCase[] = JSON.parse(
  readFileSync(
    new URL(
      "../../../backend/tests/Fixtures/brand-normalize-cases.json",
      import.meta.url
    ),
    "utf8"
  )
);

test("与后端共享夹具输出等价", () => {
  assert.ok(sharedCases.length > 0);
  for (const item of sharedCases) {
    assert.deepEqual(
      normalizeBrandOptions(item.input),
      item.expected,
      `夹具用例失败: ${item.name}`
    );
  }
});

test("品牌配置使用后台提供的 label 和 value", () => {
  assert.deepEqual(
    normalizeBrandOptions([
      { label: " 自定义品牌 ", value: " Custom " },
      { label: "重复", value: "custom" },
      { label: "", value: "invalid" }
    ]),
    [{ label: "自定义品牌", value: "custom" }]
  );
});

test("品牌键值对象转换为前端选项", () => {
  assert.deepEqual(
    normalizeBrandOptions({ ssltrus: "锐安信", DIGICERT: "DigiCert" }),
    [
      { label: "锐安信", value: "ssltrus" },
      { label: "DigiCert", value: "digicert" }
    ]
  );
});

test("兼容旧字符串品牌数组", () => {
  assert.deepEqual(normalizeBrandOptions([" Certum ", "DIGICERT"]), [
    { label: "Certum", value: "certum" },
    { label: "DIGICERT", value: "digicert" }
  ]);
});
