import assert from "node:assert/strict";
import test from "node:test";
import { buildBodyContentReview, readableBodyContent } from "../src/features/contentProposalDiff.ts";

test("Gutenberg body review removes serialization markup and retains useful link targets", () => {
  const content = '<!-- wp:paragraph --><p>Kapcsolat &amp; időpont: <a href="/kapcsolat/">foglalás</a></p><!-- /wp:paragraph -->';
  const readable = readableBodyContent(content);
  assert.equal(readable, "Kapcsolat & időpont: foglalás [/kapcsolat/]");
  assert.doesNotMatch(readable, /wp:paragraph|<p>|<!--/);
});

test("body review isolates the visible proposal change with bounded context", () => {
  const before = '<!-- wp:paragraph --><p>Közérthető útmutató a vizsgálat céljáról, menetéről, az előkészületekről, az árakról és a kapcsolódó ellátási lehetőségekről.</p><!-- /wp:paragraph -->';
  const after = '<!-- wp:paragraph --><p>Közérthető útmutató a gyomortükrözés céljáról, menetéről, az előkészületekről és a kapcsolódó ellátási lehetőségekről.</p><!-- /wp:paragraph -->';
  const review = buildBodyContentReview(before, after, 30);
  assert.equal(review.hasVisibleChange, true);
  assert.match(review.before.changed, /vizsgálat/);
  assert.match(review.before.changed, /árakról/);
  assert.match(review.after.changed, /gyomortükrözés/);
  assert.doesNotMatch(review.before.changed, /<!--|<p>/);
});

test("markup-only changes direct the reviewer to the rendered preview", () => {
  const review = buildBodyContentReview('<p class="old">Azonos szöveg.</p>', '<p class="new">Azonos szöveg.</p>');
  assert.equal(review.hasVisibleChange, false);
});
