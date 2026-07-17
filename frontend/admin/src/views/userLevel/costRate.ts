const parseCostRate = (value: unknown): number | null => {
  if (
    (typeof value !== "number" && typeof value !== "string") ||
    (typeof value === "string" && value.trim() === "")
  ) {
    return null;
  }

  const rate = Number(value);

  return Number.isFinite(rate) ? rate : null;
};

export const toCostRateNumber = (value: unknown): number =>
  parseCostRate(value) ?? 0;

export const formatCostRate = (value: unknown): string => {
  const rate = parseCostRate(value);

  return rate === null ? "-" : rate.toFixed(4);
};
