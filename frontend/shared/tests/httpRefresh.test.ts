import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import { test } from "node:test";
import { runInNewContext } from "node:vm";

const require = createRequire(
  new URL("../../admin/package.json", import.meta.url)
);
const ts = require("typescript");
const Axios = require("axios");
const source = readFileSync(
  new URL("../src/utils/http/index.ts", import.meta.url),
  "utf8"
).replace("import.meta.env.DEV", "false");

function setup(expired = false) {
  let token = {
    access_token: "old",
    refresh_token: "refresh",
    expires_in: Date.now() + (expired ? -1000 : 60000)
  };
  let logoutCount = 0;
  let refreshCount = 0;
  let refresh: () => Promise<any> = async () => {
    throw new Error("刷新失败");
  };
  const exports: any = {};
  const mocks: Record<string, any> = {
    axios: Axios,
    qs: require("qs"),
    "../progress": { start() {}, done() {} },
    "../auth": {
      getToken: () => token,
      formatToken: value => `Bearer ${value}`
    },
    "../../config": { getConfig: () => ({ BaseUrlApi: "/api" }) },
    "../message": { message() {} },
    "../messageBox": { messageBox() {} }
  };
  runInNewContext(
    ts.transpileModule(source, {
      compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
        esModuleInterop: true
      }
    }).outputText,
    { exports, require: name => mocks[name], FormData, Date, Intl, console }
  );
  const http = exports.createHttp({
    refreshToken: () => {
      refreshCount++;
      return refresh();
    },
    logout: () => logoutCount++
  });
  return {
    http,
    setRefresh: fn => (refresh = fn),
    renew: () => {
      token = { ...token, access_token: "new", expires_in: Date.now() + 60000 };
      return { data: { access_token: "new" } };
    },
    counts: () => ({ logoutCount, refreshCount })
  };
}

const success = async config => ({
  data: { code: 1, authorization: config.headers.Authorization },
  status: 200,
  statusText: "OK",
  headers: {},
  config
});
const unauthorized = async config => {
  throw new Axios.AxiosError(
    "unauthorized",
    "ERR_BAD_REQUEST",
    config,
    {},
    {
      status: 401,
      data: {},
      headers: {},
      statusText: "Unauthorized",
      config
    }
  );
};
async function settled(requests) {
  let timer;
  try {
    return await Promise.race([
      Promise.allSettled(requests),
      new Promise<never>((_, reject) => {
        timer = setTimeout(() => reject(new Error("请求仍在等待刷新")), 500);
      })
    ]);
  } finally {
    clearTimeout(timer);
  }
}

for (const expired of [true, false]) {
  test(`${expired ? "过期" : "401"}并发请求刷新失败时全部结束并退出登录`, async () => {
    const ctx = setup(expired);
    const result = await settled(
      Array.from({ length: 3 }, () =>
        ctx.http.get("/upgrade/status", {
          adapter: expired ? success : unauthorized
        })
      )
    );
    assert.ok(result.every(item => item.status === "rejected"));
    assert.deepEqual(ctx.counts(), { logoutCount: 1, refreshCount: 1 });
  });

  test(`${expired ? "过期" : "401"}并发请求共享刷新并携带新凭证继续`, async () => {
    const ctx = setup(expired);
    ctx.setRefresh(async () => ctx.renew());
    const result = await settled(
      Array.from({ length: 3 }, () =>
        ctx.http.get("/upgrade/status", {
          adapter: config =>
            config.headers.Authorization === "Bearer new"
              ? success(config)
              : unauthorized(config)
        })
      )
    );
    assert.ok(
      result.every(
        item =>
          item.status === "fulfilled" &&
          item.value.authorization === "Bearer new"
      )
    );
    assert.deepEqual(ctx.counts(), { logoutCount: 0, refreshCount: 1 });
  });
}

test("刷新接口自身401不递归刷新，原请求也会结束", async () => {
  const ctx = setup();
  ctx.setRefresh(() =>
    ctx.http.post("/refresh-token", { adapter: unauthorized })
  );
  const result = await settled([
    ctx.http.get("/upgrade/status", { adapter: unauthorized })
  ]);
  assert.equal(result[0].status, "rejected");
  assert.deepEqual(ctx.counts(), { logoutCount: 1, refreshCount: 1 });
});
