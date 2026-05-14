import {
  type UserConfigExport,
  type ConfigEnv,
  loadEnv,
  type Plugin
} from "vite";
import { resolve, relative, isAbsolute } from "path";
import { createReadStream, existsSync, readdirSync, statSync } from "fs";
import {
  root,
  alias,
  wrapperEnv,
  pathResolve,
  __APP_INFO__,
  getPluginsList,
  include,
  exclude
} from "./build";

/**
 * 开发环境：非 base 路径自动 302 到 base 前缀
 * 排除插件 web、API、Vite 内部等已处理的路径
 * 必须在 servePluginWeb / servePlugins 之后注册，确保插件路径先处理
 */
function autoRedirectBase(base: string, skipPrefixes: string[]): Plugin {
  return {
    name: "auto-redirect-base",
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        const url = req.url ?? "";
        const pathname = url.split("?")[0];
        const baseNoSlash = base.replace(/\/$/, "");
        if (
          pathname === "/" ||
          pathname === baseNoSlash ||
          pathname.startsWith(base) ||
          pathname.startsWith("/@") ||
          skipPrefixes.some(p => pathname.startsWith(p))
        ) {
          return next();
        }
        res.writeHead(302, { Location: base + url.replace(/^\//, "") });
        res.end();
      });
    }
  };
}

/** 开发环境：代理插件 web 前端（如 /easy/订单号） */
function servePluginWeb(): Plugin {
  const pluginsRoot = resolve(__dirname, "../../plugins");
  // 扫描有 frontend/web/index.html 的插件，自动注册 /name 路由
  const webPlugins = new Map<string, string>();
  try {
    const entries = readdirSync(pluginsRoot, { withFileTypes: true });
    for (const entry of entries) {
      if (!entry.isDirectory()) continue;
      const webDir = resolve(pluginsRoot, entry.name, "frontend/web");
      if (existsSync(resolve(webDir, "index.html"))) {
        webPlugins.set(`/${entry.name}`, webDir);
      }
    }
  } catch {}
  return {
    name: "serve-plugin-web",
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        const url = req.url?.split("?")[0].split("#")[0] ?? "";
        let matched: [string, string] | undefined;
        for (const [prefix, dir] of webPlugins) {
          if (url === prefix || url.startsWith(prefix + "/")) {
            matched = [prefix, dir];
            break;
          }
        }
        if (!matched) return next();
        const [prefix, webDir] = matched;
        let relPath =
          url.slice(prefix.length).replace(/^\//, "") || "index.html";
        // 与 plugins/easy/nginx/easy.conf 的 rewrite 对齐：
        // /easy/invoice 与 /easy/invoice/<tid>(/...) 都映射到 invoice.html
        if (prefix === "/easy" && /^invoice(\/|$)/.test(relPath)) {
          relPath = "invoice.html";
        }
        const filePath = resolve(webDir, relPath);
        const rel = relative(webDir, filePath);
        if (rel.startsWith("..") || isAbsolute(rel)) return next();
        if (existsSync(filePath) && statSync(filePath).isFile()) {
          const ext = filePath.split(".").pop() ?? "";
          const mimeTypes: Record<string, string> = {
            html: "text/html; charset=utf-8",
            js: "application/javascript",
            css: "text/css",
            json: "application/json",
            svg: "image/svg+xml",
            png: "image/png"
          };
          res.setHeader(
            "Content-Type",
            mimeTypes[ext] ?? "application/octet-stream"
          );
          createReadStream(filePath).pipe(res);
          return;
        }
        // SPA 回退：返回 index.html
        const indexPath = resolve(webDir, "index.html");
        if (existsSync(indexPath)) {
          res.setHeader("Content-Type", "text/html; charset=utf-8");
          createReadStream(indexPath).pipe(res);
          return;
        }
        next();
      });
    }
  };
}

/** 开发环境：将 /plugins/* 请求映射到项目根目录的 plugins/ 目录 */
function servePlugins(): Plugin {
  const pluginsRoot = resolve(__dirname, "../../plugins");
  return {
    name: "serve-plugins",
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        if (!req.url?.startsWith("/plugins/")) return next();
        const pathname = req.url.split("?")[0].split("#")[0];
        let decodedPath = "";
        try {
          decodedPath = decodeURIComponent(pathname.slice("/plugins/".length));
        } catch {
          return next();
        }
        // 开发环境：frontend/{admin,user}/file → frontend/{admin,user}/dist/file
        decodedPath = decodedPath.replace(
          /^([^/]+\/frontend\/(admin|user))\/([^/]+\.(js|css))$/,
          "$1/dist/$3"
        );
        const filePath = resolve(pluginsRoot, decodedPath);
        // 防止路径遍历（不要使用 startsWith 前缀判断）
        const relPath = relative(pluginsRoot, filePath);
        if (relPath.startsWith("..") || isAbsolute(relPath)) return next();
        if (!existsSync(filePath) || !statSync(filePath).isFile())
          return next();
        const ext = filePath.split(".").pop();
        const mime: Record<string, string> = {
          js: "application/javascript",
          css: "text/css"
        };
        res.setHeader(
          "Content-Type",
          mime[ext ?? ""] ?? "application/octet-stream"
        );
        createReadStream(filePath).pipe(res);
      });
    }
  };
}

export default ({ mode }: ConfigEnv): UserConfigExport => {
  const env = loadEnv(mode, root);
  const { VITE_CDN, VITE_PORT, VITE_COMPRESSION, VITE_PUBLIC_PATH } =
    wrapperEnv(env);
  const apiTarget = env.VITE_API_TARGET || "http://localhost:5300";
  return {
    base: VITE_PUBLIC_PATH,
    root,
    resolve: {
      alias
    },
    // 服务端渲染
    server: {
      // 端口号
      port: VITE_PORT,
      host: "0.0.0.0",
      // 本地跨域代理 https://cn.vitejs.dev/config/server-options.html#server-proxy
      proxy: {
        "/api": {
          target: `${apiTarget}/api`,
          changeOrigin: true,
          rewrite: path => path.replace(/^\/api/, "")
        }
      },
      // 预热文件以提前转换和缓存结果，降低启动期间的初始页面加载时长并防止转换瀑布
      warmup: {
        clientFiles: ["./index.html", "./src/{views,components}/*"]
      }
    },
    plugins: [
      servePluginWeb(),
      servePlugins(),
      autoRedirectBase(VITE_PUBLIC_PATH, ["/api/", "/plugins/"]),
      ...getPluginsList(VITE_CDN, VITE_COMPRESSION)
    ],
    // https://cn.vitejs.dev/config/dep-optimization-options.html#dep-optimization-options
    optimizeDeps: {
      include,
      exclude
    },
    build: {
      // https://cn.vitejs.dev/guide/build.html#browser-compatibility
      target: "es2015",
      sourcemap: false,
      // 消除打包大小超过500kb警告
      chunkSizeWarningLimit: 4000,
      rollupOptions: {
        // 限制并行文件操作数，降低内存峰值
        maxParallelFileOps: 2,
        input: {
          index: pathResolve("./index.html", import.meta.url)
        },
        // 静态资源分类打包
        output: {
          chunkFileNames: "static/js/[name]-[hash].js",
          entryFileNames: "static/js/[name]-[hash].js",
          assetFileNames: "static/[ext]/[name]-[hash].[ext]"
        }
      }
    },
    define: {
      __INTLIFY_PROD_DEVTOOLS__: false,
      __APP_INFO__: JSON.stringify(__APP_INFO__)
    }
  };
};
