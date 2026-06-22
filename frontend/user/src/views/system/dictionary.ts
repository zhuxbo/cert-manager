import { getConfig } from "@/config";

/**
 * 系统相关字典数据
 */

// 品牌选项
export const brandOptionsAll = [
  { label: "Cnssl", value: "cnssl" },
  { label: "Certum", value: "certum" },
  { label: "GoGetSSL", value: "gogetssl" },
  { label: "Positive", value: "positive" },
  { label: "锐安信", value: "ssltrus" },
  { label: "环安信", value: "keeptrust" },
  { label: "Rapid", value: "rapid" },
  { label: "GeoTrust", value: "geotrust" },
  { label: "Sectigo", value: "sectigo" },
  { label: "Alpha", value: "alpha" },
  { label: "GlobalSign", value: "globalsign" },
  { label: "DigiCert", value: "digicert" },
  { label: "TrustAsia", value: "trustasia" },
  { label: "沃通", value: "wotrus" },
  { label: "上海CA", value: "sheca" },
  { label: "CFCA", value: "cfca" }
];

// 品牌标签映射（小写 → 显示名）
export const brandLabels: { [key: string]: string } = Object.fromEntries(
  brandOptionsAll.map(b => [b.value, b.label])
);

// 品牌配置 默认配置
const brandConfig = (getConfig("Brands") as string[]) || ["certum"];

export const brandOptions = brandOptionsAll.filter(brand =>
  brandConfig.includes(brand.value)
);

// CA 选项（委托创建按 CA 选择，后端经 ca_map 派生 prefix）
export const caOptionsAll = [
  { label: "Sectigo", value: "sectigo" },
  { label: "Certum", value: "certum" },
  { label: "DigiCert", value: "digicert" },
  { label: "GlobalSign", value: "globalsign" },
  { label: "TrustAsia", value: "trustasia" },
  { label: "上海CA", value: "sheca" },
  { label: "CFCA", value: "cfca" },
  { label: "沃通", value: "wotrus" }
];

// 保险币种选项
export const warrantyCurrencyOptions = [
  { label: "USD", value: "$" },
  { label: "EUR", value: "€" },
  { label: "CNY", value: "¥" }
];

// 加密标准选项
export const encryptionStandardOptions = [
  { label: "国际", value: "international" },
  { label: "国密", value: "chinese" }
];

// 加密算法选项
export const encryptionAlgOptions = [
  { label: "RSA", value: "rsa" },
  { label: "ECDSA", value: "ecdsa" },
  { label: "SM2", value: "sm2" }
];

// 签名摘要算法选项
export const signatureDigestAlgOptions = [
  { label: "SHA256", value: "sha256" },
  { label: "SHA384", value: "sha384" },
  { label: "SHA512", value: "sha512" },
  { label: "SM3", value: "sm3" }
];

// 验证类型选项
export const validationTypeOptions = [
  { label: "DV", value: "dv" },
  { label: "OV", value: "ov" },
  { label: "EV", value: "ev" }
];

// 产品类型选项
export const productTypeOptions = [
  { label: "SSL", value: "ssl" },
  { label: "代码签名", value: "codesign" },
  { label: "S/MIME", value: "smime" },
  { label: "文档签名", value: "docsign" },
  { label: "ACME", value: "acme" }
];

export const productTypeLabels: { [key: string]: string } = {
  ssl: "SSL",
  smime: "S/MIME",
  codesign: "代码签名",
  docsign: "文档签名",
  acme: "ACME"
};

// 通用名称类型选项
export const nameTypeOptions = [
  { label: "标准域名", value: "standard" },
  { label: "通配符域名", value: "wildcard" },
  { label: "IPV4", value: "ipv4" },
  { label: "IPV6", value: "ipv6" }
];

export const nameTypeLabels: { [key: string]: string } = {
  standard: "标准域名",
  wildcard: "通配符域名",
  ipv4: "IPV4",
  ipv6: "IPV6"
};

// 验证方法选项
// delegation 为所有 SSL 产品通用验证方式，非 ACME 专用
export const validationMethodOptions = [
  { label: "委托验证", value: "delegation" },
  { label: "TXT", value: "txt" },
  { label: "CNAME", value: "cname" },
  { label: "FILE", value: "file" },
  { label: "HTTP", value: "http" },
  { label: "HTTPS", value: "https" },
  { label: "ADMIN(邮件验证)", value: "admin" },
  { label: "ADMINISTRATOR(邮件验证)", value: "administrator" },
  { label: "POSTMASTER(邮件验证)", value: "postmaster" },
  { label: "WEBMASTER(邮件验证)", value: "webmaster" },
  { label: "HOSTMASTER(邮件验证)", value: "hostmaster" }
];

export const validationMethodLabels: { [key: string]: string } = {
  delegation: "委托验证(自动续签)",
  txt: "TXT(解析验证)",
  cname: "CNAME(解析验证)",
  file: "FILE(文件验证)",
  http: "HTTP(文件验证)",
  https: "HTTPS(文件验证)",
  admin: "ADMIN(邮件验证)",
  administrator: "ADMINISTRATOR(邮件验证)",
  postmaster: "POSTMASTER(邮件验证)",
  webmaster: "WEBMASTER(邮件验证)",
  hostmaster: "HOSTMASTER(邮件验证)"
};

// 周期选项
export const periodOptions = [
  { label: "1个月", value: 1 },
  { label: "3个月", value: 3 },
  { label: "6个月", value: 6 },
  { label: "1年", value: 12 },
  { label: "2年", value: 24 },
  { label: "3年", value: 36 },
  { label: "4年", value: 48 },
  { label: "5年", value: 60 },
  { label: "6年", value: 72 },
  { label: "7年", value: 84 },
  { label: "8年", value: 96 },
  { label: "9年", value: 108 },
  { label: "10年", value: 120 }
];

export const periodLabels: { [key: string]: string } = {
  1: "1月",
  3: "3月",
  6: "6月",
  12: "1年",
  24: "2年",
  36: "3年",
  48: "4年",
  60: "5年",
  72: "6年",
  84: "7年",
  96: "8年",
  108: "9年",
  120: "10年"
};

// 开关选项通用值
export const switchOptions = {
  activeValue: 1,
  inactiveValue: 0
};

// 状态选项
export const statusOptions = [
  { label: "启用", value: 1 },
  { label: "禁用", value: 0 }
];

// 选项的集合
export const options = {
  brands: brandOptions,
  encryptionStandard: encryptionStandardOptions,
  encryptionAlg: encryptionAlgOptions,
  signatureDigestAlg: signatureDigestAlgOptions,
  nameType: nameTypeOptions,
  validationMethods: validationMethodOptions,
  periods: periodOptions,
  switch: switchOptions,
  status: statusOptions
};

export default options;
