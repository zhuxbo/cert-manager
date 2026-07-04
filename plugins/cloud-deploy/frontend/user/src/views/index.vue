<script setup lang="ts">
import { ref } from "vue";
import Access from "./access.vue";
import Target from "./target.vue";
import Log from "./log.vue";

const tab = ref("target");
</script>

<template>
  <el-card>
    <el-tabs v-model="tab">
      <el-tab-pane label="部署目标" name="target"><Target /></el-tab-pane>
      <el-tab-pane label="云凭证" name="access"><Access /></el-tab-pane>
      <el-tab-pane label="部署历史" name="log"><Log /></el-tab-pane>
    </el-tabs>
  </el-card>
</template>
