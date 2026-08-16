export interface ConfigSchemaField {
  key: string;
  type?: string;
  default?: unknown;
}

export function isBooleanConfigField(field: ConfigSchemaField): boolean {
  return field.type === "bool" || field.type === "boolean";
}

export function configForSchema(
  fields: ConfigSchemaField[],
  current: Record<string, unknown> = {}
): Record<string, unknown> {
  const next: Record<string, unknown> = {};

  for (const field of fields) {
    const value = current[field.key];
    if (value !== undefined && value !== null && value !== "") {
      next[field.key] = isBooleanConfigField(field)
        ? booleanConfigValue(value)
        : value;
    } else if (Object.prototype.hasOwnProperty.call(field, "default")) {
      next[field.key] = field.default;
    } else {
      next[field.key] = isBooleanConfigField(field) ? false : "";
    }
  }

  return next;
}

function booleanConfigValue(value: unknown): boolean {
  if (typeof value === "boolean") return value;
  if (typeof value === "number") return value !== 0;

  return !["0", "false", "no", "off"].includes(
    String(value).trim().toLowerCase()
  );
}
