<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from "vue";
import { useRouter } from "vue-router";
import { getConfig } from "@/config";
import { getProfile } from "@/api/auth";
import {
  getAssetsData,
  getOrdersData,
  getTrendData,
  getMonthlyComparison
} from "@/api/dashboard";
import PieChart from "@shared/components/Charts/PieChart.vue";
import LineChart from "@shared/components/Charts/LineChart.vue";
import BarChart from "@shared/components/Charts/BarChart.vue";
import { getPluginWidgets } from "@shared/utils/plugin-loader";
import { defaultQrcodePath, resolveSiteQrcode } from "@shared/utils";
import { useLazyVisible } from "@shared/hooks";
import type {
  AssetsData,
  OrdersData,
  TrendDataPoint,
  MonthlyComparisonData
} from "@/types/dashboard";

defineOptions({
  name: "Dashboard"
});

const router = useRouter();

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
const monthlyComparison = ref<MonthlyComparisonData>();

// 次批（图表）加载状态：与首批卡片解耦，进入视口后才触发
const chartsLoading = ref(true);

// 图表区域哨兵元素：进入视口才加载二屏图表（懒加载由 useLazyVisible 统一处理）
const chartsSentinel = ref<HTMLElement>();

// 二维码放大模态框
const showQRModal = ref(false);
const qrcodeUrl = computed(() =>
  resolveSiteQrcode(
    getConfig("Qrcode"),
    defaultQrcodePath(import.meta.env.BASE_URL)
  )
);

// 格式化金额
const formatCurrency = (amount: number): string => {
  return new Intl.NumberFormat("zh-CN", {
    style: "currency",
    currency: "CNY",
    minimumFractionDigits: 2
  }).format(amount);
};

// 格式化增长率
const formatGrowthRate = (rate: number): string => {
  const prefix = rate > 0 ? "+" : "";
  return `${prefix}${rate.toFixed(1)}%`;
};

// 格式化本地日期为YYYY-MM-DD格式（考虑时区）
const formatLocalDate = (date: Date): string => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
};

// 跳转到订单列表页面的功能
const goToOrderList = (filter: {
  status?: string;
  statusSet?: string;
  expires_at?: [string, string];
  created_at?: [string, string];
}) => {
  router.push({
    path: "/order",
    query: filter
  });
};

// 处理点击7天内过期
const handleClickExpiring7Days = () => {
  const now = new Date();
  const sixDaysLater = new Date(now.getTime() + 6 * 24 * 60 * 60 * 1000);
  goToOrderList({
    expires_at: [formatLocalDate(now), formatLocalDate(sixDaysLater)]
  });
};

// 处理点击30天内过期
const handleClickExpiring30Days = () => {
  const now = new Date();
  const twentyNineDaysLater = new Date(
    now.getTime() + 29 * 24 * 60 * 60 * 1000
  );
  goToOrderList({
    expires_at: [formatLocalDate(now), formatLocalDate(twentyNineDaysLater)]
  });
};

// 处理点击待验证订单
const handleClickProcessingOrders = () => {
  goToOrderList({ status: "processing" });
};

// 处理点击有效订单
const handleClickActiveOrders = () => {
  goToOrderList({ status: "active" });
};

// 处理点击总订单
const handleClickTotalOrders = () => {
  goToOrderList({ statusSet: "all" });
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

// 趋势图数据
const trendChartData = computed(() => {
  if (!trendData.value.length)
    return { xAxisData: [], series: [], yAxisConfig: [] };

  return {
    xAxisData: trendData.value.map(item => {
      const date = new Date(item.date);
      return `${date.getMonth() + 1}/${date.getDate()}`;
    }),
    series: [
      {
        name: "订单交易",
        data: trendData.value.map(item => item.orders),
        color: "#3B82F6",
        yAxisIndex: 0
      },
      {
        name: "取消交易",
        data: trendData.value.map(item => item.cancelled_orders),
        color: "#F97316",
        yAxisIndex: 0
      },
      {
        name: "净增订单",
        data: trendData.value.map(item => item.net_orders),
        color: "#8B5CF6",
        yAxisIndex: 0
      },
      {
        name: "消费金额",
        data: trendData.value.map(item => item.consumption),
        color: "#10B981",
        yAxisIndex: 1
      }
    ],
    yAxisConfig: [
      { name: "订单数量", position: "left" as const },
      { name: "消费金额", position: "right" as const }
    ]
  };
});

// 月度对比图数据
const monthlyComparisonChartData = computed(() => {
  if (!monthlyComparison.value) return { xAxisData: [], series: [] };

  const data = monthlyComparison.value;

  return {
    xAxisData: ["订单", "取消", "净增", "消费金额"],
    series: [
      {
        name: "上月",
        data: [
          data.last_month.orders,
          data.last_month.cancelled_orders,
          data.last_month.net_orders,
          data.last_month.consumption
        ],
        color: "#9CA3AF"
      },
      {
        name: "本月",
        data: [
          data.current_month.orders,
          data.current_month.cancelled_orders,
          data.current_month.net_orders,
          data.current_month.consumption
        ],
        color: "#3B82F6"
      }
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

// 次批：图表（趋势 / 月度对比），二屏内容延后加载
// 注：订单状态分布饼图复用首批 ordersData，无需在此重复请求
const fetchChartsData = async () => {
  try {
    chartsLoading.value = true;

    const [trendRes, comparisonRes] = await Promise.allSettled([
      getTrendData(30),
      getMonthlyComparison()
    ]);

    if (trendRes.status === "fulfilled") {
      trendData.value = trendRes.value.data;
    } else {
      console.error("获取趋势数据失败:", trendRes.reason);
    }
    if (comparisonRes.status === "fulfilled") {
      monthlyComparison.value = comparisonRes.value.data;
    } else {
      console.error("获取月度对比数据失败:", comparisonRes.reason);
    }
  } finally {
    chartsLoading.value = false;
  }
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
  // 移除键盘事件监听
  document.removeEventListener("keydown", handleKeydown);
});

// 图表区域哨兵（v-else 分支挂载后）进入视口（提前 200px 预加载）即触发次批加载，仅首次有效
useLazyVisible(chartsSentinel, fetchChartsData);
</script>

<template>
  <div class="min-h-screen">
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
              @click="openQRModal"
            />
          </div>
        </div>
      </div>

      <!-- 资产概览卡片 -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- 7/30天到期数 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div class="flex flex-col justify-center">
              <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                7/30天到期数
              </p>
              <p class="text-2xl font-bold text-gray-900 dark:text-white">
                <span
                  class="hover:text-yellow-600 cursor-pointer transition-colors duration-200"
                  title="点击查看7天内到期的订单"
                  @click="handleClickExpiring7Days"
                >
                  {{ ordersData?.expiring_7_days || 0 }}
                </span>
                /
                <span
                  class="hover:text-yellow-600 cursor-pointer transition-colors duration-200"
                  title="点击查看30天内到期的订单"
                  @click="handleClickExpiring30Days"
                >
                  {{ ordersData?.expiring_30_days || 0 }}
                </span>
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

        <!-- 待验证订单数 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div class="flex flex-col justify-center">
              <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                待验证订单数
              </p>
              <p
                class="text-2xl font-bold text-gray-900 dark:text-white hover:text-blue-600 cursor-pointer transition-colors duration-200"
                title="点击查看待验证的订单"
                @click="handleClickProcessingOrders"
              >
                {{ ordersData?.status_distribution?.processing || 0 }}
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

        <!-- 有效订单数 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div class="flex flex-col justify-center">
              <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                有效订单数
              </p>
              <p
                class="text-2xl font-bold text-gray-900 dark:text-white hover:text-green-600 cursor-pointer transition-colors duration-200"
                title="点击查看有效的订单"
                @click="handleClickActiveOrders"
              >
                {{ ordersData?.active_orders || 0 }}
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

        <!-- 交易流水订单统计 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div class="flex flex-col justify-center">
              <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                净增/总订单数
              </p>
              <p
                class="text-2xl font-bold text-gray-900 dark:text-white hover:text-orange-600 cursor-pointer transition-colors duration-200"
                title="点击查看所有订单"
                @click="handleClickTotalOrders"
              >
                {{ ordersData?.net_orders || 0 }} /
                {{ ordersData?.total_orders || 0 }}
              </p>
              <p class="text-xs text-gray-500 dark:text-gray-400">
                订单 +{{ ordersData?.total_orders || 0 }} · 取消 -{{
                  ordersData?.cancelled_orders || 0
                }}
              </p>
            </div>
            <div class="p-3 bg-orange-100 dark:bg-orange-900 rounded-full">
              <svg
                class="w-6 h-6 text-orange-600 dark:text-orange-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"
                />
              </svg>
            </div>
          </div>
        </div>
      </div>

      <!-- 图表区域（chartsSentinel 标记次批触发点，进入视口即加载图表） -->
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
              :data="ordersPieData"
              :loading="chartsLoading"
              title="订单状态"
            />
          </div>
        </div>

        <!-- 最近趋势 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
              最近30天趋势
            </h3>
          </div>
          <div class="h-80">
            <LineChart
              :x-axis-data="trendChartData.xAxisData"
              :series="trendChartData.series"
              :y-axis-config="trendChartData.yAxisConfig"
              :loading="chartsLoading"
              height="320px"
              title="订单和消费趋势"
            />
          </div>
        </div>
      </div>

      <!-- 月度对比 -->
      <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
            月度对比
          </h3>
        </div>
        <div class="h-80">
          <BarChart
            :x-axis-data="monthlyComparisonChartData.xAxisData"
            :series="monthlyComparisonChartData.series"
            :loading="chartsLoading"
            height="320px"
            title="本月与上月对比"
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
