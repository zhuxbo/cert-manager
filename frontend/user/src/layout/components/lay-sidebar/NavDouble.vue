<script setup lang="ts">
import { router } from "@/router";
import { useRoute } from "vue-router";
import { emitter } from "@shared/utils";
import { ReText } from "@shared/components/ReText";
import { useNav } from "@/layout/hooks/useNav";
import { responsiveStorageNameSpace } from "@/config";
import { useAppStoreHook } from "@/store/modules/app";
import { storageLocal, isAllEmpty } from "@pureadmin/utils";
import { useRenderIcon } from "@shared/components/ReIcon/src/hooks";
import { findRouteByPath, getParentPaths } from "@/router/utils";
import { usePermissionStoreHook } from "@/store/modules/permission";
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue";
import LaySidebarItem from "../lay-sidebar/components/SidebarItem.vue";
import LaySidebarLeftCollapse from "../lay-sidebar/components/SidebarLeftCollapse.vue";
import LaySidebarCenterCollapse from "../lay-sidebar/components/SidebarCenterCollapse.vue";

const route = useRoute();
const isShow = ref(false);
const showLogo = ref(
  storageLocal().getItem<StorageConfigs>(
    `${responsiveStorageNameSpace()}configure`
  )?.showLogo ?? true
);

const {
  pureApp,
  getLogo,
  isCollapse,
  tooltipEffect,
  menuSelect,
  backTopMenu,
  toggleSideBar
} = useNav();

const curActive = ref(0);
const childMenu = ref([]);
const subMenuData = ref([]);

const menuData = computed(() => usePermissionStoreHook().wholeMenus);
const currentMenuTitle = computed(
  () => menuData.value[curActive.value]?.meta?.title ?? ""
);

const defaultActive = computed(() =>
  !isAllEmpty(route.meta?.activePath) ? route.meta.activePath : route.path
);

function getSubMenuData() {
  let path = "";
  path = defaultActive.value;
  subMenuData.value = [];
  // path的上级路由组成的数组
  const parentPathArr = getParentPaths(
    path,
    usePermissionStoreHook().wholeMenus
  );
  // 当前路由的父级路由信息
  const parenetRoute = findRouteByPath(
    parentPathArr[0] || path,
    usePermissionStoreHook().wholeMenus
  );
  if (!parenetRoute?.children) return;
  subMenuData.value = parenetRoute?.children;
  childMenu.value = parenetRoute?.children;
  useAppStoreHook().showDouble(
    childMenu.value.length > 1 || childMenu.value[0]?.meta?.showParent
      ? true
      : false
  );
  curActive.value = menuData.value.findIndex(v => v.path === parenetRoute.path);
}

function handleChildMenu(menu, index) {
  childMenu.value = menu.children;
  router.push(menu.path);
  curActive.value = index;
  useAppStoreHook().showDouble(
    childMenu.value.length > 1 || childMenu.value[0]?.meta?.showParent
      ? true
      : false
  );
}

watch(
  () => [route.path, usePermissionStoreHook().wholeMenus],
  () => {
    if (route.path.includes("/redirect")) return;
    getSubMenuData();
    menuSelect(route.path);
  }
);

onMounted(() => {
  getSubMenuData();

  emitter.on("logoChange", key => {
    showLogo.value = key;
  });
});

onBeforeUnmount(() => {
  // 解绑`logoChange`公共事件，防止多次触发
  emitter.off("logoChange");
});
</script>

<template>
  <div class="w-[290px]">
    <div
      :class="[
        'double-left',
        'w-[80px]',
        'h-full',
        showLogo ? 'has-logo' : 'no-logo'
      ]"
    >
      <div v-if="showLogo" class="double-logo" @click="backTopMenu">
        <img :src="getLogo()" alt="logo" />
      </div>
      <el-scrollbar wrap-class="scrollbar-wrapper">
        <ul>
          <li
            v-for="(menu, index) in menuData"
            :key="index"
            :class="curActive === index ? 'is-active' : ''"
            @click="handleChildMenu(menu, index)"
          >
            <div
              :class="[
                'text-xl',
                isAllEmpty(menu?.meta.icon) ? 'mb-0' : 'mb-2'
              ]"
            >
              <component :is="useRenderIcon(menu?.meta.icon)" />
            </div>
            <ReText
              :tippyProps="{
                theme: tooltipEffect
              }"
              class="w-full! text-inherit! text-xs!"
            >
              {{ menu.meta.title }}
            </ReText>
          </li>
        </ul>
      </el-scrollbar>
    </div>
    <div
      v-if="useAppStoreHook().isShowDouble"
      :class="['double-sidebar', !isCollapse ? 'has-title' : 'no-title']"
      @mouseenter.prevent="isShow = true"
      @mouseleave.prevent="isShow = false"
    >
      <div v-show="!isCollapse" class="sidebar-logo-container">
        <span class="sidebar-title" :title="currentMenuTitle">
          {{ currentMenuTitle }}
        </span>
      </div>

      <el-scrollbar wrap-class="scrollbar-wrapper">
        <el-menu
          unique-opened
          mode="vertical"
          popper-class="pure-scrollbar"
          class="outer-most select-none"
          :collapse="isCollapse"
          :collapse-transition="false"
          :popper-effect="tooltipEffect"
          :default-active="defaultActive"
        >
          <LaySidebarItem
            v-for="routes in childMenu"
            :key="routes.path"
            :item="routes"
            :base-path="routes.path"
            class="outer-most select-none"
          />
        </el-menu>
      </el-scrollbar>
      <LaySidebarCenterCollapse
        v-if="isShow || isCollapse"
        :is-active="pureApp.sidebar.opened"
        @toggleClick="toggleSideBar"
      />
      <LaySidebarLeftCollapse
        :is-active="pureApp.sidebar.opened"
        @toggleClick="toggleSideBar"
      />
    </div>
  </div>
</template>

<style lang="scss" scoped>
.sidebar-logo-container {
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  justify-content: center;
  height: 48px;
  overflow: hidden;

  .sidebar-title {
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 18px;
    font-weight: 600;
    color: var(--pure-theme-sub-menu-active-text);
    white-space: nowrap;
  }
}
</style>
