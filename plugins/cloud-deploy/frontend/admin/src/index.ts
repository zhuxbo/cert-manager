import CloudDeployIndex from "./views/index.vue";
import DeployActions from "./components/DeployActions.vue";

declare global {
  interface Window {
    __registerPlugin: (config: any) => void;
    __deps: any;
  }
}

window.__registerPlugin({
  name: "cloud-deploy",
  routes: [
    {
      // admin app「系统」一级分组 route name=System；勿照抄 user 的 parent:"Users"
      //（admin app 的 Users 是「用户管理」，语义相反；plugin-loader addRoute 父名传错菜单不显示）
      parent: "System",
      route: {
        path: "/cloud-deploy",
        name: "CloudDeployAdmin", // 与 user 的 "CloudDeploy" 区分；仅 meta(icon/title) 与 user 对齐
        component: CloudDeployIndex,
        meta: { icon: "ri:cloud-line", title: "云部署", keepAlive: true }
      }
    }
  ],
  // widget 直接传组件类：process.vue 的 <component :is :order :cert> 才能把 order/cert 作为真实 props 传入
  widgets: [{ slot: "admin-order-detail-ssl-actions", component: DeployActions }]
});
