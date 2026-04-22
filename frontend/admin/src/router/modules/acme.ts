export default {
  path: "/acmes",
  name: "Acmes",
  redirect: "/acme",
  meta: {
    icon: "ri:robot-2-fill",
    title: "ACME",
    rank: 1.5,
    showLink: true
  },
  children: [
    {
      path: "/acme",
      name: "Acme",
      component: () => import("@/views/acme/index.vue"),
      meta: {
        title: "ACME",
        keepAlive: true
      }
    },
    {
      path: "/acme/details/:ids",
      name: "AcmeDetails",
      component: () => import("@/views/acme/details.vue"),
      meta: {
        title: "ACME 详情",
        activePath: "/acme",
        showLink: false
      }
    }
  ]
} satisfies RouteConfigsTable;
