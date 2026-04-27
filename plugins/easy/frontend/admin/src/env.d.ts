declare namespace JSX {
  interface IntrinsicElements {
    [elem: string]: any;
  }
}

declare module "~icons/*" {
  import type { Component } from "vue";
  const component: Component;
  export default component;
}

declare module "*.css";
