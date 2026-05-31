// unplugin-vue-markdown 把 *.md 编译为 Vue 组件（用于「接口文档」面板）
declare module "*.md" {
  import type { DefineComponent } from "vue";

  const component: DefineComponent<
    Record<string, never>,
    Record<string, never>,
    unknown
  >;
  export default component;
}
