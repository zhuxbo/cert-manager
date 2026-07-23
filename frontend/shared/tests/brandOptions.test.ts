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

test("后台活动品牌选项保持渠道配置顺序", () => {
  assert.deepEqual(
    normalizeBrandOptions([
      { label: "锐安信", value: "ssltrus" },
      { label: "Certum", value: "CERTUM" },
      { label: "重复", value: "ssltrus" }
    ]),
    [
      { label: "锐安信", value: "ssltrus" },
      { label: "Certum", value: "certum" }
    ]
  );
});

test("产品维护使用全部品牌而筛选继续使用活动品牌", () => {
  const adminDictionary = readFileSync(
    new URL("../../admin/src/views/system/dictionary.ts", import.meta.url),
    "utf8"
  );
  const userDictionary = readFileSync(
    new URL("../../user/src/views/system/dictionary.ts", import.meta.url),
    "utf8"
  );
  const productStore = readFileSync(
    new URL("../../admin/src/views/product/store.tsx", import.meta.url),
    "utf8"
  );
  const productImport = readFileSync(
    new URL("../../admin/src/views/product/import.vue", import.meta.url),
    "utf8"
  );
  const productSearches = [
    readFileSync(
      new URL("../../admin/src/views/product/search.tsx", import.meta.url),
      "utf8"
    ),
    readFileSync(
      new URL("../../user/src/views/product/search.tsx", import.meta.url),
      "utf8"
    )
  ];
  const productExports = [
    readFileSync(
      new URL("../../admin/src/views/product/export.vue", import.meta.url),
      "utf8"
    ),
    readFileSync(
      new URL("../../user/src/views/product/export.vue", import.meta.url),
      "utf8"
    )
  ];
  const acmeDetails = [
    readFileSync(
      new URL("../../admin/src/views/acme/detail-card.vue", import.meta.url),
      "utf8"
    ),
    readFileSync(
      new URL("../../user/src/views/acme/detail-card.vue", import.meta.url),
      "utf8"
    )
  ];

  for (const dictionary of [adminDictionary, userDictionary]) {
    assert.match(
      dictionary,
      /brandOptionsAll\s*=\s*normalizeBrandOptions\(getConfig\("AllBrands"\)\)/
    );
    assert.match(dictionary, /brandOptions\s*=\s*normalizeBrandOptions/);
    assert.match(dictionary, /brandOptionsAll\.map\(brand/);
  }
  assert.match(productStore, /options:\s*brandOptionsAll/);
  assert.match(productImport, /v-for="item in brandOptionsAll"/);
  for (const search of productSearches) {
    assert.match(search, /options:\s*brandOptions/);
    assert.match(search, /createButtonGroupRenderer\(brandOptions,/);
    assert.doesNotMatch(search, /brandOptionsAll/);
  }
  for (const productExport of productExports) {
    assert.match(productExport, /v-for="option in brandOptions"/);
    assert.doesNotMatch(productExport, /brandOptionsAll/);
  }
  for (const detail of acmeDetails) {
    assert.match(detail, /brandLabels\[acme\.brand\?\.toLowerCase\(\)\]/);
  }
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
