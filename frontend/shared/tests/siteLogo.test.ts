import assert from "node:assert/strict";
import test from "node:test";
import {
  defaultLogoPath,
  defaultQrcodePath,
  resolveSiteLogo,
  resolveSiteQrcode
} from "../src/utils/siteLogo.ts";

test("未配置或默认哨兵值时回落调用方提供的默认资源", () => {
  assert.equal(
    resolveSiteLogo("/logo.svg", "/user/logo.svg"),
    "/user/logo.svg"
  );
  assert.equal(
    resolveSiteLogo("", "/assets/logo-abc.svg"),
    "/assets/logo-abc.svg"
  );
  assert.equal(
    resolveSiteLogo(null, "/assets/logo-abc.svg"),
    "/assets/logo-abc.svg"
  );
});

test("后台上传的 Logo 地址保持不变", () => {
  const uploaded = `/api/meta/site-image/logo-${"ab12".repeat(16)}.webp`;

  assert.equal(resolveSiteLogo(uploaded, "/assets/logo-abc.svg"), uploaded);
});

test("defaultLogoPath 按公开资源目录拼接", () => {
  assert.equal(defaultLogoPath("/user/"), "/user/logo.svg");
  assert.equal(defaultLogoPath("/user"), "/user/logo.svg");
  assert.equal(defaultLogoPath(""), "/logo.svg");
});

test("二维码默认哨兵值按用户端公开资源目录回落", () => {
  assert.equal(defaultQrcodePath("/user/"), "/user/qrcode.png");
  assert.equal(defaultQrcodePath("/user"), "/user/qrcode.png");
  assert.equal(defaultQrcodePath(""), "/qrcode.png");
  assert.equal(
    resolveSiteQrcode("/qrcode.png", "/user/qrcode.png"),
    "/user/qrcode.png"
  );
  assert.equal(resolveSiteQrcode("", "/user/qrcode.png"), "/user/qrcode.png");
  assert.equal(
    resolveSiteQrcode(
      "/api/meta/site-image/qrcode-custom.png",
      "/user/qrcode.png"
    ),
    "/api/meta/site-image/qrcode-custom.png"
  );
});
