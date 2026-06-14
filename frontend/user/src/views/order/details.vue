<template>
  <div class="main">
    <template v-for="(_item, index) in details" :key="index">
      <div class="layout-main bg-bg_color">
        <Detail v-model="details[index]" />
      </div>
    </template>
  </div>
</template>
<script setup lang="ts">
import { ref } from "vue";
import { usePolling } from "@shared/hooks/usePolling";
import { batchShow } from "@/api/order";
import Detail from "./detail/index.vue";
import router from "@/router";

defineOptions({
  name: "OrderDetails"
});

const details = ref<any[]>([]);
const getDetails = () => {
  const ids = router.currentRoute.value.params.ids;
  if (!ids) return;
  batchShow(ids.toString()).then(res => {
    const fresh = res.data.items ?? [];
    if (details.value.length === 0) {
      details.value = fresh;
      return;
    }
    // 刷新：按 id 就地 mutate 已有卡片对象。子组件 reactive(props.modelValue) 持同一引用，
    // 替换整个 details 数组不会更新已挂载子组件，故按 id 匹配 Object.assign。
    // 仅更新 order 主体字段，不 bump order.sync：文档变化频率极低、签发记录靠切 tab 时拉取，
    // 无需随轮询重载；手动同步/刷新/各操作仍会 bump sync 触发子组件重载。
    const map = new Map<number, any>(fresh.map((it: any) => [it.id, it]));
    details.value.forEach((it: any) => {
      const updated = map.get(it.id);
      if (updated) Object.assign(it, updated);
    });
  });
};

// 定时刷新上提到父级：单个 batchShow 批量刷新所有卡片，替代每卡片各自 setInterval 的 N 并发，
// 消除多卡片 / 切回前台时的请求风暴。轮询走纯读 show（batchShow），不触发上游 sync。
// 切到后台标签页跳过本次；切回前台立即刷新一次。mount 时先立即取一次。
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
