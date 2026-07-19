export default {
  path: "/auto-deploy",
  name: "AutoDeploy",
  redirect: "/delegation",
  meta: {
    icon: "ri:rocket-2-fill",
    title: "自动部署",
    rank: 1.8
  },
  children: [
    {
      path: "/delegation",
      name: "Delegation",
      component: () => import("@/views/delegation/index.vue"),
      meta: {
        title: "域名委托",
        keepAlive: true
      }
    },
    {
      path: "/auto-deploy/reports",
      name: "AutoDeployReports",
      component: () => import("@/views/auto-deploy/reports.vue"),
      meta: {
        title: "部署记录",
        keepAlive: true
      }
    }
  ]
} satisfies RouteConfigsTable;
