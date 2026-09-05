<script setup lang="ts">
import { ref } from "vue";
import { useNav } from "@/layout/hooks/useNav";
import { message } from "@shared/utils";
import { ElMessageBox } from "element-plus";
import { refreshSettingCacheSafely } from "@/api/setting";
import LayNavMix from "../lay-sidebar/NavMix.vue";
import LaySidebarBreadCrumb from "../lay-sidebar/components/SidebarBreadCrumb.vue";
import LaySidebarTopCollapse from "../lay-sidebar/components/SidebarTopCollapse.vue";

import HomeFill from "~icons/ri/home-9-fill";
import DeleteBin2Fill from "~icons/ri/delete-bin-2-fill";
import UserFill from "~icons/ri/user-fill";
import LogoutCircleRLine from "~icons/ri/logout-circle-r-line";
import Setting from "~icons/ri/settings-3-line";

const { layout, device, logout, onPanel, pureApp, username, toggleSideBar } =
  useNav();

const clearingCache = ref(false);

function handleClearCache() {
  if (clearingCache.value) return;
  ElMessageBox.confirm(
    "确认刷新系统设置缓存？队列、调度器、登录会话、限流和任务锁等运行状态不会被清除。",
    "安全刷新设置缓存",
    {
      type: "warning",
      confirmButtonText: "确认清理",
      cancelButtonText: "取消"
    }
  ).then(() => {
    clearingCache.value = true;
    refreshSettingCacheSafely()
      .then(() => {
        message("系统设置缓存已安全刷新", { type: "success" });
      })
      .catch(() => {
        message("缓存清除失败", { type: "error" });
      })
      .finally(() => {
        clearingCache.value = false;
      });
  });
}
</script>

<template>
  <div class="navbar bg-[#fff] shadow-sm shadow-[rgba(0,21,41,0.08)]">
    <LaySidebarTopCollapse
      v-if="device === 'mobile'"
      class="hamburger-container"
      :is-active="pureApp.sidebar.opened"
      @toggleClick="toggleSideBar"
    />

    <LaySidebarBreadCrumb
      v-if="layout !== 'mix' && device !== 'mobile'"
      class="breadcrumb-container"
    />

    <LayNavMix v-if="layout === 'mix'" />

    <div
      v-if="layout === 'vertical' || layout === 'double'"
      class="vertical-header-right"
    >
      <a
        class="home-icon navbar-bg-hover"
        title="返回首页"
        href="/"
        target="_blank"
        rel="noopener noreferrer"
      >
        <IconifyIconOffline :icon="HomeFill" />
      </a>
      <span
        class="cache-icon navbar-bg-hover"
        title="安全刷新系统设置缓存"
        @click="handleClearCache"
      >
        <IconifyIconOffline
          :icon="DeleteBin2Fill"
          :style="{ opacity: clearingCache ? 0.5 : 1 }"
        />
      </span>
      <!-- 退出登录 -->
      <el-dropdown trigger="click">
        <span class="el-dropdown-link navbar-bg-hover select-none">
          <IconifyIconOffline :icon="UserFill" style="margin: 5px" />
          <p v-if="username">{{ username }}</p>
        </span>
        <template #dropdown>
          <el-dropdown-menu class="logout">
            <el-dropdown-item @click="logout">
              <IconifyIconOffline
                :icon="LogoutCircleRLine"
                style="margin: 5px"
              />
              退出系统
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
      <span
        class="set-icon navbar-bg-hover"
        title="打开系统配置"
        @click="onPanel"
      >
        <IconifyIconOffline :icon="Setting" />
      </span>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.navbar {
  width: 100%;
  height: 48px;
  overflow: hidden;

  .hamburger-container {
    float: left;
    height: 100%;
    line-height: 48px;
    cursor: pointer;
  }

  .vertical-header-right {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    min-width: 280px;
    height: 48px;

    .el-dropdown-link {
      display: flex;
      align-items: center;
      justify-content: space-around;
      height: 48px;
      padding: 10px;
      cursor: pointer;

      p {
        font-size: 14px;
      }

      img {
        width: 22px;
        height: 22px;
        border-radius: 50%;
      }
    }

    /* 首页图标样式，和用户图标保持一致，并适配暗色模式 */
    .home-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 48px;
      padding: 10px;
      color: var(--el-text-color-regular);
      text-decoration: none;
      cursor: pointer;
      border-radius: 4px;
    }

    .home-icon:hover,
    .cache-icon:hover {
      background-color: var(--el-fill-color-light);
    }

    .cache-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 48px;
      padding: 10px;
      color: var(--el-text-color-regular);
      cursor: pointer;
      border-radius: 4px;
    }
  }

  .breadcrumb-container {
    float: left;
    margin-left: 16px;
  }
}

.logout {
  width: 120px;

  ::v-deep(.el-dropdown-menu__item) {
    display: inline-flex;
    flex-wrap: wrap;
    min-width: 100%;
  }
}
</style>
