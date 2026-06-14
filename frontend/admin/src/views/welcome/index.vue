<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { ElProgress, ElTag, ElButton } from "element-plus";
import { Refresh } from "@element-plus/icons-vue";
import { useRouter } from "vue-router";
import { getProfile } from "@/api/auth";
import {
  getSystemOverview,
  getRealtimeData,
  getTrendsData,
  getTopProducts,
  getBrandStats,
  getUserLevelDistribution,
  getFinanceOverview,
  clearDashboardCache
} from "@/api/dashboard";
import PieChart from "@shared/components/Charts/PieChart.vue";
import LineChart from "@shared/components/Charts/LineChart.vue";
import BarChart from "@shared/components/Charts/BarChart.vue";
import type {
  SystemOverviewData,
  RealtimeData,
  TrendDataPoint,
  TopProduct,
  BrandStats,
  UserLevelDistribution,
  FinanceOverviewData
} from "@/types/dashboard";
import { message } from "@shared/utils";
import { useLazyVisible } from "@shared/hooks";
import { brandLabels } from "@/views/system/dictionary";

defineOptions({
  name: "AdminDashboard"
});

const router = useRouter();

// 管理员信息
const adminInfo = ref();
// 首屏加载状态：仅等待管理员信息 + 首批关键数据，决定页面骨架是否显示
const loading = ref(true);

// Dashboard数据
const systemOverview = ref<SystemOverviewData>();
const realtimeData = ref<RealtimeData>();
const trendsData = ref<TrendDataPoint[]>([]);
const topProducts = ref<TopProduct[]>([]);
const brandStats = ref<BrandStats[]>([]);
const userLevelDistribution = ref<UserLevelDistribution[]>([]);
const financeOverview = ref<FinanceOverviewData>();

// 产品销售排行和品牌统计的周期切换
const topProductsDays = ref(30);
const brandStatsDays = ref(30);
const daysOptions = [
  { label: "7天", value: 7 },
  { label: "30天", value: 30 },
  { label: "90天", value: 90 }
] as const;
const topProductsLoading = ref(false);
const brandStatsLoading = ref(false);

// 周期切换
type Period = "daily" | "weekly" | "monthly";
const periodLabels = { daily: "日", weekly: "周", monthly: "月" } as const;
const periodCompareLabels = {
  daily: "较昨日",
  weekly: "较上周",
  monthly: "较上月"
} as const;

// 用户卡片周期
const userPeriod = ref<Period>("daily");

// 订单卡片周期
const orderPeriod = ref<Period>("daily");

// 充值卡片周期
const rechargePeriod = ref<Period>("daily");
const rechargeDelta = computed(() => {
  const f = systemOverview.value?.finance?.[rechargePeriod.value];
  return (f?.recharge || 0) - (f?.prev_recharge || 0);
});

// 消费卡片周期
const consumptionPeriod = ref<Period>("daily");
const consumptionDelta = computed(() => {
  const f = systemOverview.value?.finance?.[consumptionPeriod.value];
  return (f?.consumption || 0) - (f?.prev_consumption || 0);
});

// 次批（图表/排行）加载状态：与首批卡片解耦，进入视口后才触发
const chartsLoading = ref(true);
const refreshing = ref(false);

// 图表区域哨兵元素：进入视口才加载二屏图表（懒加载由 useLazyVisible 统一处理）
const chartsSentinel = ref<HTMLElement>();

// 格式化金额
const formatCurrency = (amount: number): string => {
  return new Intl.NumberFormat("zh-CN", {
    style: "currency",
    currency: "CNY",
    minimumFractionDigits: 2
  }).format(amount);
};

// 格式化数字
const formatNumber = (num: number): string => {
  return new Intl.NumberFormat("zh-CN").format(num);
};

// 格式化本地日期为YYYY-MM-DD格式（考虑时区）
const formatLocalDate = (date: Date): string => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
};

// 跳转到用户列表页面
const goToUserList = (filter: Record<string, string>) => {
  router.push({
    path: "/user",
    query: filter
  });
};

// 切换产品排行周期
const handleTopProductsDaysChange = async (days: number) => {
  topProductsDays.value = days;
  topProductsLoading.value = true;
  try {
    const res = await getTopProducts(days, 10);
    topProducts.value = res.data;
  } finally {
    topProductsLoading.value = false;
  }
};

// 切换品牌统计周期
const handleBrandStatsDaysChange = async (days: number) => {
  brandStatsDays.value = days;
  brandStatsLoading.value = true;
  try {
    const res = await getBrandStats(days);
    brandStats.value = res.data;
  } finally {
    brandStatsLoading.value = false;
  }
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

// 处理点击处理中订单
const handleClickProcessingOrders = () => {
  goToOrderList({ status: "processing" });
};

// 处理点击7天内过期
const handleClickExpiring7Days = () => {
  const now = new Date();
  const sixDaysLater = new Date(now.getTime() + 6 * 24 * 60 * 60 * 1000);
  goToOrderList({
    status: "active",
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
    status: "active",
    expires_at: [formatLocalDate(now), formatLocalDate(twentyNineDaysLater)]
  });
};

// 处理点击7天内签发
const handleClickIssued7Days = () => {
  const now = new Date();
  const sevenDaysAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
  goToOrderList({
    status: "active",
    created_at: [formatLocalDate(sevenDaysAgo), formatLocalDate(now)]
  });
};

// 处理点击30天内签发
const handleClickIssued30Days = () => {
  const now = new Date();
  const thirtyDaysAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
  goToOrderList({
    status: "active",
    created_at: [formatLocalDate(thirtyDaysAgo), formatLocalDate(now)]
  });
};

// 系统趋势图数据
const systemTrendsChartData = computed(() => {
  if (!trendsData.value.length) return { xAxisData: [], series: [] };

  return {
    xAxisData: trendsData.value.map(item => {
      const date = new Date(item.date);
      return `${date.getMonth() + 1}/${date.getDate()}`;
    }),
    series: [
      {
        name: "新增用户",
        data: trendsData.value.map(item => item.users),
        color: "#3B82F6"
      },
      {
        name: "新增订单",
        data: trendsData.value.map(item => item.orders),
        color: "#10B981"
      },
      {
        name: "净充值",
        data: trendsData.value.map(item => item.recharge),
        color: "#22C55E",
        yAxisIndex: 1
      },
      {
        name: "净消费",
        data: trendsData.value.map(item => item.consumption),
        color: "#EF4444",
        yAxisIndex: 1
      }
    ]
  };
});

// CA品牌统计饼图数据
const brandStatsChartData = computed(() => {
  if (!brandStats.value.length) return [];

  return brandStats.value.map((brand, index) => ({
    name: brandLabels[brand.brand?.toLowerCase()] || brand.brand,
    value: brand.revenue,
    itemStyle: {
      color: ["#3B82F6", "#10B981", "#F59E0B", "#EF4444", "#8B5CF6", "#EC4899"][
        index % 6
      ]
    }
  }));
});

// 用户等级分布图数据
const userLevelChartData = computed(() => {
  if (!userLevelDistribution.value.length) return [];

  return userLevelDistribution.value.map((level, index) => ({
    name: level.level_name,
    value: level.user_count,
    itemStyle: {
      color: ["#3B82F6", "#10B981", "#F59E0B", "#EF4444", "#8B5CF6"][index % 5]
    }
  }));
});

// 产品销售排行图数据
const topProductsChartData = computed(() => {
  if (!topProducts.value.length) return { xAxisData: [], series: [] };

  return {
    xAxisData: topProducts.value
      .slice(0, 10)
      .map(product => product.product_name || ""),
    series: [
      {
        name: "销售额",
        data: topProducts.value
          .slice(0, 10)
          .map(product => product.sales_amount),
        color: "#3B82F6"
      }
    ]
  };
});

// 获取管理员信息
const fetchAdminInfo = async () => {
  const res = await getProfile();
  adminInfo.value = res.data;
};

// 首批：关键指标卡片（系统概览 / 实时监控 / 财务概览），进页立即加载并渲染
const fetchOverviewData = async () => {
  // 各接口独立结算，单个失败不连累其它卡片
  const [overviewRes, realtimeRes, financeRes] = await Promise.allSettled([
    getSystemOverview(),
    getRealtimeData(),
    getFinanceOverview()
  ]);

  if (overviewRes.status === "fulfilled") {
    systemOverview.value = overviewRes.value.data;
  }
  if (realtimeRes.status === "fulfilled") {
    realtimeData.value = realtimeRes.value.data;
  }
  if (financeRes.status === "fulfilled") {
    financeOverview.value = financeRes.value.data;
  }
};

// 次批：图表/排行（系统趋势 / 产品销售排行 / 品牌分布 / 用户等级分布），二屏内容延后加载
const fetchChartsData = async () => {
  try {
    chartsLoading.value = true;

    const [trendsRes, productsRes, brandsRes, levelsRes] =
      await Promise.allSettled([
        getTrendsData(30),
        getTopProducts(topProductsDays.value, 10),
        getBrandStats(brandStatsDays.value),
        getUserLevelDistribution()
      ]);

    if (trendsRes.status === "fulfilled") {
      trendsData.value = trendsRes.value.data;
    }
    if (productsRes.status === "fulfilled") {
      topProducts.value = productsRes.value.data;
    }
    if (brandsRes.status === "fulfilled") {
      brandStats.value = brandsRes.value.data;
    }
    if (levelsRes.status === "fulfilled") {
      userLevelDistribution.value = levelsRes.value.data;
    }
  } finally {
    chartsLoading.value = false;
  }
};

// 刷新缓存并重新获取数据
const handleRefreshData = async () => {
  try {
    refreshing.value = true;

    // 清除后端缓存
    await clearDashboardCache();

    // 首批与次批并行刷新，互不阻塞
    await Promise.all([fetchOverviewData(), fetchChartsData()]);

    message("数据刷新成功", { type: "success" });
  } finally {
    refreshing.value = false;
  }
};

onMounted(async () => {
  loading.value = true;
  // 首屏仅等待管理员信息 + 首批关键卡片数据
  await Promise.all([fetchAdminInfo(), fetchOverviewData()]);
  loading.value = false;
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
      <!-- 欢迎信息 -->
      <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
        <div class="flex items-center justify-between">
          <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
              管理后台 - 欢迎回来，{{ adminInfo?.username }}
            </h1>
            <p class="text-gray-600 dark:text-gray-400 mt-1">
              系统运营数据总览
            </p>
          </div>
          <div>
            <ElButton
              type="primary"
              :loading="refreshing"
              :icon="refreshing ? undefined : Refresh"
              @click="handleRefreshData"
            >
              {{ refreshing ? "刷新中..." : "刷新数据" }}
            </ElButton>
          </div>
        </div>
      </div>

      <!-- 核心指标卡片 -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- 用户数 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div>
              <div class="flex items-center gap-2">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                  用户数
                </p>
                <div class="flex gap-1">
                  <span
                    v-for="(label, key) in periodLabels"
                    :key="key"
                    class="text-xs px-1.5 py-0.5 rounded cursor-pointer transition-colors"
                    :class="
                      userPeriod === key
                        ? 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400'
                        : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'
                    "
                    @click="userPeriod = key"
                  >
                    {{ label }}
                  </span>
                </div>
              </div>
              <p class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ formatNumber(systemOverview?.monthly?.total_users || 0) }}
              </p>
              <p class="text-xs text-gray-500 dark:text-gray-400">
                新增: +{{ systemOverview?.new_users?.[userPeriod] || 0 }}
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
                  stroke-width="2"
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                />
              </svg>
            </div>
          </div>
        </div>

        <!-- 有效/总 订单数 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div>
              <div class="flex items-center gap-2">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                  有效/总 订单数
                </p>
                <div class="flex gap-1">
                  <span
                    v-for="(label, key) in periodLabels"
                    :key="key"
                    class="text-xs px-1.5 py-0.5 rounded cursor-pointer transition-colors"
                    :class="
                      orderPeriod === key
                        ? 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400'
                        : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'
                    "
                    @click="orderPeriod = key"
                  >
                    {{ label }}
                  </span>
                </div>
              </div>
              <p class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ formatNumber(systemOverview?.monthly?.active_orders || 0) }}
                /
                {{ formatNumber(systemOverview?.monthly?.total_orders || 0) }}
              </p>
              <p class="text-xs text-gray-500 dark:text-gray-400">
                新增: +{{ systemOverview?.new_orders?.[orderPeriod] || 0 }}
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

        <!-- 充值 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div>
              <div class="flex items-center gap-2">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                  净充值
                </p>
                <div class="flex gap-1">
                  <span
                    v-for="(label, key) in periodLabels"
                    :key="key"
                    class="text-xs px-1.5 py-0.5 rounded cursor-pointer transition-colors"
                    :class="
                      rechargePeriod === key
                        ? 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400'
                        : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'
                    "
                    @click="rechargePeriod = key"
                  >
                    {{ label }}
                  </span>
                </div>
              </div>
              <p class="text-2xl font-bold text-gray-900 dark:text-white">
                {{
                  formatCurrency(
                    systemOverview?.finance?.[rechargePeriod]?.recharge || 0
                  )
                }}
              </p>
              <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ periodCompareLabels[rechargePeriod] }}:
                {{ rechargeDelta >= 0 ? "+" : ""
                }}{{ formatCurrency(rechargeDelta) }}
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
                  d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"
                />
              </svg>
            </div>
          </div>
        </div>

        <!-- 消费 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between">
            <div>
              <div class="flex items-center gap-2">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                  净消费
                </p>
                <div class="flex gap-1">
                  <span
                    v-for="(label, key) in periodLabels"
                    :key="key"
                    class="text-xs px-1.5 py-0.5 rounded cursor-pointer transition-colors"
                    :class="
                      consumptionPeriod === key
                        ? 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400'
                        : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'
                    "
                    @click="consumptionPeriod = key"
                  >
                    {{ label }}
                  </span>
                </div>
              </div>
              <p class="text-2xl font-bold text-gray-900 dark:text-white">
                {{
                  formatCurrency(
                    systemOverview?.finance?.[consumptionPeriod]?.consumption ||
                      0
                  )
                }}
              </p>
              <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ periodCompareLabels[consumptionPeriod] }}:
                {{ consumptionDelta >= 0 ? "+" : ""
                }}{{ formatCurrency(consumptionDelta) }}
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
                  d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"
                />
              </svg>
            </div>
          </div>
        </div>
      </div>

      <!-- 实时监控 -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
              实时监控
            </h3>
            <ElTag
              :type="
                realtimeData?.online_users && realtimeData.online_users > 0
                  ? 'success'
                  : 'info'
              "
            >
              在线用户: {{ realtimeData?.online_users || 0 }}
            </ElTag>
          </div>
          <div class="grid grid-cols-3 gap-4">
            <div class="text-center">
              <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                处理中订单
              </p>
              <p
                class="text-2xl font-bold text-gray-900 dark:text-white hover:text-blue-600 cursor-pointer transition-colors duration-200"
                title="点击查看处理中的订单"
                @click="handleClickProcessingOrders"
              >
                {{ realtimeData?.today?.processing_orders || 0 }}
              </p>
            </div>
            <div class="text-center">
              <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                7/30天内过期
              </p>
              <p
                class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"
              >
                <span
                  class="hover:text-yellow-700 cursor-pointer transition-colors duration-200"
                  title="点击查看7天内过期的订单"
                  @click="handleClickExpiring7Days"
                >
                  {{ realtimeData?.alerts?.expiring_7_days || 0 }}
                </span>
                /
                <span
                  class="hover:text-yellow-700 cursor-pointer transition-colors duration-200"
                  title="点击查看30天内过期的订单"
                  @click="handleClickExpiring30Days"
                >
                  {{ realtimeData?.alerts?.expiring_30_days || 0 }}
                </span>
              </p>
            </div>
            <div class="text-center">
              <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                7/30日签发
              </p>
              <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                <span
                  class="hover:text-blue-700 cursor-pointer transition-colors duration-200"
                  title="点击查看7天内签发的订单"
                  @click="handleClickIssued7Days"
                >
                  {{ realtimeData?.alerts?.issued_7_days || 0 }}
                </span>
                /
                <span
                  class="hover:text-blue-700 cursor-pointer transition-colors duration-200"
                  title="点击查看30天内签发的订单"
                  @click="handleClickIssued30Days"
                >
                  {{ realtimeData?.alerts?.issued_30_days || 0 }}
                </span>
              </p>
            </div>
          </div>
        </div>

        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
              财务概览
            </h3>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div class="text-center">
              <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                总余额
              </p>
              <p
                class="text-2xl font-bold text-green-600 dark:text-green-400 hover:text-green-700 cursor-pointer transition-colors duration-200"
                title="点击查看余额大于0的用户"
                @click="goToUserList({ balance: '0.01,' })"
              >
                {{ formatCurrency(financeOverview?.total_balance || 0) }}
              </p>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                {{ financeOverview?.positive_count || 0 }} 个用户
              </p>
            </div>
            <div class="text-center">
              <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                总欠费
              </p>
              <p
                class="text-2xl font-bold text-red-600 dark:text-red-400 hover:text-red-700 cursor-pointer transition-colors duration-200"
                title="点击查看欠费用户"
                @click="goToUserList({ balance: ',-0.01' })"
              >
                {{ formatCurrency(financeOverview?.total_debt || 0) }}
              </p>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                {{ financeOverview?.negative_count || 0 }} 个用户
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- 图表区域（chartsSentinel 标记次批触发点，进入视口即加载图表/排行） -->
      <!-- 系统趋势 -->
      <div
        ref="chartsSentinel"
        class="bg-white dark:bg-[#141414] rounded-lg p-6"
      >
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
            系统趋势（最近30天）
          </h3>
        </div>
        <div v-if="chartsLoading" class="flex items-center justify-center h-80">
          <div class="text-gray-500 dark:text-gray-400">图表加载中...</div>
        </div>
        <div v-else class="h-80">
          <LineChart
            :x-axis-data="systemTrendsChartData.xAxisData"
            :series="systemTrendsChartData.series"
            :y-axis-config="[
              { name: '用户/订单', position: 'left' as const },
              { name: '净充值/净消费', position: 'right' as const }
            ]"
            height="320px"
            :show-data-zoom="true"
          />
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- 产品销售排行 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
              <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                产品销售排行（Top10）
              </h3>
              <div class="flex gap-1">
                <span
                  v-for="opt in daysOptions"
                  :key="opt.value"
                  class="text-xs px-1.5 py-0.5 rounded cursor-pointer transition-colors"
                  :class="
                    topProductsDays === opt.value
                      ? 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400'
                      : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'
                  "
                  @click="handleTopProductsDaysChange(opt.value)"
                >
                  {{ opt.label }}
                </span>
              </div>
            </div>
          </div>
          <div
            v-if="chartsLoading || topProductsLoading"
            class="flex items-center justify-center h-64"
          >
            <div class="text-gray-500 dark:text-gray-400">图表加载中...</div>
          </div>
          <div v-else class="h-80">
            <BarChart
              :x-axis-data="topProductsChartData.xAxisData"
              :series="topProductsChartData.series"
              height="320px"
              horizontal
              :show-legend="false"
            />
          </div>
        </div>

        <!-- CA品牌统计 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
              <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                CA品牌收入分布
              </h3>
              <div class="flex gap-1">
                <span
                  v-for="opt in daysOptions"
                  :key="opt.value"
                  class="text-xs px-1.5 py-0.5 rounded cursor-pointer transition-colors"
                  :class="
                    brandStatsDays === opt.value
                      ? 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-400'
                      : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300'
                  "
                  @click="handleBrandStatsDaysChange(opt.value)"
                >
                  {{ opt.label }}
                </span>
              </div>
            </div>
          </div>
          <div
            v-if="chartsLoading || brandStatsLoading"
            class="flex items-center justify-center h-80"
          >
            <div class="text-gray-500 dark:text-gray-400">图表加载中...</div>
          </div>
          <div v-else class="h-80">
            <PieChart :data="brandStatsChartData" height="320px" />
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- 用户等级分布 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
              用户等级分布
            </h3>
          </div>
          <div
            v-if="chartsLoading"
            class="flex items-center justify-center h-80"
          >
            <div class="text-gray-500 dark:text-gray-400">图表加载中...</div>
          </div>
          <div v-else class="h-80">
            <PieChart :data="userLevelChartData" height="320px" />
          </div>
        </div>

        <!-- API统计 -->
        <div class="bg-white dark:bg-[#141414] rounded-lg p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
              API调用统计
            </h3>
          </div>
          <div class="space-y-4">
            <!-- 总体统计 -->
            <div class="pb-4 border-b border-gray-200 dark:border-gray-700">
              <div class="grid grid-cols-3 gap-4 text-center">
                <div>
                  <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">
                    总调用数
                  </p>
                  <p class="text-xl font-bold text-gray-900 dark:text-white">
                    {{ formatNumber(systemOverview?.daily?.api_calls || 0) }}
                  </p>
                </div>
                <div>
                  <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">
                    错误次数
                  </p>
                  <p class="text-xl font-bold text-red-600 dark:text-red-400">
                    {{ formatNumber(systemOverview?.daily?.api_errors || 0) }}
                  </p>
                </div>
                <div>
                  <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">
                    错误率
                  </p>
                  <p class="text-xl font-bold text-gray-900 dark:text-white">
                    {{ (systemOverview?.daily?.error_rate || 0).toFixed(2) }}%
                  </p>
                </div>
              </div>
              <div class="mt-4">
                <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                  API稳定性
                </div>
                <ElProgress
                  :percentage="
                    Math.max(0, 100 - (systemOverview?.daily?.error_rate || 0))
                  "
                  :color="
                    systemOverview?.daily?.error_rate &&
                    systemOverview?.daily?.error_rate > 5
                      ? '#F56C6C'
                      : '#67C23A'
                  "
                />
              </div>
            </div>

            <!-- 各版本详细统计 -->
            <div
              v-if="systemOverview?.daily?.version_stats?.length"
              class="space-y-3"
            >
              <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                版本统计详情
              </h4>
              <div
                v-for="versionStat in systemOverview.daily.version_stats"
                :key="versionStat.version"
                class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4"
              >
                <div class="flex items-center justify-between mb-3">
                  <span class="font-medium text-gray-900 dark:text-white">
                    API {{ versionStat.version.toUpperCase() }}
                  </span>
                  <ElTag
                    :type="
                      versionStat.success_rate > 95
                        ? 'success'
                        : versionStat.success_rate > 90
                          ? 'warning'
                          : 'danger'
                    "
                    size="small"
                  >
                    成功率 {{ versionStat.success_rate.toFixed(2) }}%
                  </ElTag>
                </div>
                <div class="grid grid-cols-3 gap-3 text-sm">
                  <div class="text-center">
                    <p class="text-gray-600 dark:text-gray-400 mb-1">总调用</p>
                    <p
                      class="text-lg font-semibold text-gray-900 dark:text-white"
                    >
                      {{ formatNumber(versionStat.total_calls) }}
                    </p>
                  </div>
                  <div class="text-center">
                    <p class="text-gray-600 dark:text-gray-400 mb-1">成功</p>
                    <p
                      class="text-lg font-semibold text-green-600 dark:text-green-400"
                    >
                      {{ formatNumber(versionStat.success_calls) }}
                    </p>
                  </div>
                  <div class="text-center">
                    <p class="text-gray-600 dark:text-gray-400 mb-1">错误</p>
                    <p
                      class="text-lg font-semibold text-red-600 dark:text-red-400"
                    >
                      {{ formatNumber(versionStat.error_calls) }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- 无版本数据时的提示 -->
            <div
              v-else
              class="text-center py-6 text-gray-500 dark:text-gray-400"
            >
              暂无版本统计数据
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* 使用Tailwind CSS，无需自定义样式 */
</style>
