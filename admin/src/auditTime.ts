const WORDPRESS_GMT_TIMESTAMP = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/;

export function parseAuditUtc(value: string): Date | null {
  const match = WORDPRESS_GMT_TIMESTAMP.exec(value.trim());
  if (!match) return null;
  const [, year, month, day, hour, minute, second] = match;
  const parsed = new Date(`${year}-${month}-${day}T${hour}:${minute}:${second}Z`);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

export function formatAuditLocalTime(
  value: string,
  locales?: Intl.LocalesArgument,
  timeZone?: string
): string {
  const parsed = parseAuditUtc(value);
  if (!parsed) return value;
  try {
    return new Intl.DateTimeFormat(locales, {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hourCycle: "h23",
      timeZoneName: "short",
      ...(timeZone ? { timeZone } : {})
    }).format(parsed);
  } catch {
    return `${value} UTC`;
  }
}

export function auditUtcDateTime(value: string): string | undefined {
  return parseAuditUtc(value)?.toISOString();
}
