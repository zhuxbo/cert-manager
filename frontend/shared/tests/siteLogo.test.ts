import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { fileURLToPath } from "node:url";
import path from "node:path";
import {
  defaultLoginImagePath,
  defaultLogoPath,
  defaultQrcodePath,
  resolveSiteLoginImage,
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
  assert.equal(
    resolveSiteQrcode("/qrcode.svg", "/user/qrcode.png"),
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

test("登录配图未配置时回落默认 login.svg，已配置时保持上传地址", () => {
  assert.equal(defaultLoginImagePath("/user/"), "/user/login.svg");
  assert.equal(defaultLoginImagePath("/user"), "/user/login.svg");
  assert.equal(defaultLoginImagePath(""), "/login.svg");
  assert.equal(resolveSiteLoginImage("", "/user/login.svg"), "/user/login.svg");
  assert.equal(
    resolveSiteLoginImage(null, "/user/login.svg"),
    "/user/login.svg"
  );
  assert.equal(
    resolveSiteLoginImage(
      "/api/meta/site-image/login-image-custom.png",
      "/user/login.svg"
    ),
    "/api/meta/site-image/login-image-custom.png"
  );
});

test("用户首页直接使用单一 PNG 回落，不再注册 SVG 加载失败切换", () => {
  const here = path.dirname(fileURLToPath(import.meta.url));
  const dashboard = readFileSync(
    path.resolve(here, "../../user/src/views/welcome/index.vue"),
    "utf8"
  );

  assert.match(dashboard, /defaultQrcodePath\(import\.meta\.env\.BASE_URL\)/);
  assert.doesNotMatch(dashboard, /handleQrcodeLoadError|qrcode\.svg/);
});

test("趋势周期请求失败时清空旧周期数据", () => {
  const here = path.dirname(fileURLToPath(import.meta.url));
  const dashboard = readFileSync(
    path.resolve(here, "../../user/src/views/welcome/index.vue"),
    "utf8"
  );
  const catchBlock = dashboard.match(
    /catch \(error\) \{\s*if \(requestId !== latestTrendRequestId\) return;\s*trendData\.value = \[\];/
  );

  assert.ok(catchBlock, "最新趋势请求失败后应清空旧周期数据");
});
