<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from "vue";
import { useResizeObserver, useDebounceFn } from "@vueuse/core";
import * as echarts from "echarts/core";
import { LineChart } from "echarts/charts";
import {
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  TitleComponent
} from "echarts/components";
import { CanvasRenderer } from "echarts/renderers";
import type { EChartsCoreOption } from "echarts/core";

// 注册ECharts组件
echarts.use([
  LineChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  DataZoomComponent,
  TitleComponent,
  CanvasRenderer
]);

interface SeriesData {
  name: string;
  data: number[];
  color?: string;
  lineWidth?: number;
  yAxisIndex?: number;
}

interface Props {
  title?: string;
  xAxisData: string[];
  series: SeriesData[];
  height?: string;
  smooth?: boolean;
  showDataZoom?: boolean;
  yAxisConfig?: Array<{
    name: string;
    position: "left" | "right";
    type?: "value" | "category";
  }>;
}

const props = withDefaults(defineProps<Props>(), {
  title: "",
  height: "320px",
  smooth: true,
  showDataZoom: false,
  yAxisConfig: () => [{ name: "", position: "left" }]
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

  // 首帧数据未就绪时父组件可能传入空 yAxisConfig（如 Dashboard 异步加载）。
  // echarts 在「有 xAxis 但 yAxis 为空数组」时会抛 axis.getAxesOnZeroOf is not
  // a function，故空数组回退到单个默认 Y 轴，保证坐标系完整。
  const yAxisConfig = props.yAxisConfig.length
    ? props.yAxisConfig
    : [{ name: "", position: "left" as const }];

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
        type: "cross",
        label: {
          backgroundColor: "#6a7985"
        }
      }
    },
    legend: {
      data: props.series.map(s => s.name),
      bottom: 10,
      textStyle: {
        color: "#6B7280"
      }
    },
    grid: {
      left: "3%",
      right: "4%",
      bottom: props.showDataZoom ? "28%" : "15%",
      top: props.title ? "15%" : "10%",
      containLabel: true
    },
    xAxis: {
      type: "category",
      boundaryGap: false,
      data: props.xAxisData,
      axisLabel: {
        color: "#6B7280"
      },
      axisLine: {
        lineStyle: {
          color: "#E5E7EB"
        }
      }
    },
    yAxis: yAxisConfig.map((config, index) => ({
      type: config.type || "value",
      position: config.position,
      name: config.name,
      nameTextStyle: {
        color: "#6B7280"
      },
      axisLabel: {
        color: "#6B7280"
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
    })),
    dataZoom: props.showDataZoom
      ? [
          {
            type: "inside",
            start: 70,
            end: 100
          },
          {
            start: 70,
            end: 100,
            height: 30,
            bottom: 50
          }
        ]
      : undefined,
    series: props.series.map((seriesItem, index) => ({
      name: seriesItem.name,
      type: "line",
      smooth: props.smooth,
      data: seriesItem.data,
      yAxisIndex: seriesItem.yAxisIndex || 0,
      lineStyle: {
        color: seriesItem.color || defaultColors[index % defaultColors.length],
        width: seriesItem.lineWidth ?? 2
      },
      itemStyle: {
        color: seriesItem.color || defaultColors[index % defaultColors.length]
      },
      areaStyle: {
        opacity: 0.1,
        color: seriesItem.color || defaultColors[index % defaultColors.length]
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
