<script setup lang="ts">
import { ref } from "vue";
import Targets from "./targets.vue";
import Accesses from "./accesses.vue";
import Logs from "./logs.vue";

const tab = ref("targets");
</script>

<template>
  <el-card>
    <el-tabs v-model="tab">
      <el-tab-pane label="部署目标" name="targets"><Targets /></el-tab-pane>
      <el-tab-pane label="云凭证" name="accesses"><Accesses /></el-tab-pane>
      <el-tab-pane label="部署历史" name="logs"><Logs /></el-tab-pane>
    </el-tabs>
  </el-card>
</template>
