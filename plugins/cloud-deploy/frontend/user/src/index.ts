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
      parent: "Users", // user 端「系统设置」顶层 route name=Users
      route: {
        path: "/cloud-deploy",
        name: "CloudDeploy",
        component: CloudDeployIndex,
        meta: { icon: "ri:cloud-line", title: "云部署", keepAlive: true }
      }
    }
  ],
  // widget 直接传组件类：process.vue 的 <component :is :order :cert> 才能把 order/cert 作为真实 props 传入。
  // 不可包成 (props)=>h(DeployActions,props)——函数式组件下外部属性落 attrs、props 参数为空，DeployActions 收不到。
  widgets: [
    {
      slot: "user-order-detail-ssl-actions",
      component: DeployActions
    }
  ]
});
