# Media import module

AW-007 adds an isolated media import domain for later integration into the Walker host. It contains no host routes, credentials, production database implementation, UI, or scheduler. The host binds the interfaces and applies its normal authentication, authorization, CSRF, upload, logging, and database rules.

## Supported sources

| Provider | Search | Creator | Collection | Pagination |
|---|---:|---:|---:|---|
| YouTube | Yes | Channel ID or handle | Playlist ID | API page token; creator cursor is opaque and source-bound |
| Vimeo | Yes | Numeric user ID | Numeric album/showcase ID | Numeric page cursor |
| Dailymotion | No | Profile ID | Playlist ID | API v2 page cursor; at most 10 pages of at most 100 results |

Dailymotion global search is explicitly unsupported because the current documented API v2 does not provide the required global-search operation. The adapter returns the safe \`Unsupported\` error instead of falling back to an older API.

A source is either a validated provider identifier or an HTTPS URL on the exact provider-host allowlist. User info, ports, fragments, encoded path segments, extra query parameters, lookalike hosts, and arbitrary URL schemes are rejected. Inputs become provider IDs before an adapter runs. The importer never downloads a user-supplied URL.

## Provider request and response boundary

Adapters create a \`ProviderRequest\` with a fixed \`ProviderEndpoint\`, bounded query values, and an optional validated provider resource ID. The endpoint enum maps only to official HTTPS API hosts. A host implementation of \`ProviderTransport\` must:

- construct requests only from \`ProviderRequest::uri()\`;
- attach credentials at runtime without storing them in the source tree or import record;
- verify TLS, use finite connect and total timeouts, disable redirects, and stop reading at \`ProviderResponse::MAX_BODY_BYTES\`;
- bound response headers and map transport failures to safe provider errors;
- avoid logging authorization headers, raw response bodies, exception messages, or queries containing secrets.

The response boundary rejects redirects, oversized or non-JSON bodies, malformed JSON, unexpected HTTP statuses, and invalid provider shapes. Only bounded error codes and retry times enter job state. Provider HTML is reduced to bounded plain text before it can enter a draft.

Provider credentials, scopes, quotas, and availability are host concerns. No live provider call or credential is needed by the tests.

## CSV source lists

\`CsvSourceParser\` accepts UTF-8 CSV with this exact header:

\`\`\`csv
provider,source_type,source
youtube,search,cats
vimeo,creator,12345
dailymotion,collection,x7abc
\`\`\`

Limits: 1 MiB per file, 500 data rows, and 8 KiB per parsed record. Blank rows are ignored. A malformed header or invalid encoding rejects the file. Invalid provider/type/source, wrong column count, duplicate normalized sources, and a row-limit overflow become row-numbered issues; valid rows remain available. CSV cells are data only and no URL is fetched.

The host enforces upload authorization and CSRF protection, applies its request-body limit before materializing the file in memory, and passes only the uploaded contents to the parser. The parser never writes uploaded files to disk.

## Job lifecycle and safe persistence

The store implementation is the persistence boundary and must enforce these transitions atomically:

- create a new queued job;
- claim a due queued job, or reclaim a running job only after its lease expires; a claim has a new opaque lease token;
- commit one page only if the claim token and version are current;
- pause queued/running jobs while invalidating the active lease, resume paused jobs to the queue, and cancel queued/running/paused jobs while invalidating the lease;
- defer transient quota/availability errors with a retry time capped at one day; fail non-retryable errors with a bounded \`ProviderError\`.

\`ImportJobStore::commitPage()\` must perform the lease/version check, draft inserts, cursor/counter update, and lease release in one database transaction. A stale or cancelled claim inserts no drafts and advances no cursor. The store must enforce a unique key on (provider, external video ID); retries never replace existing content. Database implementations should use a unique constraint and transaction rather than a check-then-insert.

The runner fetches one bounded page at a time, rejects oversized or duplicate provider pages, and checkpoints only after the page is committed. The cursor advances after the unique-key draft inserts and job state update succeed together. A lease-lost result means the page was not committed.

Preview checks provider fields and stored duplicates without persisting. It also marks duplicate IDs within the returned page. New records remain drafts. Approval is a separate \`DraftReviewService\` call guarded by an injected \`DraftApprovalPolicy\`; approval does not publish a record.

\`ImportJobControlService\` applies an injected policy before pause, resume, or cancel. The host policy must check the actor's role and job scope. Web controllers must validate CSRF before invoking any state-changing service. Host scheduling invokes the runner with a non-user-controlled worker identity and honors retry times.

## Public types

- Source and transport boundary: \`MediaProvider\`, \`SourceKind\`, \`ImportSource\`, \`ProviderEndpoint\`, \`ProviderRequest\`, \`ProviderResponse\`, \`ProviderTransport\`
- Provider results: \`VideoCandidate\`, \`ProviderPage\`, \`ProviderAdapter\`, provider adapters, and \`ProviderAdapterRegistry\`
- CSV: \`CsvSourceParser\`, \`CsvImportResult\`, and row issue/error enums
- Job lifecycle: \`ImportJob\`, \`ClaimedImportJob\`, \`ImportJobStore\`, \`ImportRunner\`, \`ImportJobControlService\`, \`ImportJobControlPolicy\`
- Preview and moderation: \`ImportPreviewService\`, \`ImportDraftStore\`, \`DraftReviewService\`, \`DraftApprovalPolicy\`

## Tests

Provider tests use fixed mock transports and synthetic JSON only. They cover endpoint and host selection, redirects, body limits, provider errors, malformed payloads, cursor handling, CSV limits, duplicate detection, lease conflicts, pause/resume/cancel, atomic page commit semantics, retry deferral, draft-only creation, and explicit approval. Tests never use real credentials or network access.

## Provider references

These public references document the operations used by the adapters. Check current provider behavior and required scopes when implementing the host transport:

- YouTube search: https://developers.google.com/youtube/v3/docs/search/list
- YouTube channel lookup: https://developers.google.com/youtube/v3/docs/channels/list
- YouTube playlist items: https://developers.google.com/youtube/v3/docs/playlistItems/list
- Vimeo video API: https://developer.vimeo.com/api/reference/videos
- Dailymotion profile videos (API v2): https://developers.dailymotion.com/reference/listprofilevideos
- Dailymotion playlist videos (API v2): https://developers.dailymotion.com/reference/list-playlist-videos
