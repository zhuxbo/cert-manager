import type { BrandOption } from "../config/types";

/**
 * 与后端 PlatformConfigService::brandOptions 为对称副本，语义必须一致；
 * 等价性由 backend/tests/Fixtures/brand-normalize-cases.json 共享夹具双端锁定
 * （PHP: PlatformConfigServiceTest，TS: tests/brandOptions.test.ts）。
 */
export const normalizeBrandOptions = (value: unknown): BrandOption[] => {
  if (!Array.isArray(value) && (typeof value !== "object" || value === null)) {
    return [];
  }

  const result: BrandOption[] = [];
  const seen = new Set<string>();
  // PHP json_decode 会把 "0"/"1" 等规范数字键转成 int：完整数字序列即列表，
  // 零散 int 键在键值分支中被跳过；此处对齐该语义
  const isCanonicalNumericKey = (key: string): boolean =>
    /^(0|[1-9]\d*)$/.test(key);
  const objectKeys = Array.isArray(value) ? [] : Object.keys(value);
  const isListLike =
    Array.isArray(value) ||
    objectKeys.every((key, index) => key === String(index));
  const items: unknown[] = Array.isArray(value)
    ? value
    : isListLike
      ? Object.values(value)
      : Object.entries(value)
          .filter(([key]) => !isCanonicalNumericKey(key))
          .map(([brandValue, label]) => ({
            label,
            value: brandValue
          }));
  items.forEach((item: any) => {
    const label =
      typeof item === "string"
        ? item.trim()
        : typeof item?.label === "string"
          ? item.label.trim()
          : "";
    const brandValue =
      typeof item === "string"
        ? item.trim().toLowerCase()
        : typeof item?.value === "string"
          ? item.value.trim().toLowerCase()
          : "";

    if (!label || !brandValue || seen.has(brandValue)) return;
    seen.add(brandValue);
    result.push({ label, value: brandValue });
  });

  return result;
};
