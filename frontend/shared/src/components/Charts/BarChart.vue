<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from "vue";
import { useResizeObserver, useDebounceFn } from "@vueuse/core";
import * as echarts from "echarts/core";
import { BarChart } from "echarts/charts";
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  TitleComponent
} from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";
import type { EChartsCoreOption } from "echarts/core";

// 注册ECharts组件
echarts.use([
  BarChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  TitleComponent,
  CanvasRenderer
]);

interface SeriesData {
  name: string;
  data: number[];
  color?: string;
  type?: "bar";
}

interface Props {
  title?: string;
  xAxisData: string[];
  series: SeriesData[];
  height?: string;
  horizontal?: boolean;
  showLegend?: boolean;
  barWidth?: string | number;
}

const props = withDefaults(defineProps<Props>(), {
  title: "",
  height: "350px",
  horizontal: false,
  showLegend: true,
  barWidth: "60%"
});

const chartRef = ref<HTMLDivElement>();
let chartInstance: echarts.ECharts | null = null;

const defaultColors = [
  "#3B82F6",
  "#10B981",
  "#F59E0B",
  "#EF4444",
  "#8B5CF6",
  "#EC4899"
];

const initChart = () => {
  if (!chartRef.value) return;

  chartInstance = echarts.init(chartRef.value);
  updateChart();
};

const updateChart = () => {
  if (!chartInstance) return;

  const option: EChartsCoreOption = {
    title: {
      text: props.title,
      left: "center",
      textStyle: {
        fontSize: 16,
        fontWeight: "normal",
        color: "#374151"
      }
    },
    tooltip: {
      trigger: "axis",
      axisPointer: {
        type: "shadow"
      },
      confine: true,
      textStyle: {
        fontSize: 12
      }
    },
    legend: props.showLegend
      ? {
          data: props.series.map(s => s.name),
          bottom: 10,
          textStyle: {
            color: "#6B7280"
          }
        }
      : undefined,
    grid: {
      left: props.horizontal ? 200 : "3%",
      right: "4%",
      bottom: props.showLegend ? "15%" : "10%",
      top: props.title ? "15%" : "10%",
      containLabel: false
    },
    xAxis: {
      type: props.horizontal ? "value" : "category",
      data: props.horizontal ? undefined : props.xAxisData,
      axisLabel: {
        color: "#6B7280"
      },
      axisLine: {
        lineStyle: {
          color: "#E5E7EB"
        }
      }
    },
    yAxis: {
      type: props.horizontal ? "category" : "value",
      data: props.horizontal ? props.xAxisData : undefined,
      axisLabel: {
        color: "#6B7280",
        width: props.horizontal ? 190 : undefined,
        overflow: props.horizontal ? "truncate" : undefined,
        fontSize: 11,
        lineHeight: 14,
        interval: 0,
        align: props.horizontal ? "right" : undefined,
        verticalAlign: props.horizontal ? "middle" : undefined,
        padding: props.horizontal ? [0, 5, 0, 0] : undefined
      },
      axisLine: {
        lineStyle: {
          color: "#E5E7EB"
        }
      },
      splitLine: {
        lineStyle: {
          color: "#F3F4F6"
        }
      }
    },
    series: props.series.map((seriesItem, index) => ({
      name: seriesItem.name,
      type: "bar",
      data: seriesItem.data,
      barWidth: props.barWidth,
      itemStyle: {
        color: seriesItem.color || defaultColors[index % defaultColors.length],
        borderRadius: props.horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]
      },
      emphasis: {
        itemStyle: {
          shadowBlur: 10,
          shadowOffsetX: 0,
          shadowColor: "rgba(0, 0, 0, 0.3)"
        }
      }
    }))
  };

  // notMerge=true：与 PieChart 一致，series 数量变化时不残留旧系列/图例
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

// 监听数据变化：仅增量 setOption 合并更新，不重建实例
watch(
  () => [props.xAxisData, props.series],
  () => {
    updateChart();
  },
  { deep: true }
);
</script>

<template>
  <div ref="chartRef" :style="{ height: height, width: '100%' }" />
</template>

<style scoped>
/* 图表容器样式 */
</style>
