import assert from "node:assert/strict";
import test from "node:test";
import { auditUtcDateTime, formatAuditLocalTime, parseAuditUtc } from "../src/auditTime.ts";

test("WordPress GMT audit timestamps are parsed explicitly as UTC", () => {
  const parsed = parseAuditUtc("2026-09-17 15:48:26");
  assert.equal(parsed?.toISOString(), "2026-09-17T15:48:26.000Z");
  assert.equal(auditUtcDateTime("2026-09-17 15:48:26"), "2026-09-17T15:48:26.000Z");
});

test("audit timestamps are rendered in the selected local time zone", () => {
  const local = formatAuditLocalTime("2026-09-17 15:48:26", "en-GB", "Europe/Budapest");
  assert.match(local, /17:48:26/);
  assert.match(local, /(CEST|GMT\+2)/);
});

test("an unrecognized audit timestamp remains visible", () => {
  assert.equal(formatAuditLocalTime("unknown"), "unknown");
  assert.equal(auditUtcDateTime("unknown"), undefined);
});
