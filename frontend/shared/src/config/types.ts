// ECharts 类型由各应用自行定义
type ECharts = any;

/**
 * 对应 `public/platform-config.json` 文件的类型声明
 */
export interface PlatformConfigs {
  Title?: string;
  /** 存储命名空间前缀，用于区分不同应用的 storage key */
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
  Brands?: BrandOption[];
  DnsTools?: string[];
  Beian?: string;
  Logo?: string;
  Qrcode?: string;
  Acme?: boolean;
  AutoDeploy?: boolean;
}

export interface BrandOption {
  label: string;
  value: string;
}

/**
 * 与 `PlatformConfigs` 类型不同，这里是缓存到浏览器本地存储的类型声明
 */
export interface StorageConfigs {
  version?: string;
  title?: string;
  fixedHeader?: boolean;
  hiddenSideBar?: boolean;
  multiTagsCache?: boolean;
  keepAlive?: boolean;
  locale?: string;
  layout?: string;
  theme?: string;
  darkMode?: boolean;
  grey?: boolean;
  weak?: boolean;
  hideTabs?: boolean;
  hideFooter?: boolean;
  sidebarStatus?: boolean;
  epThemeColor?: string;
  themeColor?: string;
  overallStyle?: string;
  showLogo?: boolean;
  showModel?: string;
  menuSearchHistory?: number;
  username?: string;
  baseUrlApi?: string;
}

/**
 * `responsive-storage` 本地响应式 `storage` 的类型声明
 */
export interface ResponsiveStorage {
  locale: {
    locale?: string;
  };
  layout: {
    layout?: string;
    theme?: string;
    darkMode?: boolean;
    sidebarStatus?: boolean;
    epThemeColor?: string;
    themeColor?: string;
    overallStyle?: string;
  };
  configure: {
    grey?: boolean;
    weak?: boolean;
    hideTabs?: boolean;
    hideFooter?: boolean;
    showLogo?: boolean;
    showModel?: string;
    multiTagsCache?: boolean;
    stretch?: boolean | number;
  };
  backend: {
    baseUrlApi: string;
  };
  tags?: Array<any>;
}

/**
 * 平台里所有组件实例都能访问到的全局属性对象的类型声明
 */
export interface GlobalPropertiesApi {
  $echarts: ECharts;
  $storage: ResponsiveStorage;
  $config: PlatformConfigs;
}
