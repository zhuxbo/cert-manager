// 管理端Dashboard数据类型定义

// API版本统计
export interface ApiVersionStats {
  version: string;
  total_calls: number;
  success_calls: number;
  error_calls: number;
  success_rate: number;
}

// 财务数据
export interface FinancePeriod {
  recharge: number;
  consumption: number;
  prev_recharge: number;
  prev_consumption: number;
}

// 系统概览数据
export interface SystemOverviewData {
  daily: {
    api_calls: number;
    api_errors: number;
    error_rate: number;
    version_stats: ApiVersionStats[];
  };
  monthly: {
    total_users: number;
    total_orders: number;
    cancelled_orders: number;
    net_orders: number;
    active_orders: number;
    expiring_orders: number;
  };
  finance: {
    daily: FinancePeriod;
    weekly: FinancePeriod;
    monthly: FinancePeriod;
  };
  new_users: {
    daily: number;
    weekly: number;
    monthly: number;
  };
  new_orders: {
    daily: number;
    weekly: number;
    monthly: number;
  };
  order_stats: Record<
    "daily" | "weekly" | "monthly",
    {
      orders: number;
      cancelled_orders: number;
      net_orders: number;
    }
  >;
}

// 实时统计数据
export interface RealtimeData {
  online_users: number;
  today: {
    new_users: number;
    new_orders: number;
    cancelled_orders: number;
    net_orders: number;
    processing_orders: number;
    revenue: number;
  };
  alerts: {
    expiring_7_days: number;
    expiring_30_days: number;
    issued_7_days: number;
    issued_30_days: number;
  };
}

// 趋势数据点
export interface TrendDataPoint {
  date: string;
  users: number;
  orders: number;
  cancelled_orders: number;
  net_orders: number;
  recharge: number;
  consumption: number;
}

// 产品销售排行
export interface TopProduct {
  product_id: number;
  product_name: string;
  order_count: number;
  sales_amount: number;
}

// CA品牌统计
export interface BrandStats {
  brand: string;
  orders: number;
  revenue: number;
}

// 用户等级分布
export interface UserLevelDistribution {
  level_code: string;
  level_name: string;
  user_count: number;
}

// 财务概览数据
export interface FinanceOverviewData {
  total_balance: number;
  positive_count: number;
  total_debt: number;
  negative_count: number;
}

// Dashboard总览数据
export interface AdminDashboardOverview {
  overview: {
    daily: SystemOverviewData["daily"];
    monthly: SystemOverviewData["monthly"];
  };
  trend: TrendDataPoint[];
  top_products: TopProduct[];
  brand_stats: BrandStats[];
}
