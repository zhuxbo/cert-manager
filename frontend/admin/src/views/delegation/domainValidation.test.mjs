import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import isDomain from "validator/lib/isFQDN.js";
import { createDomainValidator } from "../../../../shared/src/utils/domain.ts";

const adminStore = new URL("./store.tsx", import.meta.url);
const userStore = new URL(
  "../../../../user/src/views/delegation/store.tsx",
  import.meta.url
);

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
