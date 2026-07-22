import assert from "node:assert/strict";
import test from "node:test";
import { mergePlatformConfigSources } from "../src/config/merge.ts";

test("后台品牌设置存在时不再处理旧 platform-config 站点与品牌字段", () => {
  const merged = mergePlatformConfigSources(
    {
      BaseUrlApi: "/api",
      StorageNameSpace: "user-",
      Title: "旧标题",
      Brands: [{ label: "旧品牌", value: "legacy" }],
      Beian: "旧备案",
      CopyStart: 2010,
      Logo: "/old-logo.svg",
      LogoExpanded: "/old-expanded-logo.svg",
      Qrcode: "/old-qrcode.png"
    },
    {
      Title: "后台标题",
      Brands: [{ label: "Certum", value: "certum" }],
      DnsTools: ["https://dns.example.test"],
      Beian: "后台备案",
      CopyStart: 2020,
      Logo: "/logo.svg",
      LogoExpanded: "",
      Qrcode: "/qrcode.png"
    }
  );

  assert.deepEqual(merged, {
    BaseUrlApi: "/api",
    StorageNameSpace: "user-",
    Title: "后台标题",
    Brands: [{ label: "Certum", value: "certum" }],
    DnsTools: ["https://dns.example.test"],
    Beian: "后台备案",
    CopyStart: 2020,
    Logo: "/logo.svg",
    LogoExpanded: "",
    Qrcode: "/qrcode.png"
  });
});

test("旧后台未提供品牌设置时保留完整 platform-config 兼容行为", () => {
  const staticConfig = {
    BaseUrlApi: "/api",
    Title: "旧标题",
    Brands: [{ label: "旧品牌", value: "legacy" }]
  };

  assert.deepEqual(
    mergePlatformConfigSources(staticConfig, { Title: "不完整后台配置" }),
    staticConfig
  );
});

test("后台品牌设置为空数组时仍视为已存在", () => {
  const merged = mergePlatformConfigSources(
    { Brands: [{ label: "旧品牌", value: "legacy" }] },
    { Brands: [] }
  );

  assert.deepEqual(merged.Brands, []);
});
