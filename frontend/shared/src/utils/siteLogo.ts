/** 由公开资源目录推导默认 Logo 地址（user 端沿用可被升级保留的 public/logo.svg）。 */
export const defaultLogoPath = (baseUrl: string): string => {
  const base = baseUrl || "/";
  return `${base.endsWith("/") ? base : `${base}/`}logo.svg`;
};

/**
 * 后台未配置（空值或默认哨兵 "/logo.svg"）时回落到调用方提供的默认资源；
 * admin 端传打包内置资源 URL（dev/分域部署均可达），user 端传 defaultLogoPath(BASE_URL)。
 */
export const resolveSiteLogo = (
  logo: string | null | undefined,
  fallbackUrl: string
): string => {
  return logo && logo !== "/logo.svg" ? logo : fallbackUrl;
};
