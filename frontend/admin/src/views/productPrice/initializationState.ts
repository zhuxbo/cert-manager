import type {
  InitializationLevel,
  InitializationRequest,
  InitializationResult
} from "../../api/productPrice.ts";
import type { UserLevelDto } from "../../api/userLevel.ts";

export interface InitializationLevelDetails extends InitializationLevel {
  name: string;
  custom: number;
}

export type InitializationLevelProfile = UserLevelDto;

export interface InitializationLevelPage {
  items: InitializationLevelProfile[];
  total: number;
}

type InitializationParams = Omit<InitializationRequest, "preview_token">;

type PreviewSummary = Partial<InitializationResult> & {
  can_execute: boolean;
  warnings: Array<Partial<InitializationResult["warnings"][number]>>;
};

export interface ExecutionDecision {
  accepted: boolean;
  close: boolean;
  invalidate: boolean;
}

export interface PreviewRequestKey {
  requestId: number;
  revision: string;
}

const compareCodes = (left: { code: string }, right: { code: string }) =>
  left.code < right.code ? -1 : left.code > right.code ? 1 : 0;

const normalizeCostRate = (value: string | number): string => {
  if (typeof value === "number") {
    if (!Number.isFinite(value) || value < 1 || value > 99.9999) {
      throw new RangeError("会员倍率必须介于 1.0000 和 99.9999 之间");
    }

    return value.toFixed(4);
  }

  const trimmed = value.trim();
  const match = /^(\d+)(?:\.(\d{0,4}))?$/.exec(trimmed);
  if (!match) {
    throw new TypeError("会员倍率必须是最多四位小数的十进制数");
  }

  const integer = match[1].replace(/^0+(?=\d)/, "");
  const normalized = `${integer}.${(match[2] ?? "").padEnd(4, "0")}`;
  const numeric = Number(normalized);
  if (numeric < 1 || numeric > 99.9999) {
    throw new RangeError("会员倍率必须介于 1.0000 和 99.9999 之间");
  }

  return normalized;
};

export function mergeSelectedLevels(
  baseLevels: readonly InitializationLevelDetails[],
  customLevels: readonly InitializationLevelDetails[]
): InitializationLevelDetails[] {
  const merged = new Map<string, InitializationLevelDetails>();

  for (const level of [...baseLevels, ...customLevels]) {
    const code = level.code.trim();
    if (!code) continue;

    merged.set(code, {
      code,
      name: level.name,
      custom: level.custom,
      cost_rate: normalizeCostRate(level.cost_rate)
    });
  }

  return [...merged.values()].sort(compareCodes);
}

export function mapSelectedLevelDetails(
  baseCodes: readonly string[],
  customCodes: readonly string[],
  profiles: readonly InitializationLevelProfile[],
  costRateOverrides: ReadonlyMap<string, string | number> = new Map()
): InitializationLevelDetails[] {
  const profilesByCode = new Map(
    profiles.map(level => [level.code, level] as const)
  );
  const selectedCodes = [...new Set([...baseCodes, ...customCodes])];
  const missingCodes = selectedCodes.filter(code => !profilesByCode.has(code));
  if (missingCodes.length > 0) {
    throw new Error(`会员级别资料缺失：${missingCodes.join("、")}`);
  }

  const mapCodes = (codes: readonly string[]) =>
    codes.flatMap(code => {
      const profile = profilesByCode.get(code);
      if (!profile) return [];

      return [
        {
          code: profile.code,
          name: profile.name,
          custom: profile.custom,
          cost_rate: normalizeCostRate(
            costRateOverrides.get(profile.code) ?? profile.cost_rate
          )
        }
      ];
    });

  return mergeSelectedLevels(mapCodes(baseCodes), mapCodes(customCodes));
}

export async function loadAllLevels(
  fetchPage: (
    currentPage: number,
    pageSize: number
  ) => Promise<InitializationLevelPage>,
  pageSize = 100
): Promise<InitializationLevelProfile[]> {
  const safePageSize = Math.max(1, Math.trunc(pageSize));
  const levels = new Map<string, InitializationLevelProfile>();
  let expectedTotal: number | null = null;

  for (let currentPage = 1; ; currentPage += 1) {
    const page = await fetchPage(currentPage, safePageSize);
    const items = Array.isArray(page.items) ? page.items : [];
    const total = page.total;
    if (!Number.isSafeInteger(total) || total < 0) {
      throw new Error("会员级别分页 total 非法");
    }
    if (expectedTotal === null) {
      expectedTotal = total;
    } else if (total !== expectedTotal) {
      throw new Error(
        `会员级别分页 total 发生变化：${expectedTotal} -> ${total}`
      );
    }

    for (const item of items) {
      if (item.code && !levels.has(item.code)) levels.set(item.code, item);
    }

    if (items.length === 0 || currentPage * safePageSize >= expectedTotal)
      break;
  }

  return [...levels.values()].sort(compareCodes);
}

export function normalizeInitializationParams(
  params: InitializationParams
): InitializationParams {
  const levels = new Map<string, InitializationLevel>();

  for (const level of params.levels) {
    const code = level.code.trim();
    if (!code) continue;
    levels.set(code, {
      code,
      cost_rate: normalizeCostRate(level.cost_rate)
    });
  }

  return {
    levels: [...levels.values()].sort(compareCodes),
    precision: params.precision,
    force: Boolean(params.force),
    sync_cost_rates: Boolean(params.sync_cost_rates),
    preview: Boolean(params.preview)
  };
}

export function makeInitializationRevision(
  params: InitializationParams
): string {
  const normalized = normalizeInitializationParams(params);

  return JSON.stringify({
    levels: normalized.levels,
    precision: normalized.precision,
    force: normalized.force,
    sync_cost_rates: normalized.sync_cost_rates
  });
}

export function makeSelectedCodesRevision(
  baseCodes: readonly string[],
  customCodes: readonly string[]
): string {
  const codes = [
    ...new Set(
      [...baseCodes, ...customCodes].map(code => code.trim()).filter(Boolean)
    )
  ].sort();

  return JSON.stringify(codes);
}

export function canAcceptLevelList(
  responseGeneration: number,
  currentGeneration: number,
  dialogVisible: boolean
): boolean {
  return responseGeneration === currentGeneration && dialogVisible;
}

export function createPreviewState() {
  let latestRequestId = 0;
  let revision = "";
  let result: PreviewSummary | null = null;

  return {
    begin(params: InitializationParams): PreviewRequestKey {
      latestRequestId += 1;
      revision = makeInitializationRevision(params);
      result = null;
      return { requestId: latestRequestId, revision };
    },
    accept(request: PreviewRequestKey, response: PreviewSummary): boolean {
      if (
        !revision ||
        request.requestId !== latestRequestId ||
        request.revision !== revision
      ) {
        return false;
      }
      result = response;
      return true;
    },
    isLatest(request: PreviewRequestKey): boolean {
      return request.requestId === latestRequestId;
    },
    invalidate(): void {
      revision = "";
      result = null;
    },
    snapshot(): {
      requestId: number;
      revision: string;
      result: PreviewSummary | null;
    } {
      return { requestId: latestRequestId, revision, result };
    }
  };
}

export function canExecutePreview(
  preview: Pick<
    PreviewSummary,
    "can_execute" | "preview_token" | "warnings"
  > | null
): boolean {
  return Boolean(
    preview?.can_execute &&
    preview.preview_token &&
    preview.warnings.length === 0
  );
}

export function canExecuteCurrentPreview(
  preview: Pick<
    PreviewSummary,
    "can_execute" | "preview_token" | "warnings"
  > | null,
  executionRequest: Readonly<InitializationRequest> | null,
  baseCodes: readonly string[],
  customCodes: readonly string[],
  selectedLevels: readonly InitializationLevelDetails[],
  currentRevision: string
): boolean {
  if (
    !executionRequest ||
    !canExecutePreview(preview) ||
    makeSelectedCodesRevision(baseCodes, customCodes) !==
      makeSelectedCodesRevision(
        selectedLevels.map(level => level.code),
        []
      )
  ) {
    return false;
  }

  return (
    makeInitializationRevision({ ...executionRequest, preview: true }) ===
    currentRevision
  );
}

export function canAcceptExecutionResult(
  result: Pick<InitializationResult, "executed" | "reason" | "warnings">
): ExecutionDecision {
  if (result.executed === true) {
    return { accepted: true, close: true, invalidate: false };
  }

  return {
    accepted: false,
    close: false,
    invalidate: true
  };
}

export function cloneExecutionRequest(
  previewParams: InitializationParams,
  previewToken: string
): Readonly<InitializationRequest> {
  const normalized = normalizeInitializationParams(previewParams);
  const levels = normalized.levels.map(level => Object.freeze({ ...level }));

  return Object.freeze({
    ...normalized,
    levels: Object.freeze(levels) as InitializationLevel[],
    preview: false,
    preview_token: previewToken
  });
}

const escapeHtml = (value: unknown): string =>
  String(value ?? "").replace(
    /[&<>"']/g,
    character =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
      })[character] ?? character
  );

export function buildForceConfirmation(
  levels: readonly Pick<InitializationLevelDetails, "code" | "name">[],
  summary: Pick<InitializationResult, "deleted_count" | "rebuilt_count">
): string {
  const levelLabels = levels
    .map(level => `${escapeHtml(level.name)}（${escapeHtml(level.code)}）`)
    .join("、");

  return [
    '<strong style="color:#f56c6c">红色危险操作：将强制重建价格</strong>',
    `<p>涉及级别：${levelLabels || "无"}</p>`,
    `<p>预计删除 <strong>${summary.deleted_count}</strong> 条，重建 <strong>${summary.rebuilt_count}</strong> 条。</p>`,
    "<p>请确认继续执行。</p>"
  ].join("");
}

export function syncCostRatesHint(
  force: boolean,
  syncCostRates: boolean
): string {
  return !force && syncCostRates
    ? "仅新增价格按新比例生成，既有价格不会随会员比例变化。"
    : "";
}
