import assert from "node:assert/strict";
import test from "node:test";
import { mergePlatformConfigSources } from "../src/config/merge.ts";

test("后台品牌设置存在时不再处理旧 platform-config 站点与品牌字段", () => {
  const merged = mergePlatformConfigSources(
    {
      BaseUrlApi: "/api",
      StorageNameSpace: "user-",
      Title: "旧标题",
      AllBrands: [{ label: "旧品牌", value: "legacy" }],
      Brands: [{ label: "旧品牌", value: "legacy" }],
      Beian: "旧备案",
      CopyStart: 2010,
      Logo: "/old-logo.svg",
      LogoExpanded: "/old-expanded-logo.svg",
      Qrcode: "/old-qrcode.png"
    },
    {
      Title: "后台标题",
      AllBrands: [
        { label: "Certum", value: "certum" },
        { label: "DigiCert", value: "digicert" }
      ],
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
    AllBrands: [
      { label: "Certum", value: "certum" },
      { label: "DigiCert", value: "digicert" }
    ],
    Brands: [{ label: "Certum", value: "certum" }],
    DnsTools: ["https://dns.example.test"],
    Beian: "后台备案",
    CopyStart: 2020,
    Logo: "/logo.svg",
    LogoExpanded: "",
    Qrcode: "/qrcode.png"
  });
});

test("后台平台配置缺失时保留静态部署配置", () => {
  const staticConfig = {
    BaseUrlApi: "/api",
    StorageNameSpace: "user-"
  };

  assert.deepEqual(mergePlatformConfigSources(staticConfig), staticConfig);
});

test("AllBrands 存在时由后台完整接管品牌和站点字段", () => {
  const merged = mergePlatformConfigSources(
    {
      Title: "旧标题",
      AllBrands: [{ label: "旧品牌", value: "legacy" }],
      Brands: [{ label: "旧品牌", value: "legacy" }]
    },
    { AllBrands: [], Brands: [] }
  );

  assert.deepEqual(merged.AllBrands, []);
  assert.deepEqual(merged.Brands, []);
  assert.equal(merged.Title, undefined);
});
