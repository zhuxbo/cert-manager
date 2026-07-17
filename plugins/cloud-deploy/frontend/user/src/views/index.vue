<script setup lang="ts">
import { ref } from "vue";
import { Plus } from "@element-plus/icons-vue";
import Access from "./access.vue";
import Target from "./target.vue";
import Log from "./log.vue";

const tab = ref("target");
const targetRef = ref<any>(null);
const accessRef = ref<any>(null);

function openCreate() {
  if (tab.value === "target") {
    targetRef.value?.openCreate();
  } else if (tab.value === "access") {
    accessRef.value?.openCreate();
  }
}
</script>

<template>
  <el-card>
    <div style="position: relative">
      <div style="position: absolute; top: 0; right: 0; z-index: 1">
        <el-button
          v-if="tab === 'target' || tab === 'access'"
          :icon="Plus"
          size="small"
          type="primary"
          @click="openCreate"
        >
          {{ tab === "target" ? "新增目标" : "新增凭证" }}
        </el-button>
      </div>
      <el-tabs v-model="tab">
        <el-tab-pane label="部署目标" name="target">
          <Target ref="targetRef" />
        </el-tab-pane>
        <el-tab-pane label="云凭证" name="access">
          <Access ref="accessRef" />
        </el-tab-pane>
        <el-tab-pane label="部署历史" name="log"><Log /></el-tab-pane>
      </el-tabs>
    </div>
  </el-card>
</template>
