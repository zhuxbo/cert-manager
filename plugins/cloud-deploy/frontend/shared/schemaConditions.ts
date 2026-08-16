import { configForSchema, type ConfigSchemaField } from "./schemaConfig";

export interface SchemaCondition {
  key: string;
  equals: unknown;
}

export interface ConditionalSchemaField extends ConfigSchemaField {
  required?: boolean;
  required_when?: SchemaCondition;
  visible_when?: SchemaCondition;
}

export function isSchemaFieldVisible(
  field: ConditionalSchemaField,
  values: Record<string, unknown>,
  fields: ConditionalSchemaField[]
): boolean {
  return (
    field.visible_when === undefined ||
    matchesSchemaCondition(field.visible_when, values, fields)
  );
}

export function isSchemaFieldRequired(
  field: ConditionalSchemaField,
  values: Record<string, unknown>,
  fields: ConditionalSchemaField[]
): boolean {
  return (
    field.required === true ||
    (field.required_when !== undefined &&
      matchesSchemaCondition(field.required_when, values, fields))
  );
}

/**
 * 初始化 schema 默认值后仅保留当前条件分支字段，避免切换类型时提交隐藏旧值。
 */
export function configForVisibleSchema(
  fields: ConditionalSchemaField[],
  current: Record<string, unknown> = {}
): Record<string, unknown> {
  const normalized = configForSchema(fields, current);

  return valuesForVisibleSchema(fields, normalized);
}

/** 仅移除不可见字段；编辑凭证时可避免补写未主动修改的默认值。 */
export function valuesForVisibleSchema(
  fields: ConditionalSchemaField[],
  current: Record<string, unknown> = {}
): Record<string, unknown> {
  return Object.fromEntries(
    fields
      .filter(field => isSchemaFieldVisible(field, current, fields))
      .filter(field => Object.prototype.hasOwnProperty.call(current, field.key))
      .map(field => [field.key, current[field.key]])
  );
}

function matchesSchemaCondition(
  condition: SchemaCondition,
  values: Record<string, unknown>,
  fields: ConditionalSchemaField[]
): boolean {
  const controlling = fields.find(field => field.key === condition.key);
  const value = values[condition.key];
  const effectiveValue =
    value === undefined || value === null || value === ""
      ? controlling?.default
      : value;

  return effectiveValue === condition.equals;
}
