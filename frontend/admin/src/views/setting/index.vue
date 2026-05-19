<script setup lang="tsx">
import { onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { PlusDrawerForm } from "plus-pro-components";
import { ElButton, ElPopconfirm, ElTabs, ElTabPane } from "element-plus";
import { getAllSettings, destroyGroup, clearCache } from "@/api/setting";
import { useSettingGroupStore } from "./groupStore";
import SettingGroup from "./SettingGroup.vue";
import { message } from "@shared/utils";
import { useDrawerSize } from "@/views/system/drawer";

defineOptions({
  name: "Setting"
});

const route = useRoute();
const router = useRouter();

// 从 route.query.tab 取首个值，处理 string[]/null/undefined
const readQueryTab = (): string => {
  const raw = route.query.tab;
  if (Array.isArray(raw)) return (raw[0] as string) || "";
  return (raw as string) || "";
};

const { drawerSize } = useDrawerSize();

const loading = ref(false);
const groups = ref<any[]>([]);
const activeTab = ref<string>("");

const {
  groupFormRef,
  showGroupForm,
  groupId,
  groupValues,
  groupColumns,
  rules,
  openGroupForm,
  confirmGroupForm,
  closeGroupForm
} = useSettingGroupStore((newGroupName?: string) => loadSettings(newGroupName));

// 加载所有设置；preferTab 指定加载完成后优先选中的 tab name
const loadSettings = (preferTab?: string) => {
  loading.value = true;
  getAllSettings().then(({ data }) => {
    groups.value = data.groups || [];
    syncActiveTab(preferTab);
    loading.value = false;
  });
};

// 校正 activeTab：优先 preferTab → 当前 activeTab → URL query → 第一个分组
const syncActiveTab = (preferTab?: string) => {
  const names = groups.value.map(g => g.name);
  const candidates = [preferTab, activeTab.value, readQueryTab()];
  const chosen = candidates.find(n => n && names.includes(n)) || names[0] || "";
  if (activeTab.value !== chosen) {
    activeTab.value = chosen;
  }
  // URL 同步
  const currentQueryTab = readQueryTab();
  if (chosen && currentQueryTab !== chosen) {
    router.replace({ query: { ...route.query, tab: chosen } });
  } else if (!chosen && currentQueryTab) {
    const { tab: _tab, ...rest } = route.query;
    router.replace({ query: rest });
  }
};

// activeTab 变更（用户点 tab）→ 同步到 URL
watch(activeTab, newName => {
  if (!newName) return;
  const currentQueryTab = readQueryTab();
  if (currentQueryTab !== newName) {
    router.replace({ query: { ...route.query, tab: newName } });
  }
});

// URL query.tab 变更（后退/前进按钮、外部链接）→ 同步到 activeTab
watch(
  () => route.query.tab,
  newTab => {
    const name = Array.isArray(newTab)
      ? (newTab[0] as string) || ""
      : (newTab as string) || "";
    const names = groups.value.map(g => g.name);
    if (name && names.includes(name) && activeTab.value !== name) {
      activeTab.value = name;
    }
  }
);

const handleAddGroup = () => {
  openGroupForm(0);
};

const handleEditGroup = (id: number) => {
  openGroupForm(id);
};

const handleDeleteGroup = (id: number) => {
  destroyGroup(id).then(() => {
    message("删除成功", { type: "success" });
    // 当前 tab 是被删的就清空，让 syncActiveTab 回落到第一个
    const deleted = groups.value.find(g => g.id === id);
    const preferTab =
      deleted && deleted.name === activeTab.value ? "" : activeTab.value;
    activeTab.value = preferTab;
    loadSettings();
  });
};

const handleClearCache = () => {
  clearCache().then(() => {
    message("缓存已清除", { type: "success" });
  });
};

onMounted(() => {
  loadSettings();
});
</script>

<template>
  <div class="main">
    <div
      class="setting-header bg-bg_color w-full p-4 mb-4 flex justify-between items-center"
    >
      <h3 class="text-xl font-bold text-gray-600">系统设置</h3>
      <div class="flex gap-2">
        <el-popconfirm
          title="确定要清除所有设置缓存吗？"
          width="240"
          @confirm="handleClearCache"
        >
          <template #reference>
            <el-button type="warning">清除缓存</el-button>
          </template>
        </el-popconfirm>
        <el-button type="primary" @click="handleAddGroup">添加设置组</el-button>
      </div>
    </div>

    <div v-loading="loading" class="setting-groups bg-bg_color rounded">
      <template v-if="groups.length > 0">
        <el-tabs v-model="activeTab" tab-position="top" class="setting-tabs">
          <el-tab-pane
            v-for="group in groups"
            :key="group.id"
            :label="group.title"
            :name="group.name"
          >
            <SettingGroup
              :group="group"
              :onRefresh="() => loadSettings()"
              @edit-group="handleEditGroup"
              @delete-group="handleDeleteGroup"
            />
          </el-tab-pane>
        </el-tabs>
      </template>

      <div v-else class="empty-groups text-center py-8">
        <p class="text-gray-500 mb-4">暂无设置组</p>
        <el-button type="primary" @click="handleAddGroup">添加设置组</el-button>
      </div>
    </div>

    <PlusDrawerForm
      ref="groupFormRef"
      v-model="groupValues"
      :visible="showGroupForm"
      :form="{
        columns: groupColumns,
        rules,
        labelPosition: 'right',
        labelSuffix: ''
      }"
      :size="drawerSize"
      :closeOnClickModal="true"
      :title="groupId > 0 ? '编辑设置组' : '新增设置组'"
      confirmText="提交"
      cancelText="取消"
      @confirm="confirmGroupForm"
      @cancel="closeGroupForm"
    />
  </div>
</template>

<style scoped lang="scss">
.main {
  padding: 16px;
}

.setting-header {
  border-radius: 4px;
}

.setting-tabs {
  padding: 8px 16px 16px;
}
</style>
