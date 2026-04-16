# VATI to MintLeaf Integration

## Auth

- Session/cookie auth: supported on shared APIs. MintLeaf can forward authenticated VATI session cookies.
- Service token auth: supported on shared APIs with either `Authorization: Bearer <token>` or `X-Service-Token: <token>`.
- Token config:
  - `MINTLEAF_SERVICE_TOKEN`
  - fallback `INTEGRATION_SERVICE_TOKEN`
- For `/api/shared/my_assigned_applications.php`, service-token requests must also send `user_id` so VATI can resolve the effective user scope.

## Stable Application Identity

- VATI exposes the canonical immutable external identifier as `applicationId`.
- `applicationId` is normalized to uppercase and whitespace-trimmed before responses, email headers, links, and webhook payloads are built.
- Legacy `application_id` fields are still returned where the old UI expects them.

## Shared APIs

### `GET /api/shared/my_assigned_applications.php`

Response shape:

```json
{
  "status": 1,
  "message": "ok",
  "data": [
    {
      "applicationId": "APP-20260411010112345",
      "caseId": 123,
      "currentRoleForUser": "validator",
      "currentStage": "Pending Validator",
      "accessReason": "validator_assignment",
      "applicationUrl": "https://example.test/GSS/modules/shared/candidate_report.php?application_id=APP-20260411010112345&case_id=123",
      "candidateUrl": "https://example.test/GSS/modules/shared/candidate_report.php?application_id=APP-20260411010112345&case_id=123",
      "timelineUrl": "https://example.test/GSS/modules/qa/case_review.php?application_id=APP-20260411010112345&case_id=123"
    }
  ]
}
```

### `GET /api/shared/case_workflow_snapshot.php?application_id=<applicationId>`

Response fields:

- `applicationId`
- `caseId`
- `candidateName`
- `candidateEmail`
- `currentStage`
- `rawCaseStatus`
- `ownerSummary`
- `pendingItemsSummary`
- `lastTimelineEvent`
- `tatConfig`
- `applicationUrl`
- `candidateUrl`
- `timelineUrl`
- optional `workflowDebug` when `include_debug=1`

### `GET /api/shared/candidate_report_get.php?application_id=<applicationId>`

Stable integration fields in `data`:

- `applicationId`
- `caseId`
- `assigned_components`
- `assignedComponents`
- `component_workflow`
- `componentWorkflow`
- `applicationUrl`
- `candidateUrl`
- `timelineUrl`

Legacy payload fields are preserved for VATI UI compatibility.

### `GET /api/shared/case_timeline_list.php?application_id=<applicationId>`

Response event shape:

```json
{
  "status": 1,
  "message": "ok",
  "data": [
    {
      "timelineId": 99,
      "applicationId": "APP-20260411010112345",
      "eventType": "update",
      "eventTimestamp": "2026-04-11T10:15:23+00:00",
      "sectionKey": "employment",
      "componentKey": "employment",
      "message": "VERIFIER component status: APPROVED",
      "metadata": null,
      "actor": {
        "userId": 17,
        "role": "verifier",
        "username": "jane.doe",
        "name": "Jane Doe"
      }
    }
  ]
}
```

## Email Headers Added

Headers added to MintLeaf-relevant outbound mail:

- `X-Application-Id`
- `X-SourceCaseId`
- `X-VATI-Event-Type`
- `X-VATI-User-Id`
- `X-VATI-User-Role`

Applied to:

- candidate invitation emails
- candidate submission notifications
- candidate submission confirmations
- shared template-driven application emails
- other calls that set `app_mail_set_log_meta()` with an `application_id`

## Webhooks from VATI to MintLeaf

Configure:

- `MINTLEAF_WEBHOOK_URL`
- `MINTLEAF_WEBHOOK_SECRET`

Headers sent by VATI:

- `X-VATI-Event-Id`
- `X-VATI-Event-Type`
- `X-VATI-Source: VATI`
- `X-VATI-Timestamp`
- `X-VATI-Signature: sha256=<hmac>` when secret is configured

Webhook payload example:

```json
{
  "eventId": "vati_0d2f9f5f1a1c9a7a2d6c7f7d4c0d1b2a3e4f5a6b",
  "eventType": "application.created",
  "applicationId": "APP-20260411010112345",
  "caseId": 123,
  "candidateEmail": "candidate@example.com",
  "candidateName": "Alex Candidate",
  "currentStage": "Invited",
  "status": "CREATED",
  "triggeredBy": {
    "userId": 44,
    "role": "client_admin"
  },
  "triggeredAt": "2026-04-11T10:15:23+00:00",
  "metadata": {
    "applicationUrl": "https://example.test/GSS/modules/shared/candidate_report.php?application_id=APP-20260411010112345&case_id=123",
    "candidateUrl": "https://example.test/GSS/modules/shared/candidate_report.php?application_id=APP-20260411010112345&case_id=123",
    "timelineUrl": "https://example.test/GSS/modules/qa/case_review.php?application_id=APP-20260411010112345&case_id=123"
  }
}
```

Currently emitted from:

- case creation: `application.created`
- component assignment: `application.assigned`
- candidate submission: `candidate.responded`
- component workflow actions: `workflow.stage.changed` or `application.status.changed`
- case-level actions: `application.status.changed` or `application.closed`

Idempotency:

- VATI generates deterministic `eventId` values for the same event shape.
- MintLeaf should de-duplicate on `eventId`.
- VATI retries safely because the payload and event id remain stable for the same event.

## Error Format

All integration-focused shared endpoints return JSON only.

Error format:

```json
{
  "status": 0,
  "message": "Unauthorized"
}
```

Typical status codes:

- `200` success
- `400` bad request
- `401` unauthorized
- `403` forbidden
- `404` not found
- `405` method not allowed
- `500` server/database error

## Logging

Logs are written under `logs/` when available:

- `logs/webhooks.log`
- `logs/auth.log`
- `logs/integration_failures.log`

## Notes

- Returned emails are normalized to lowercase where these integration responses expose them.
- Null/empty handling is kept predictable: integration aliases prefer `null` over empty strings for optional fields.
- Legacy UI-facing fields remain in place to avoid breaking current VATI screens.
