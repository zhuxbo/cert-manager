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
import { onMounted, ref } from "vue";
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
  const ids = idsParam
    .split(",")
    .map(s => Number(s))
    .filter(n => !!n);
  if (ids.length === 0) return;

  batchShowAcmes(ids).then(res => {
    items.value = res.data?.items ?? [];
  });
};

// 父组件只在 mount 时拉一次 batchShow；3 分钟自动刷新由每个 DetailCard 子组件自治（与传统 Order 详情聚合页一致）
onMounted(() => {
  getDetails();
});
</script>

<style scoped lang="scss">
.layout-main {
  width: 100%;
  height: 100%;
  margin-bottom: 20px;
  overflow: hidden;
}
</style>
