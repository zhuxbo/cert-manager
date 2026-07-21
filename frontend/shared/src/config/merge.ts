import type { PlatformConfigs } from "./types";

const backendManagedKeys: (keyof PlatformConfigs)[] = [
  "Title",
  "Brands",
  "DnsTools",
  "Beian",
  "Logo",
  "Qrcode"
];

/**
 * 新后端已提供 Brands 时，站点与品牌配置完全以后端为准；旧 JSON 只保留部署配置。
 * 老后端没有 platform.Brands 时继续读取完整静态配置，保证跨版本升级兼容。
 */
export const mergePlatformConfigSources = (
  staticConfig: PlatformConfigs,
  backendConfig?: PlatformConfigs
): PlatformConfigs => {
  const merged = { ...staticConfig };
  const hasBackendBrands = Object.prototype.hasOwnProperty.call(
    backendConfig ?? {},
    "Brands"
  );

  if (!hasBackendBrands) return merged;

  backendManagedKeys.forEach(key => delete merged[key]);
  return Object.assign(merged, backendConfig);
};
