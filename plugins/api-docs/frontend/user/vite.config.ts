import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import { resolve } from "path";

// IIFE 打包：external 主系统四件套（用 __deps 全局），
// Scalar 及其依赖全量打入插件 bundle —— 体积只进插件，不进主系统主包
export default defineConfig({
  plugins: [vue()],
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
