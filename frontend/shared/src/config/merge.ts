import type { PlatformConfigs } from "./types";

const backendManagedKeys: (keyof PlatformConfigs)[] = [
  "Title",
  "AllBrands",
  "Brands",
  "DnsTools",
  "Beian",
  "CopyStart",
  "Favicon",
  "Logo",
  "LogoExpanded",
  "Qrcode"
];

/**
 * 后端已提供 AllBrands 时，站点与品牌配置完全以后端为准；静态 JSON 只保留部署配置。
 */
export const mergePlatformConfigSources = (
  staticConfig: PlatformConfigs,
  backendConfig?: PlatformConfigs
): PlatformConfigs => {
  const merged = { ...staticConfig };
  const hasBackendBrands = Object.prototype.hasOwnProperty.call(
    backendConfig ?? {},
    "AllBrands"
  );

  if (!hasBackendBrands) return merged;

  backendManagedKeys.forEach(key => delete merged[key]);
  return Object.assign(merged, backendConfig);
};
