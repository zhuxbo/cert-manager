const DEFAULT_COPYRIGHT_START = 2017;

export const resolveCopyrightStart = (
  value: unknown,
  currentYear = new Date().getFullYear()
): number => {
  const year = typeof value === "string" && value.trim() !== "" ? Number(value) : value;

  return typeof year === "number" &&
    Number.isInteger(year) &&
    year >= 1000 &&
    year <= currentYear
    ? year
    : DEFAULT_COPYRIGHT_START;
};
