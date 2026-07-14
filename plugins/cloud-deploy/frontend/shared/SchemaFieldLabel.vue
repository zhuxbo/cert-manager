<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch
} from "vue";
import { InfoFilled } from "@element-plus/icons-vue";
import {
  isTextTruncated,
  schemaFieldHelp,
  schemaFieldLabel,
  type SchemaFieldLike
} from "./schemaLabel";

const props = defineProps<{
  field: SchemaFieldLike;
}>();

const label = computed(() => schemaFieldLabel(props.field));
const help = computed(() => schemaFieldHelp(props.field));
const labelElement = ref<HTMLElement | null>(null);
const labelTruncated = ref(false);
let resizeObserver: ResizeObserver | null = null;

function updateLabelTruncated(): void {
  labelTruncated.value = labelElement.value
    ? isTextTruncated(labelElement.value)
    : false;
}

onMounted(() => {
  void nextTick(() => {
    updateLabelTruncated();

    if (typeof ResizeObserver !== "undefined" && labelElement.value) {
      resizeObserver = new ResizeObserver(updateLabelTruncated);
      resizeObserver.observe(labelElement.value);
    }
  });
});

watch(label, () => {
  void nextTick(updateLabelTruncated);
});

onBeforeUnmount(() => {
  resizeObserver?.disconnect();
});
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
    <el-tooltip
      :content="label"
      :disabled="!labelTruncated"
      placement="top"
      :show-after="300"
    >
      <span
        ref="labelElement"
        style="
          min-width: 0;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        "
        >{{ label }}</span
      >
    </el-tooltip>
    <el-tooltip v-if="help" :content="help" placement="top">
      <el-icon
        style="flex: 0 0 auto; color: var(--el-color-info); cursor: help"
      >
        <InfoFilled />
      </el-icon>
    </el-tooltip>
  </span>
</template>
