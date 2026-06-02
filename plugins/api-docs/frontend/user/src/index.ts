import ApiDocs from "./views/ApiDocs.vue";

// 注入到「系统设置」（user 端顶层菜单 name = "Users"）下的子菜单
window.__registerPlugin({
  name: "api-docs",
  routes: [
    {
      parent: "Users",
      route: {
        path: "/api-docs",
        name: "ApiDocs",
        component: ApiDocs,
        meta: {
          icon: "ep:document",
          title: "接口文档",
          keepAlive: true
        }
      }
    }
  ]
});
