export default {
  path: "/system",
  name: "System",
  redirect: "/task",
  meta: {
    icon: "ri:settings-5-fill",
    title: "系统",
    rank: 99
  },
  children: [
    {
      path: "/task",
      name: "Task",
      component: () => import("@/views/task/index.vue"),
      meta: {
        icon: "ri:task-line",
        title: "任务管理",
        keepAlive: true
      }
    },
    {
      path: "/notification/records",
      name: "NotificationRecords",
      component: () => import("@/views/notification/record/index.vue"),
      meta: {
        icon: "ri:history-line",
        title: "通知记录",
        keepAlive: true
      }
    },
    {
      path: "/notification/templates",
      name: "NotificationTemplate",
      component: () => import("@/views/notification/template/index.vue"),
      meta: {
        icon: "ri:article-line",
        title: "通知模板",
        keepAlive: true
      }
    },
    {
      path: "/setting",
      name: "Setting",
      component: () => import("@/views/setting/index.vue"),
      meta: {
        icon: "ri:settings-3-line",
        title: "系统设置",
        keepAlive: true
      }
    },
    {
      path: "/plugin",
      name: "Plugin",
      component: () => import("@/views/plugin/index.vue"),
      meta: {
        icon: "ri:plug-line",
        title: "插件管理",
        keepAlive: true
      }
    },
    {
      path: "/upgrade",
      name: "Upgrade",
      component: () => import("@/views/upgrade/index.vue"),
      meta: {
        icon: "ri:download-cloud-2-line",
        title: "系统升级",
        keepAlive: true
      }
    },
    {
      path: "/database-backup",
      name: "DatabaseBackup",
      component: () => import("@/views/database-backup/index.vue"),
      meta: {
        icon: "ri:database-2-line",
        title: "数据库备份",
        keepAlive: true
      }
    }
  ]
} satisfies RouteConfigsTable;
