<template>
  <div class="descriptions">
    <el-button
      v-for="option in downloadOptions"
      :key="option.value"
      type="primary"
      link
      @click="OrderApi.download(order.id, option.value)"
    >
      {{ option.label }}
    </el-button>
  </div>
</template>

<script setup lang="ts">
import { inject } from "vue";
import * as OrderApi from "@/api/order";
import { getSmimeDownloadOptions } from "@shared/smimeDownloadOptions";

const order = inject("order") as any;
const downloadOptions = getSmimeDownloadOptions();
</script>

<style scoped lang="scss">
.descriptions {
  width: 100%;
  padding: 5px 0 5px 20px;
  margin-bottom: 10px;
  font-size: 14px;
  line-height: 28px;
  white-space: nowrap;
  border-left: 4px solid var(--el-border-color);
}
</style>
