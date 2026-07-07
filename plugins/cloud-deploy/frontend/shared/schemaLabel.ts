export interface SchemaFieldLike {
  label?: string;
  description?: string;
  help?: string;
  tip?: string;
}

function splitLabel(label: string): { label: string; hint: string } {
  const match = label.match(/^(.*?)\s*[（(]([^（）()]*)[）)]\s*$/);
  if (!match) return { label, hint: "" };

  return {
    label: match[1].trim(),
    hint: match[2].trim()
  };
}

export function schemaFieldLabel(field: SchemaFieldLike): string {
  return splitLabel(field.label ?? "").label;
}

export function schemaFieldHelp(field: SchemaFieldLike): string {
  const labelHint = splitLabel(field.label ?? "").hint;
  return [labelHint, field.description, field.help, field.tip]
    .map(value => (value ?? "").trim())
    .filter(Boolean)
    .join("\n");
}
