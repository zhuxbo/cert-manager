// 共享类型定义

// API 响应基础类型
export interface ApiResponse<T = any> {
  code: number;
  data: T;
  msg?: string;
  errors?: Record<string, string[]>;
}

// BaseResponse 类型别名（兼容全局类型）
export type BaseResponse<T = any> = ApiResponse<T>;

// 分页参数
export interface PaginationParams {
  page?: number;
  per_page?: number;
}

// 分页响应
export interface PaginatedResponse<T> {
  data: T[];
  total: number;
  current_page: number;
  per_page: number;
  last_page: number;
}

// 通用选项类型
export interface SelectOption {
  label: string;
  value: string | number;
}

// 声明全局类型（供 shared 包内部使用）
declare global {
  interface BaseResponse<T = any> {
    code: number;
    data?: T;
    msg?: string;
    errors?: Record<string, string[]>;
  }

  interface PlatformConfigs {
    Title?: string;
    StorageNameSpace?: string;
    FixedHeader?: boolean;
    HiddenSideBar?: boolean;
    MultiTagsCache?: boolean;
    MaxTagsLevel?: number;
    KeepAlive?: boolean;
    Locale?: string;
    Layout?: string;
    Theme?: string;
    DarkMode?: boolean;
    OverallStyle?: string;
    Grey?: boolean;
    Weak?: boolean;
    HideTabs?: boolean;
    HideFooter?: boolean;
    Stretch?: boolean | number;
    SidebarStatus?: boolean;
    EpThemeColor?: string;
    ShowLogo?: boolean;
    ShowModel?: string;
    MenuArrowIconNoTransition?: boolean;
    CachingAsyncRoutes?: boolean;
    TooltipEffect?: "dark" | "light";
    ResponsiveStorageNameSpace?: string;
    MenuSearchHistory?: number;
    BaseUrlApi?: string;
    AllBrands?: Array<{ label: string; value: string }>;
    Brands?: Array<{ label: string; value: string }>;
    DnsTools?: string[];
    Beian?: string;
    CopyStart?: string | number;
    Favicon?: string;
    Logo?: string;
    LogoExpanded?: string;
    Qrcode?: string;
  }
}

export {};
