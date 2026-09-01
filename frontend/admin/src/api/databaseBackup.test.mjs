import assert from "node:assert/strict";
import test from "node:test";
import { fileURLToPath } from "node:url";

import { createServer } from "vite";

const adminRoot = fileURLToPath(new URL("../..", import.meta.url));

test("恢复预检使用长超时并由弹窗接管错误提示", async t => {
  const calls = [];
  const httpModuleId = "\0restore-preflight-http";
  const server = await createServer({
    root: adminRoot,
    configFile: false,
    appType: "custom",
    server: { middlewareMode: true, hmr: false, ws: false },
    optimizeDeps: { noDiscovery: true },
    plugins: [
      {
        name: "restore-preflight-http-double",
        enforce: "pre",
        resolveId(id) {
          return id === "@/utils/http" ? httpModuleId : null;
        },
        load(id) {
          if (id !== httpModuleId) return null;
          return `export const http = { request(...args) { globalThis.__restorePreflightCalls.push(args); return Promise.resolve({}); } };`;
        }
      }
    ]
  });
  globalThis.__restorePreflightCalls = calls;
  t.after(async () => {
    delete globalThis.__restorePreflightCalls;
    await server.close();
  });

  const api = await server.ssrLoadModule("/src/api/databaseBackup.ts");
  await api.getRestorePreflight("backup_20260831_154413");

  assert.deepEqual(calls, [
    [
      "get",
      "/database/backups/backup_20260831_154413/restore-preflight",
      undefined,
      { timeout: 300000, suppressErrorMessage: true }
    ]
  ]);
});
