import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import { resolve } from "path";
import { copyFileSync, existsSync } from "fs";

// IIFE 打包：external 主系统四件套（用 __deps 全局），
// Scalar 及其依赖全量打入插件 bundle —— 体积只进插件，不进主系统主包
export default defineConfig({
  plugins: [
    vue(),
    {
      // Scalar 官方 standalone bundle 在 iframe 内独立加载，不进 vite 打包；
      // 构建后从 node_modules 复制到 dist。放 closeBundle 而非 package.json 的
      // `&& cp`：make plugins-build 直接 exec vite build 绕过 package.json 脚本，
      // cp 只挂 package.json 时它不产出 standalone；放 closeBundle 则两条构建路径
      //（make plugins-build 的 exec vite build / release-plugin.sh 的 pnpm build）都触发。
      name: "copy-scalar-standalone",
      closeBundle() {
        const src = resolve(
          __dirname,
          "node_modules/@scalar/api-reference/dist/browser/standalone.js"
        );
        if (!existsSync(src)) {
          throw new Error(
            `[api-docs] Scalar standalone bundle 不存在: ${src}（先在该目录 pnpm install）`
          );
        }
        copyFileSync(src, resolve(__dirname, "dist/scalar-standalone.js"));
      }
    }
  ],
  resolve: {
    alias: {
      "@": resolve(__dirname, "src")
    }
  },
  build: {
    lib: {
      entry: resolve(__dirname, "src/index.ts"),
      name: "ApiDocsPluginUser",
      formats: ["iife"],
      fileName: () => "api-docs-plugin.iife.js"
    },
    outDir: "dist",
    // Scalar 体积大，关闭 chunk 大小告警噪音
    chunkSizeWarningLimit: 4000,
    rollupOptions: {
      external: ["vue", "vue-router", "element-plus", "pinia"],
      output: {
        globals: {
          vue: "__deps.Vue",
          "vue-router": "__deps.VueRouter",
          "element-plus": "__deps.ElementPlus",
          pinia: "__deps.Pinia"
        }
      }
    }
  }
});
