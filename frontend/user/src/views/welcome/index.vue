<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from "vue";
import { getConfig } from "@/config";
import { getProfile } from "@/api/auth";
import { getAssetsData, getOrdersData, getTrendData } from "@/api/dashboard";
import PieChart from "@shared/components/Charts/PieChart.vue";
import LineChart from "@shared/components/Charts/LineChart.vue";
import { getPluginWidgets } from "@shared/utils/plugin-loader";
import {
  defaultQrcodePath,
  resolveSiteQrcode,
  resolveSiteQrcodeAfterError
} from "@shared/utils";
import { useLazyVisible } from "@shared/hooks";
import { brandLabels } from "@/views/system/dictionary";
import { topUpDialogStore } from "@/store/modules/topUp";
import type {
  AssetsData,
  OrdersData,
  TrendDataPoint,
  TrendPeriod
} from "@/types/dashboard";

defineOptions({
  name: "Dashboard"
});

// 插件 widget
const dashboardTopWidgets = getPluginWidgets("user-dashboard-top");

// 用户信息
const userInfo = ref();
// 首屏加载状态：仅等待用户信息 + 首批关键数据
const loading = ref(true);

// Dashboard数据
const assetsData = ref<AssetsData>();
const ordersData = ref<OrdersData>();
const trendData = ref<TrendDataPoint[]>([]);
const trendPeriod = ref<TrendPeriod>("month");

// 次批（图表）加载状态：与首批卡片解耦，进入视口后才触发
const chartsLoading = ref(true);

// 图表区域哨兵元素：进入视口才加载二屏图表（懒加载由 useLazyVisible 统一处理）
const chartsSentinel = ref<HTMLElement>();

// 二维码放大模态框
const showQRModal = ref(false);
const configuredQrcode = getConfig("Qrcode");
const qrcodeUrl = ref(
  resolveSiteQrcode(
    configuredQrcode,
    defaultQrcodePath(import.meta.env.BASE_URL)
  )
);
const handleQrcodeLoadError = () => {
  qrcodeUrl.value = resolveSiteQrcodeAfterError(
    configuredQrcode,
    qrcodeUrl.value,
    defaultQrcodePath(import.meta.env.BASE_URL, "png")
  );
};

// 格式化金额
const formatCurrency = (amount: number): string => {
  return new Intl.NumberFormat("zh-CN", {
    style: "currency",
    currency: "CNY",
    minimumFractionDigits: 2
  }).format(amount);
};

// 打开二维码放大模态框
const openQRModal = () => {
  showQRModal.value = true;
};

// 关闭二维码放大模态框
const closeQRModal = () => {
  showQRModal.value = false;
};

// 键盘事件处理
const handleKeydown = (event: KeyboardEvent) => {
  if (event.key === "Escape" && showQRModal.value) {
    closeQRModal();
  }
};

// 订单状态饼图数据
const ordersPieData = computed(() => {
  if (!ordersData.value?.status_distribution) return [];

  const statusNames: Record<string, string> = {
    unpaid: "待支付",
    pending: "待提交",
    processing: "待验证",
    approving: "待审核",
    active: "已签发",
    cancelling: "待取消",
    cancelled: "已取消",
    renewed: "已续期",
    replaced: "已替换",
    reissued: "已重签",
    expired: "已过期",
    revoked: "已吊销",
    failed: "已失败"
  };

  return Object.entries(ordersData.value.status_distribution)
    .map(([status, count]) => ({
      name: statusNames[status] || status,
      value: count,
      itemStyle: {
        color:
          status === "active"
            ? "#10B981"
            : status === "pending"
              ? "#F59E0B"
              : status === "processing"
                ? "#3B82F6"
                : status === "approving"
                  ? "#8B5CF6"
                  : status === "failed"
                    ? "#EF4444"
                    : status === "cancelled"
                      ? "#9CA3AF"
                      : status === "unpaid"
                        ? "#F97316"
                        : status === "expired"
                          ? "#6B7280"
                          : status === "reissued"
                            ? "#06B6D4"
                            : status === "renewed"
                              ? "#84CC16"
                              : status === "revoked"
                                ? "#DC2626"
                                : status === "replaced"
                                  ? "#7C3AED"
                                  : status === "cancelling"
                                    ? "#D97706"
                                    : "#6B7280"
      }
    }))
    .filter(item => item.value > 0);
});

// 订单品牌排行数据：少量订单也能直接看清数量与占比
const brandDistributionData = computed(() => {
  const entries = Object.entries(ordersData.value?.brand_distribution || {})
    .filter(([, count]) => count > 0)
    .sort(([, countA], [, countB]) => countB - countA);
  const visibleEntries = entries.slice(0, 7);
  if (entries.length > 7) {
    visibleEntries.push([
      "other",
      entries.slice(7).reduce((sum, [, count]) => sum + count, 0)
    ]);
  }
  const total = visibleEntries.reduce((sum, [, count]) => sum + count, 0);
  const colors = [
    "#3B82F6",
    "#10B981",
    "#F59E0B",
    "#8B5CF6",
    "#06B6D4",
    "#EC4899",
    "#84CC16",
    "#94A3B8"
  ];

  return visibleEntries.map(([brand, count], index) => ({
    name: brand === "other" ? "其他" : brandLabels[brand] || brand,
    count,
    percentage: total > 0 ? (count / total) * 100 : 0,
    color: colors[index % colors.length]
  }));
});

const trendPeriodOptions: Array<{ label: string; value: TrendPeriod }> = [
  { label: "月", value: "month" },
  { label: "季", value: "quarter" },
  { label: "年", value: "year" }
];

// 趋势图数据
const trendChartData = computed(() => {
  if (!trendData.value.length)
    return { xAxisData: [], series: [], yAxisConfig: [] };

  return {
    xAxisData: trendData.value.map(item => {
      const [year, month, day] = item.date.split("-");
      return trendPeriod.value === "year"
        ? `${year.slice(2)}-${Number(month)}`
        : `${Number(month)}/${Number(day)}`;
    }),
    series: [
      {
        name: "订单",
        data: trendData.value.map(item => item.net_orders),
        color: "#3B82F6",
        lineWidth: 3,
        yAxisIndex: 0
      },
      {
        name: "消费",
        data: trendData.value.map(item => item.consumption),
        color: "#10B981",
        lineWidth: 3,
        yAxisIndex: 1
      }
    ],
    yAxisConfig: [
      { name: "订单数量", position: "left" as const },
      { name: "消费金额", position: "right" as const }
    ]
  };
});

// 获取用户信息
const fetchUserInfo = async () => {
  try {
    const res = await getProfile();
    userInfo.value = res.data;
  } catch (error) {
    console.error("获取用户信息失败:", error);
  }
};

// 首批：关键指标卡片（资产 / 订单概览），进页立即加载并渲染
const fetchOverviewData = async () => {
  // 各接口独立结算，单个失败不连累其它卡片
  const [assetsRes, ordersRes] = await Promise.allSettled([
    getAssetsData(),
    getOrdersData()
  ]);

  if (assetsRes.status === "fulfilled") {
    assetsData.value = assetsRes.value.data;
  } else {
    console.error("获取资产数据失败:", assetsRes.reason);
  }
  if (ordersRes.status === "fulfilled") {
    ordersData.value = ordersRes.value.data;
  } else {
    console.error("获取订单数据失败:", ordersRes.reason);
  }
};

// 次批：趋势图，二屏内容延后加载
// 注：状态/品牌分布复用首批 ordersData，无需重复请求
let latestTrendRequestId = 0;

const fetchChartsData = async () => {
  const requestId = ++latestTrendRequestId;
  const period = trendPeriod.value;

  try {
    chartsLoading.value = true;
    const res = await getTrendData(period);
    if (requestId !== latestTrendRequestId) return;
    trendData.value = res.data;
  } catch (error) {
    if (requestId !== latestTrendRequestId) return;
    trendData.value = [];
    console.error("获取趋势数据失败:", error);
  } finally {
    if (requestId === latestTrendRequestId) {
      chartsLoading.value = false;
    }
  }
};

const handleTrendPeriodChange = async (period: TrendPeriod) => {
  if (period === trendPeriod.value) return;
  trendPeriod.value = period;
  await fetchChartsData();
};

onMounted(async () => {
  loading.value = true;
  // 首屏仅等待用户信息 + 首批关键卡片数据
  await Promise.all([fetchUserInfo(), fetchOverviewData()]);
  loading.value = false;

  // 添加键盘事件监听
  document.addEventListener("keydown", handleKeydown);
});

onUnmounted(() => {
  latestTrendRequestId++;
  // 移除键盘事件监听
  document.removeEventListener("keydown", handleKeydown);
});

// 图表区域哨兵（v-else 分支挂载后）进入视口（提前 200px 预加载）即触发次批加载，仅首次有效
useLazyVisible(chartsSentinel, fetchChartsData);
</script>

<template>
  <div>
    <!-- 加载状态 -->
    <div v-if="loading" class="flex items-center justify-center h-64">
      <div class="text-gray-500 dark:text-gray-400">数据加载中...</div>
    </div>

    <!-- Dashboard内容 -->
    <div v-else class="space-y-6">
      <!-- 插件 widget 插槽 -->
      <component
        :is="w.component"
        v-for="w in dashboardTopWidgets"
        :key="w.name"
      />

      <!-- 欢迎信息 -->
      <div class="bg-white dark:bg-[#141414] rounded-lg p-0">
        <div class="flex items-center justify-between">
          <div class="m-5">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
              欢迎回来，{{ userInfo.username }}
            </h1>
            <p class="text-gray-600 dark:text-gray-400 mt-1">
              这是您的账户概览
            </p>
          </div>
          <div class="flex-shrink-0 m-2">
            <img
              :src="qrcodeUrl"
              alt="二维码"
              class="w-24 h-24 rounded-sm block cursor-pointer hover:opacity-80! transition-opacity! duration-200!"
              title="点击放大"
              @error="handleQrcodeLoadError"
              @click="openQRModal"
            />
          </div>
        </div>
      </div>

      <!-- 资产概览卡片 -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- 账户余额 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div class="flex flex-col justify-center">
              <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                账户余额
              </p>
              <p
                class="text-2xl font-bold cursor-pointer transition-colors"
                :class="
                  (assetsData?.balance || 0) < 0
                    ? 'text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300'
                    : 'text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400'
                "
                title="点击充值"
                @click="topUpDialogStore().showDialog"
              >
                {{ formatCurrency(assetsData?.balance || 0) }}
              </p>
            </div>
            <div class="p-3 bg-indigo-100 dark:bg-indigo-900 rounded-full">
              <svg
                class="w-6 h-6 text-indigo-600 dark:text-indigo-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-2m0-6h2a2 2 0 012 2v2a2 2 0 01-2 2h-2V9zm0 0h2"
                />
              </svg>
            </div>
          </div>
        </div>

        <!-- 7/30天到期数 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div class="flex flex-col justify-center">
              <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                7/30天到期数
              </p>
              <p class="text-2xl font-bold text-gray-900 dark:text-white">
                <span>{{ ordersData?.expiring_7_days || 0 }}</span>
                /
                <span>{{ ordersData?.expiring_30_days || 0 }}</span>
              </p>
            </div>
            <div class="p-3 bg-yellow-100 dark:bg-yellow-900 rounded-full">
              <svg
                class="w-6 h-6 text-yellow-600 dark:text-yellow-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                />
              </svg>
            </div>
          </div>
        </div>

        <!-- 处理中订单数 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div class="flex flex-col justify-center">
              <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                处理中订单数
              </p>
              <p class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ ordersData?.processing_orders || 0 }}
              </p>
              <p class="text-xs text-gray-500 dark:text-gray-400">
                待支付 · 待提交 · 待验证 · 审核中
              </p>
            </div>
            <div class="p-3 bg-blue-100 dark:bg-blue-900 rounded-full">
              <svg
                class="w-6 h-6 text-blue-600 dark:text-blue-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="3"
                  d="M6 12l4 4 8-8"
                />
              </svg>
            </div>
          </div>
        </div>

        <!-- 有效/总订单数 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div class="flex flex-col justify-center">
              <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                有效/总订单数
              </p>
              <p class="text-2xl font-bold text-gray-900 dark:text-white">
                <span>{{ ordersData?.active_orders || 0 }}</span>
                /
                <span>{{ ordersData?.order_count || 0 }}</span>
              </p>
            </div>
            <div class="p-3 bg-green-100 dark:bg-green-900 rounded-full">
              <svg
                class="w-6 h-6 text-green-600 dark:text-green-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                />
              </svg>
            </div>
          </div>
        </div>
      </div>

      <!-- 分布图 -->
      <div ref="chartsSentinel" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- 订单状态分布 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
              订单状态分布
            </h3>
          </div>
          <div class="h-80">
            <PieChart
              v-if="ordersPieData.length"
              :data="ordersPieData"
              title="订单状态"
            />
            <div
              v-else
              class="flex items-center justify-center h-full text-gray-500 dark:text-gray-400"
            >
              暂无订单状态数据
            </div>
          </div>
        </div>

        <!-- 订单品牌分布 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
              订单品牌分布
            </h3>
          </div>
          <div class="h-80">
            <div
              v-if="brandDistributionData.length"
              class="h-full flex flex-col justify-center gap-4 px-2"
            >
              <div
                v-for="brand in brandDistributionData"
                :key="brand.name"
                class="space-y-1.5"
              >
                <div
                  class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-300"
                >
                  <span class="truncate pr-4">{{ brand.name }}</span>
                  <span class="shrink-0">
                    {{ brand.count }} 单 · {{ brand.percentage.toFixed(1) }}%
                  </span>
                </div>
                <div
                  class="h-3 rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden"
                >
                  <div
                    class="h-full rounded-full transition-all duration-300"
                    :style="{
                      width: `${brand.percentage}%`,
                      backgroundColor: brand.color
                    }"
                  />
                </div>
              </div>
            </div>
            <div
              v-else
              class="flex items-center justify-center h-full text-gray-500 dark:text-gray-400"
            >
              暂无订单品牌数据
            </div>
          </div>
        </div>
      </div>

      <!-- 订单和消费趋势 -->
      <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-2">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
              订单和消费趋势
            </h3>
            <div class="flex gap-1">
              <span
                v-for="option in trendPeriodOptions"
                :key="option.value"
                class="text-xs px-1.5 py-0.5 rounded cursor-pointer transition-colors"
                :class="
                  trendPeriod === option.value
                    ? 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400'
                    : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'
                "
                @click="handleTrendPeriodChange(option.value)"
              >
                {{ option.label }}
              </span>
            </div>
          </div>
        </div>
        <div class="h-80">
          <div
            v-if="chartsLoading"
            class="flex items-center justify-center h-full text-gray-500 dark:text-gray-400"
          >
            图表加载中...
          </div>
          <LineChart
            v-else
            :x-axis-data="trendChartData.xAxisData"
            :series="trendChartData.series"
            :y-axis-config="trendChartData.yAxisConfig"
            height="320px"
            title="订单和消费"
          />
        </div>
      </div>
    </div>

    <!-- 二维码放大模态框 -->
    <div
      v-if="showQRModal"
      class="fixed inset-0 bg-black/50 flex items-center justify-center z-50!"
      @click="closeQRModal"
    >
      <div class="bg-white dark:bg-gray-800 rounded-lg p-6 max-w-md mx-4!">
        <div class="text-center mb-4!">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
            添加客服微信
          </h3>
        </div>
        <div class="flex justify-center">
          <img
            :src="qrcodeUrl"
            alt="二维码"
            class="w-64 h-64 rounded-lg"
            @error="handleQrcodeLoadError"
            @click.stop
          />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* 使用Tailwind CSS，无需自定义样式 */
</style>
