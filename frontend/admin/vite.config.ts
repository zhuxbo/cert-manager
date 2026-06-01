import {
  type UserConfigExport,
  type ConfigEnv,
  loadEnv,
  type Plugin
} from "vite";
import { resolve, relative, isAbsolute } from "path";
import { createReadStream, existsSync, statSync } from "fs";
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
        "/api/admin": {
          target: `${apiTarget}/api/admin`,
          changeOrigin: true,
          rewrite: path => path.replace(/^\/api\/admin/, "")
        },
        "/api/plugins": {
          target: apiTarget,
          changeOrigin: true
        },
        "/api/meta": {
          target: apiTarget,
          changeOrigin: true
        }
      },
      // 预热文件以提前转换和缓存结果，降低启动期间的初始页面加载时长并防止转换瀑布
      warmup: {
        clientFiles: ["./index.html", "./src/{views,components}/*"]
      }
    },
    plugins: [servePlugins(), ...getPluginsList(VITE_CDN, VITE_COMPRESSION)],
    // https://cn.vitejs.dev/config/dep-optimization-options.html#dep-optimization-options
    optimizeDeps: {
      include,
      exclude
    },
    build: {
      // https://cn.vitejs.dev/guide/build.html#browser-compatibility
      target: "es2015",
      sourcemap: false,
      // 超过此大小（KB）的 chunk 触发警告，便于及时发现过大产物
      chunkSizeWarningLimit: 1000,
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
          assetFileNames: "static/[ext]/[name]-[hash].[ext]",
          // 拆分稳定大依赖为独立 vendor chunk，提升长期缓存命中率
          manualChunks(id) {
            if (!id.includes("node_modules")) return;
            // vue 全家桶归一个 chunk，避免运行时初始化顺序/循环依赖问题
            if (
              /node_modules\/(@vue\/|vue\/|vue-router\/|pinia\/|@pinia\/|vue-demi\/)/.test(
                id
              )
            ) {
              return "vue-vendor";
            }
            if (id.includes("node_modules/echarts/")) {
              return "echarts";
            }
            // zrender 是 echarts 的渲染底座，并入同一 chunk
            if (id.includes("node_modules/zrender/")) {
              return "echarts";
            }
            if (
              id.includes("node_modules/element-plus/") ||
              id.includes("node_modules/@element-plus/")
            ) {
              return "element-plus";
            }
          }
        }
      }
    },
    define: {
      __INTLIFY_PROD_DEVTOOLS__: false,
      __APP_INFO__: JSON.stringify(__APP_INFO__)
    }
  };
};
