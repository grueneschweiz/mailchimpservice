---
trigger: model_decision
description: Project dependencies and architecture of the Mailchimp Service, including how it depends on and communicates with the Webling Service (CRM). Use when working on Synchronizer classes (CrmToMailchimpSynchronizer, MailchimpToCrmCronSynchronizer, MailchimpToCrmWebhookSynchronizer, WebsiteToMailchimpSynchronizer), CrmClient, MailChimpClient, OAuthClient, MailchimpEndpoint, config/*.yml, composer.json dependencies, or the REST endpoints in routes/api.php.
---

# Mailchimp Service — Dependencies & Architecture

## Purpose
Give an AI assistant working on the **Mailchimp Service** (`grueneschweiz/mailchimpservice`)
enough context on its dependencies and architecture — in particular how it depends on and
communicates with the **Webling Service** (`grueneschweiz/weblingservice`) — without having
that repo checked out.

---

## System overview

- **Webling** (proprietary CRM, SaaS) — the actual system of record for member/contact data.
  Not talked to directly by this project.
- **Webling Service** (`weblingservice`) — a separate Laravel application that wraps Webling's
  RESTful API. It exposes a higher-level, OAuth2-secured REST API (`/api/v1/...`) with
  member CRUD, matching/dedup, merge and revision/changed-since endpoints. See its
  `docs/API.md` for the full contract.
- **Mailchimp Service** (this repo, `mailchimpservice`) — the glue between the Webling Service
  and the Mailchimp Marketing API. It has **no direct dependency on Webling itself** — it only
  ever talks to the Webling Service's REST API. In this codebase the Webling Service is
  internally referred to as **"CRM"** (`CrmClient`, `crmClient`, `crmKey`, `CrmToMailchimp...`).
- **Website** (WordPress theme, separate repo) — sends new/changed contacts to this service or
  to the Webling Service, and can query member status through the Webling Service.

---

## Dependencies of this project

From `composer.json`:

- **laravel/framework** (^10.0) — application framework.
- **guzzlehttp/guzzle** (^7.0.1) — HTTP client used by `CrmClient` (Webling Service) and
  `MailChimpClient` (Mailchimp).
- **drewm/mailchimp-api** (^2.5) — thin wrapper around the Mailchimp Marketing API.
- **symfony/yaml** — parses the per-tenant config files in `config/*.yml`
  (see `app/Synchronizer/Config.php`).

No Webling/Webling-Service SDK is used — integration is a hand-rolled Guzzle client
implementing the Webling Service's OAuth2 client-credentials flow.

---

## How this project talks to the Webling Service

### Auth (OAuth2 client credentials)
`app/Http/CrmClient.php` implements the exact flow
described in the Webling Service README/API docs:

1. `POST {baseUrl}/oauth/token` with `grant_type=client_credentials`, `client_id`,
   `client_secret`, `scope=""` → receives a bearer token.
2. The token is cached in the `oauth_clients` table (`app/OAuthClient.php`,
   keyed by `client_id`), so it is reused across requests instead of being fetched every time.
3. Validity is checked by calling `GET /api/v1/auth`; on failure the token is refreshed.
4. All subsequent calls send `Authorization: Bearer {token}`.

Credentials (`clientId`, `clientSecret`, `url`) come from the `auth.crm` section of the
per-endpoint YAML config file (see below), **not** from `.env`.

### Config files (`config/*.yml`)
Each "endpoint" (e.g. one country/organization instance) has its own YAML config, loaded by
`Config.php`. Relevant sections:

```yaml
auth:
  crm:            # = Webling Service credentials
    clientId: ...
    clientSecret: ...
    url: ...
  mailchimp:
    apikey: ...
mailchimp:
  listId: ...
  syncAll: false
mailchimpToCrm:
  interestsToSync: [...]
  groupForNewMembers: ...
webling:
  prioritizedGroups: [...]
fields:           # field mapping between Webling Service member fields (crmKey)
  - crmKey: email1
    mailchimpKey: ...
    type: merge|group|tag|autotag|token|email
    sync: both|toMailchimp|toCrm
```

`MailchimpEndpoint` (DB model, `app/MailchimpEndpoint.php`)
maps a per-endpoint identifier (used in REST URLs) to one of these config file names. Endpoints
are managed via artisan commands (`endpoint:add|edit|delete|list`, `app/Console/Commands/`).

### Synchronizers (`app/Synchronizer/`)

- **`CrmToMailchimpSynchronizer`** — pulls changes from the Webling Service using its
  revision/changed-since endpoints (`GET /api/v1/revision`, `GET /api/v1/member/changed/{revisionId}`),
  applies field mapping/filters, and pushes them into Mailchimp. Run via
  `php artisan sync:all toMailchimp <config>` (nightly cron in production).
- **`MailchimpToCrmCronSynchronizer`** — pulls changed Mailchimp subscribers and upserts them
  back into the Webling Service (`PUT /api/v1/member/{crmId}`), gated by
  `isUpsertToCrmEnabled` in the config.
- **`MailchimpToCrmWebhookSynchronizer`** — handles Mailchimp webhooks (`subscribe`,
  `unsubscribe`, `cleaned`, `profile`, `upemail`) in near-real time and reflects the change in
  the Webling Service, e.g. `crmClient->put('member/{id}', ...)`, or via
  `crmClient->post('member/match', ...)` for dedup lookups. On unsubscribe/cleaned it uses the
  Webling Service's field-update `mode` semantics (`replace`, `append`, ...) exactly as documented
  in the Webling Service API.
- **`WebsiteToMailchimpSynchronizer`** — lets the **Website** push a brand-new contact
  *directly* into Mailchimp (bypassing the Webling Service) via
  `POST /api/v1/mailchimp/contact/{endpoint-id}`. This is the "new contact fast path": new
  contacts land in Mailchimp first and only reach Webling later, once
  `MailchimpToCrmCronSynchronizer` or a webhook syncs them back through the Webling Service.

### REST surface exposed by this service (`routes/api.php`)

| Route | Purpose |
|---|---|
| `POST /api/v1/mailchimp/webhook/{endpoint-id}` | Mailchimp webhook receiver → `MailchimpToCrmWebhookSynchronizer` → Webling Service |
| `GET /api/v1/mailchimp/webhook/{endpoint-id}` | Webhook URL verification (Mailchimp requirement) |
| `POST /api/v1/mailchimp/contact/{endpoint-id}` | Website → new contact directly into Mailchimp |

Each route is scoped to a per-endpoint identifier resolved via `MailchimpEndpoint`.

---

## Data flow summary

1. **Webling Service → Mailchimp Service → Mailchimp**: cron (`sync:all toMailchimp`) pulls
   changes from the Webling Service's revision endpoint and pushes them to Mailchimp
   (`CrmToMailchimpSynchronizer`).
2. **Mailchimp → Mailchimp Service → Webling Service**: Mailchimp webhooks or the cron job
   (`sync:all toCrm`) push subscriber changes/unsubscribes back into the Webling Service
   (`MailchimpToCrmWebhookSynchronizer` / `MailchimpToCrmCronSynchronizer`).
3. **Website → Mailchimp Service → Mailchimp**: new contacts are added directly to Mailchimp
   via `WebsiteToMailchimpSynchronizer`, then flow back to the Webling Service later through
   path 2.
4. **Website → Webling Service → Webling** (existing contacts): handled entirely outside this
   repo, directly against the Webling Service.

The Webling Service remains the master/system-of-record surface for member data; this project
never talks to Webling directly and has no knowledge of Webling's own API — everything goes
through the Webling Service's abstracted, OAuth2-secured REST API.

---

## Rules for further development

- Never call Webling's native API from this project — always go through the Webling Service.
- Keep using the OAuth2 client-credentials flow (`CrmClient`) for all Webling Service calls;
  don't hardcode tokens.
- Respect the Webling Service's field-update `mode` semantics (`replace`, `append`, `remove`,
  `replaceEmpty`, `addIfNew`) when constructing `PUT`/`POST` payloads.
- Per-tenant behavior (field mapping, credentials, sync direction) belongs in `config/*.yml`,
  not in code.
- New contacts should keep using the Mailchimp-first fast path
  (`WebsiteToMailchimpSynchronizer`) unless product requirements explicitly change this.
