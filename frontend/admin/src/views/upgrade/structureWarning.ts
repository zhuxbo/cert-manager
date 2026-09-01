import type { StructureCheckSummary } from "../../api/upgrade.ts";

export type UpgradeStructureSummary = StructureCheckSummary;

export interface StructureWarningView {
  message: string;
  details: string[];
  manualActions: string[];
}

export function buildStructureWarningView(
  summary: UpgradeStructureSummary
): StructureWarningView {
  const countItems: Array<[string[], string]> = [
    [summary.missing_tables ?? [], "张缺失表"],
    [summary.missing_columns ?? [], "个缺失字段"],
    [summary.extra_columns ?? [], "个多余字段"],
    [summary.modified_columns ?? [], "个字段差异"],
    [summary.missing_indexes ?? [], "个缺失索引"],
    [summary.extra_indexes ?? [], "个多余索引"],
    [summary.modified_indexes ?? [], "个索引差异"],
    [summary.missing_foreign_keys ?? [], "个缺失外键"],
    [summary.extra_foreign_keys ?? [], "个多余外键"],
    [summary.modified_foreign_keys ?? [], "个外键差异"]
  ];
  const detailItems: Array<[string[], string]> = [
    [summary.missing_tables ?? [], "缺失表"],
    [summary.extra_tables ?? [], "未纳入核心结构的表"],
    [summary.missing_columns ?? [], "缺失字段"],
    [summary.extra_columns ?? [], "多余字段"],
    [summary.modified_columns ?? [], "字段差异"],
    [summary.missing_indexes ?? [], "缺失索引"],
    [summary.extra_indexes ?? [], "多余索引"],
    [summary.modified_indexes ?? [], "索引差异"],
    [summary.missing_foreign_keys ?? [], "缺失外键"],
    [summary.extra_foreign_keys ?? [], "多余外键"],
    [summary.modified_foreign_keys ?? [], "外键差异"]
  ];
  const counts = countItems.flatMap(([items, label]) =>
    items.length ? [`${items.length} ${label}`] : []
  );

  return {
    message: counts.length
      ? `发现 ${counts.join("、")}`
      : "核心数据库结构无待处理差异",
    details: detailItems.flatMap(([items, label]) =>
      items.length ? [`${label}：${items.join(", ")}`] : []
    ),
    manualActions: (summary.manual_actions ?? []).filter(
      action => !action.startsWith("删除多余表 ")
    )
  };
}
