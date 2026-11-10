# Published taxonomy inspection

`smartcloud-agent-composer/inspect-published-taxonomy-terms` accepts `post_id`,
`page_type` and an optional `taxonomy`. It returns actual term assignments for
the published record selected by the active Blueprint. It requires read access
only, and does not create a working copy or return the post body.

The Site Contract must allow reading the post type and searching each returned
taxonomy. The post must be public, published, readable and not password
protected. Taxonomies must be public, queryable, REST-visible and registered
for the selected post type. An empty assignment list is a valid observation.

The response contains the current blog ID, post modification time, term IDs,
slugs, names and an assignment fingerprint. A taxonomy change can occur without
changing the post modification time; use `assignments_sha256` to compare term
observations. This is evidence from the current CMS state, not an assertion
about an earlier static export.

When the optional Content Relations plugin is installed, it separately exposes
`smartcloud-content-relations/inspect-recommendation-rules`. That operation
explains recommendation selectors and tag candidates through the actual shared
resolver. It does not predict a browser's random display choice.
