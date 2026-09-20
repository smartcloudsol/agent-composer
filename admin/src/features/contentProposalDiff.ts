export interface ContentDiffExcerpt {
  leading: string;
  changed: string;
  trailing: string;
  hiddenBefore: boolean;
  hiddenAfter: boolean;
}

export interface BodyContentReview {
  before: ContentDiffExcerpt;
  after: ContentDiffExcerpt;
  hasVisibleChange: boolean;
}

const decodeHtmlEntities = (value: string): string => value
  .replace(/&#(\d+);/g, (_match, decimal: string) => String.fromCodePoint(Number.parseInt(decimal, 10)))
  .replace(/&#x([\da-f]+);/gi, (_match, hexadecimal: string) => String.fromCodePoint(Number.parseInt(hexadecimal, 16)))
  .replace(/&nbsp;/gi, " ")
  .replace(/&amp;/gi, "&")
  .replace(/&lt;/gi, "<")
  .replace(/&gt;/gi, ">")
  .replace(/&quot;/gi, '"')
  .replace(/&#0?39;|&apos;/gi, "'");

const attribute = (attributes: string, name: string): string => {
  const match = attributes.match(new RegExp(`\\s${name}\\s*=\\s*(["'])(.*?)\\1`, "i"));
  return match ? decodeHtmlEntities(match[2]) : "";
};

/** Convert serialized Gutenberg markup into reviewer-facing visible content. */
export const readableBodyContent = (value: string): string => {
  const withMediaAndLinks = value
    .replace(/<!--[\s\S]*?-->/g, "")
    .replace(/<img\b([^>]*)>/gi, (_match, attributes: string) => {
      const alt = attribute(attributes, "alt");
      const src = attribute(attributes, "src");
      return `\n[Image${alt ? `: ${alt}` : ""}${src ? ` — ${src}` : ""}]\n`;
    })
    .replace(/<a\b([^>]*)>([\s\S]*?)<\/a>/gi, (_match, attributes: string, body: string) => {
      const href = attribute(attributes, "href");
      return `${body}${href ? ` [${href}]` : ""}`;
    })
    .replace(/<br\s*\/?>/gi, "\n")
    .replace(/<\/(?:p|h[1-6]|li|blockquote|figcaption|td|th|tr|section|article|div)>/gi, "\n")
    .replace(/<[^>]+>/g, "");

  return decodeHtmlEntities(withMediaAndLinks)
    .split(/\r?\n/)
    .map((line) => line.replace(/[\t ]+/g, " ").trim())
    .filter(Boolean)
    .join("\n");
};

const excerpt = (
  value: string,
  commonPrefix: number,
  commonSuffix: number,
  contextCharacters: number,
): ContentDiffExcerpt => {
  const changedEnd = value.length - commonSuffix;
  let start = Math.max(0, commonPrefix - contextCharacters);
  let end = Math.min(value.length, changedEnd + contextCharacters);

  while (start > 0 && !/\s/.test(value[start - 1])) start -= 1;
  while (end < value.length && !/\s/.test(value[end])) end += 1;

  return {
    leading: value.slice(start, commonPrefix),
    changed: value.slice(commonPrefix, changedEnd),
    trailing: value.slice(changedEnd, end),
    hiddenBefore: start > 0,
    hiddenAfter: end < value.length,
  };
};

export const buildBodyContentReview = (
  beforeMarkup: string,
  afterMarkup: string,
  contextCharacters = 140,
): BodyContentReview => {
  const beforeText = readableBodyContent(beforeMarkup);
  const afterText = readableBodyContent(afterMarkup);
  let commonPrefix = 0;
  const prefixLimit = Math.min(beforeText.length, afterText.length);
  while (commonPrefix < prefixLimit && beforeText[commonPrefix] === afterText[commonPrefix]) commonPrefix += 1;
  const wordCharacter = (character: string | undefined): boolean => Boolean(character && /[\p{L}\p{N}_]/u.test(character));
  while (
    commonPrefix > 0
    && (
      (wordCharacter(beforeText[commonPrefix - 1]) && wordCharacter(beforeText[commonPrefix]))
      || (wordCharacter(afterText[commonPrefix - 1]) && wordCharacter(afterText[commonPrefix]))
    )
  ) commonPrefix -= 1;

  let commonSuffix = 0;
  const suffixLimit = Math.min(beforeText.length - commonPrefix, afterText.length - commonPrefix);
  while (
    commonSuffix < suffixLimit
    && beforeText[beforeText.length - commonSuffix - 1] === afterText[afterText.length - commonSuffix - 1]
  ) commonSuffix += 1;
  while (commonSuffix > 0) {
    const beforeStart = beforeText.length - commonSuffix;
    const afterStart = afterText.length - commonSuffix;
    const splitsWord = (
      (wordCharacter(beforeText[beforeStart - 1]) && wordCharacter(beforeText[beforeStart]))
      || (wordCharacter(afterText[afterStart - 1]) && wordCharacter(afterText[afterStart]))
    );
    if (!splitsWord) break;
    commonSuffix -= 1;
  }

  return {
    before: excerpt(beforeText, commonPrefix, commonSuffix, contextCharacters),
    after: excerpt(afterText, commonPrefix, commonSuffix, contextCharacters),
    hasVisibleChange: beforeText !== afterText,
  };
};
