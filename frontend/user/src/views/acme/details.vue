<template>
  <div class="main">
    <template v-for="(_item, index) in items" :key="index">
      <div class="layout-main bg-bg_color">
        <DetailCard v-model="items[index]" />
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { usePolling } from "@shared/hooks/usePolling";
import { batchShowAcmes } from "@/api/acme";
import type { Acme } from "@/api/acme";
import { useRoute } from "vue-router";
import DetailCard from "./detail-card.vue";

defineOptions({
  name: "AcmeDetails"
});

const route = useRoute();
const items = ref<Acme[]>([]);

const getDetails = () => {
  const idsParam = route.params.ids?.toString() ?? "";
  if (!idsParam) return;

  // 与 order 对齐：直接传逗号连接字符串，后端 BaseRequest.prepareForValidation 统一 explode 成数组
  batchShowAcmes(idsParam).then(res => {
    const fresh = res.data?.items ?? [];
    if (items.value.length === 0) {
      items.value = fresh;
      return;
    }
    // 刷新：按 id 就地 mutate 已有卡片对象。子组件 reactive(props.modelValue) 持同一引用，
    // 替换整个 items 数组不会更新已挂载子组件，故按 id 匹配 Object.assign 保持卡片位置与内部状态。
    const map = new Map(fresh.map(it => [it.id, it]));
    items.value.forEach(it => {
      const updated = map.get(it.id);
      if (updated) Object.assign(it, updated);
    });
  });
};

// 定时刷新上提到父级：单个 batchShowAcmes 批量刷新所有卡片，避免子卡片各自 setInterval
// 在多卡片 / 切标签页时产生并发请求风暴。切到后台标签页跳过本次；切回前台立即刷新一次。mount 时先立即取一次。
usePolling(getDetails, { immediate: true });
</script>

<style scoped lang="scss">
.layout-main {
  width: 100%;
  height: 100%;
  margin-bottom: 20px;
  overflow: hidden;
}
</style>
