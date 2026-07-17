<script setup lang="ts">
import { ref } from "vue";
import { Plus } from "@element-plus/icons-vue";
import Targets from "./targets.vue";
import Accesses from "./accesses.vue";
import Logs from "./logs.vue";

const tab = ref("targets");
const targetsRef = ref<any>(null);
const accessesRef = ref<any>(null);

function openCreate() {
  if (tab.value === "targets") {
    targetsRef.value?.openCreate();
  } else if (tab.value === "accesses") {
    accessesRef.value?.openCreate();
  }
}
</script>

<template>
  <el-card>
    <div style="position: relative">
      <div style="position: absolute; top: 0; right: 0; z-index: 1">
        <el-button
          v-if="tab === 'targets' || tab === 'accesses'"
          :icon="Plus"
          size="small"
          type="primary"
          @click="openCreate"
        >
          {{ tab === "targets" ? "新增目标" : "新增凭证" }}
        </el-button>
      </div>
      <el-tabs v-model="tab">
        <el-tab-pane label="部署目标" name="targets">
          <Targets ref="targetsRef" />
        </el-tab-pane>
        <el-tab-pane label="云凭证" name="accesses">
          <Accesses ref="accessesRef" />
        </el-tab-pane>
        <el-tab-pane label="部署历史" name="logs"><Logs /></el-tab-pane>
      </el-tabs>
    </div>
  </el-card>
</template>
