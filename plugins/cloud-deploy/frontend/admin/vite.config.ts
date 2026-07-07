import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import vueJsx from "@vitejs/plugin-vue-jsx";
import { resolve } from "path";

export default defineConfig({
  plugins: [vue(), vueJsx()],
  resolve: {
    alias: {
      "@": resolve(__dirname, "src"),
      "@cloud-deploy/shared": resolve(__dirname, "../shared")
    }
  },
  build: {
    lib: {
      entry: resolve(__dirname, "src/index.ts"),
      name: "CloudDeployPluginAdmin",
      formats: ["iife"],
      fileName: () => "cloud-deploy-plugin.iife.js"
    },
    outDir: "dist",
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
