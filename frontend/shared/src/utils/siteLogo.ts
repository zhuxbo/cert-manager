/** 由公开资源目录推导默认 Logo 地址（user 端沿用可被升级保留的 public/logo.svg）。 */
export const defaultLogoPath = (baseUrl: string): string => {
  const base = baseUrl || "/";
  return `${base.endsWith("/") ? base : `${base}/`}logo.svg`;
};

/** 由公开资源目录推导默认客服二维码地址。 */
export const defaultQrcodePath = (
  baseUrl: string,
  extension: "svg" | "png" = "svg"
): string => {
  const base = baseUrl || "/";
  return `${base.endsWith("/") ? base : `${base}/`}qrcode.${extension}`;
};

/** 由公开资源目录推导默认登录配图地址（user 端 public/login.svg，可被升级保留）。 */
export const defaultLoginImagePath = (baseUrl: string): string => {
  const base = baseUrl || "/";
  return `${base.endsWith("/") ? base : `${base}/`}login.svg`;
};

/** 后台未配置登录配图时回落默认 login.svg；默认资源加载失败由调用方降级纯色面板。 */
export const resolveSiteLoginImage = (
  loginImage: string | null | undefined,
  fallbackUrl: string
): string => {
  return loginImage || fallbackUrl;
};

const isDefaultQrcodeValue = (qrcode: string | null | undefined): boolean =>
  !qrcode || ["/qrcode.png", "/qrcode.svg"].includes(qrcode);

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

/** 后台未配置或返回默认哨兵时，优先使用新版 SVG 占位图。 */
export const resolveSiteQrcode = (
  qrcode: string | null | undefined,
  fallbackUrl: string
): string => {
  return isDefaultQrcodeValue(qrcode) ? fallbackUrl : qrcode;
};

/** 默认 SVG 不存在时回落旧版 PNG；后台上传的自定义地址加载失败时不替换。 */
export const resolveSiteQrcodeAfterError = (
  qrcode: string | null | undefined,
  currentUrl: string,
  legacyFallbackUrl: string
): string => {
  return isDefaultQrcodeValue(qrcode) && currentUrl !== legacyFallbackUrl
    ? legacyFallbackUrl
    : currentUrl;
};
