<script setup lang="ts">
import { computed } from "vue";
import { InfoFilled } from "@element-plus/icons-vue";
import {
  schemaFieldHelp,
  schemaFieldLabel,
  type SchemaFieldLike
} from "./schemaLabel";

const props = defineProps<{
  field: SchemaFieldLike;
}>();

const label = computed(() => schemaFieldLabel(props.field));
const help = computed(() => schemaFieldHelp(props.field));
</script>

<template>
  <span
    style="
      display: inline-flex;
      max-width: 100%;
      align-items: center;
      justify-content: flex-end;
      gap: 4px;
      vertical-align: middle;
    "
  >
    <span
      style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap"
      >{{ label }}</span
    >
    <el-tooltip v-if="help" :content="help" placement="top">
      <el-icon
        style="flex: 0 0 auto; color: var(--el-color-info); cursor: help"
      >
        <InfoFilled />
      </el-icon>
    </el-tooltip>
  </span>
</template>
