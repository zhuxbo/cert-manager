import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import isDomain from "validator/lib/isFQDN.js";
import { createDomainValidator } from "../../../../shared/src/utils/domain.ts";
import { formatDelegationCopyText } from "../../../../shared/src/utils/delegation.ts";

const adminStore = new URL("./store.tsx", import.meta.url);
const userStore = new URL(
  "../../../../user/src/views/delegation/store.tsx",
  import.meta.url
);
const delegationApps = [
  {
    api: new URL("../../api/delegation.ts", import.meta.url),
    table: new URL("./table.tsx", import.meta.url),
    hook: new URL("./hook.tsx", import.meta.url)
  },
  {
    api: new URL("../../../../user/src/api/delegation.ts", import.meta.url),
    table: new URL(
      "../../../../user/src/views/delegation/table.tsx",
      import.meta.url
    ),
    hook: new URL(
      "../../../../user/src/views/delegation/hook.tsx",
      import.meta.url
    )
  }
];
const validateZone = createDomainValidator(isDomain);

const validate = value => {
  let validationError;

  validateZone({}, value, error => {
    validationError = error;
  });

  return validationError;
};

test("生产委托域校验接受中文、ASCII 和 Punycode 域名并拒绝非法输入", () => {
  for (const domain of [
    "啊沙发沙发的.com",
    "例子.中国",
    "example.com",
    "xn--fiq228c.com"
  ]) {
    assert.equal(validate(domain), undefined, domain);
  }

  for (const domain of [
    "a..com",
    "-bad.com",
    "bad-.com",
    "bad_domain.com",
    "localhost"
  ]) {
    assert.match(
      validate(domain)?.message ?? "",
      /请输入正确的域名格式/,
      domain
    );
  }
});

test("管理端和用户端手工添加委托都接入统一 IDN 校验", async () => {
  for (const file of [adminStore, userStore]) {
    const source = await readFile(file, "utf8");

    assert.match(source, /import isDomain from "validator\/lib\/isFQDN"/);
    assert.match(
      source,
      /import \{ createDomainValidator \} from "@shared\/utils\/domain"/
    );
    assert.match(
      source,
      /const validateZone = createDomainValidator\(isDomain\)/
    );
    assert.doesNotMatch(source, /\^\(\[a-z0-9\]/i);
  }
});

test("管理端和用户端委托页面只消费 proxy_domain", async () => {
  for (const app of delegationApps) {
    const source = (
      await Promise.all(Object.values(app).map(file => readFile(file, "utf8")))
    ).join("\n");

    assert.match(source, /proxy_domain/);
    assert.doesNotMatch(source, /proxy_zone/);
  }
});

test("管理端和用户端按 nullable 契约稳定展示代理域", async () => {
  for (const app of delegationApps) {
    const [apiSource, tableSource, hookSource] = await Promise.all([
      readFile(app.api, "utf8"),
      readFile(app.table, "utf8"),
      readFile(app.hook, "utf8")
    ]);

    assert.match(apiSource, /proxy_domain:\s*string \| null;/);
    assert.match(tableSource, /row\.proxy_domain \|\| "-"/);
    assert.doesNotMatch(hookSource, /function formatDelegationCopyText/);
    assert.match(
      `${tableSource}\n${hookSource}`,
      /formatDelegationCopyText[^]*from "@shared\/utils(?:\/delegation)?"/
    );
  }
});

test("管理端和用户端共用委托复制文本格式化实现", () => {
  assert.equal(
    formatDelegationCopyText({
      zone: "example.com",
      prefix: "fallback",
      cname_to: {
        host: "_dnsauth.example.com",
        value: "target.example.net"
      },
      target_fqdn: null,
      proxy_domain: null
    }),
    "域名: example.com\n主机记录: _dnsauth\n记录类型: CNAME\n记录值: target.example.net\n代理域: -"
  );
});
