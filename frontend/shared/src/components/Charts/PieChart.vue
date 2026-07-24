<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from "vue";
import { useResizeObserver, useDebounceFn } from "@vueuse/core";
import * as echarts from "echarts/core";
import { PieChart } from "echarts/charts";
import {
  TooltipComponent,
  LegendComponent,
  TitleComponent
} from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";
import type { EChartsCoreOption } from "echarts/core";

// 注册ECharts组件
echarts.use([
  PieChart,
  TooltipComponent,
  LegendComponent,
  TitleComponent,
  CanvasRenderer
]);

interface PieDataItem {
  name: string;
  value: number;
  itemStyle?: {
    color?: string;
  };
}

interface Props {
  title?: string;
  data: PieDataItem[];
  height?: string;
  colors?: string[];
  loading?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  title: "",
  height: "300px",
  colors: () => ["#3B82F6", "#10B981", "#F59E0B", "#EF4444", "#8B5CF6"],
  loading: false
});

const chartRef = ref<HTMLDivElement>();
let chartInstance: echarts.ECharts | null = null;

const initChart = () => {
  if (!chartRef.value) return;

  chartInstance = echarts.init(chartRef.value);
  updateChart();
};

const updateChart = () => {
  if (!chartInstance || !props.data.length) return;

  const option: EChartsCoreOption = {
    title: {
      text: props.title,
      left: "center",
      top: 10,
      textStyle: {
        fontSize: 14,
        fontWeight: "normal",
        color: "#374151"
      }
    },
    tooltip: {
      trigger: "item",
      formatter: "{a} <br/>{b}: {c} ({d}%)"
    },
    legend: {
      type: "scroll",
      orient: "horizontal",
      bottom: 5,
      left: "center",
      itemWidth: 12,
      itemHeight: 12,
      itemGap: 8,
      textStyle: {
        fontSize: 11,
        color: "#6B7280"
      },
      pageIconSize: 10,
      pageTextStyle: {
        fontSize: 10
      }
    },
    series: [
      {
        name: props.title || "数据",
        type: "pie",
        radius: ["30%", "60%"],
        center: ["50%", "45%"],
        avoidLabelOverlap: true,
        itemStyle: {
          borderRadius: 4,
          borderColor: "#fff",
          borderWidth: 1
        },
        label: {
          show: false
        },
        emphasis: {
          label: {
            show: true,
            fontSize: 14,
            fontWeight: "bold",
            position: "center"
          },
          itemStyle: {
            shadowBlur: 10,
            shadowOffsetX: 0,
            shadowColor: "rgba(0, 0, 0, 0.5)"
          }
        },
        labelLine: {
          show: false
        },
        data: props.data.map((item, index) => ({
          ...item,
          itemStyle: {
            color:
              item.itemStyle?.color || props.colors[index % props.colors.length]
          }
        }))
      }
    ]
  };

  chartInstance.setOption(option, true);
};

// 防抖 resize，避免窗口/容器频繁变化时高频重排
const resizeChart = useDebounceFn(() => {
  chartInstance?.resize();
}, 120);

onMounted(() => {
  initChart();
  // 监听容器尺寸变化（含侧边栏折叠等布局驱动的 resize），自动随组件销毁清理
  useResizeObserver(chartRef, resizeChart);
});

onBeforeUnmount(() => {
  if (chartInstance) {
    chartInstance.dispose();
    chartInstance = null;
  }
});

// 监听数据变化
watch(
  () => props.data,
  () => {
    updateChart();
  },
  { deep: true }
);

// 监听title变化
watch(
  () => props.title,
  () => {
    updateChart();
  }
);
</script>

<template>
  <div :style="{ height: height, width: '100%' }" class="relative">
    <div ref="chartRef" :style="{ height: height, width: '100%' }" />
    <div
      v-if="loading"
      class="absolute inset-0 flex items-center justify-center bg-white/80 dark:bg-[#141414]/80"
    >
      <div class="text-gray-500 dark:text-gray-400">图表加载中...</div>
    </div>
  </div>
</template>

<style scoped>
/* 图表容器样式 */
</style>
