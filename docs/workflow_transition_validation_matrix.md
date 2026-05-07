# Workflow Transition Engine Validation Matrix (P0)

## Scope
- Endpoint under test: `api/shared/component_action.php`
- Engine flag: `WF_TRANSITION_ENGINE=1`
- Out of scope (not migrated in this phase): `submit.php`, `queue_complete.php`, `store_authorization.php`

## Preconditions
1. `Vati_Payfiller_Cases.workflow_version` exists and has non-null values.
2. `Vati_Payfiller_Workflow_Transitions` exists.
3. Snapshot rows exist in `Vati_Payfiller_Case_Components` for test application.
4. Required workflow rows exist in `Vati_Payfiller_Case_Component_Workflow`.

## Structured Log File
- `logs/workflow_transition.log`
- Events:
  - `transition_start`
  - `transition_commit`
  - `transition_rollback`
  - `transition_failure`
  - `transition_idempotent_replay`
  - `legacy_shadow_compare`
  - `queue_projection_update`

## Test Matrix

### 1) Normal Flow
1. Validator approve required component.
- Expect: `component_status=approved`, version +1, case may progress to `PENDING_VERIFIER` only when all required validator components finalized.
2. Verifier approve required component.
- Expect: verifier group queue projection updated deterministically.
3. QA approve required component.
- Expect: blocked unless verifier stage final for required components.

### 2) Failure Flow
1. Invalid stage transition.
- Setup: previous stage pending.
- Expect: `WF_PREVIOUS_STAGE_PENDING`, rollback, version unchanged.
2. Stale version.
- Send old `expected_workflow_version`.
- Expect: HTTP 409, `WF_VERSION_CONFLICT`.
3. Unauthorized actor role.
- Expect: HTTP 403 `WF_FORBIDDEN_ROLE`.
4. Assignment mismatch.
- Expect: HTTP 403 `WF_NOT_ASSIGNED`.

### 3) Concurrency Flow
1. Simultaneous approvals from two clients with same `expected_workflow_version`.
- Expect: only one succeeds; second returns `WF_VERSION_CONFLICT`.
2. Duplicate request replay.
- Same `transition_request_id`.
- Expect: `WF_IDEMPOTENT_REPLAY`, no duplicate mutation.
3. Retry after conflict.
- Reload workflow_version and retry.
- Expect: success if transition still allowed.

### 4) Queue Projection Validation
1. Validator queue completion correctness.
- Verify queue status `done` only when all required validator-stage components are final.
2. Verifier group projection correctness.
- Verify group row `done` only when group components with validator-approved state are verifier-final.
3. Hold keeps queue active.
- Set any required component status to `hold`; expect queue state `blocked` (not completed).
4. Insufficient-documents keeps queue active.
- Set any required component status to `insufficient_documents`; expect queue state `blocked` (not completed).
5. Waiting-candidate semantics.
- If upstream prerequisite stage is not final, expect queue state `waiting_candidate`.
6. Rejected-but-reopenable keeps queue active.
- Mixed final states (`approved` + `rejected`) with no terminal case rejection must remain active (`blocked`/`in_progress`).
7. Terminal rejection closes queue.
- If case is terminal rejected (`REJECTED`/`STOP_BGV`) or all required stage items are rejected, queue may close.

### 5) Rollback Validation
1. Force invariant failure (QA approve while verifier stage unresolved).
- Expect full rollback: no workflow row mutation, no version bump, no queue drift.
2. DB write error simulation (optional by temp constraint violation).
- Expect rollback + structured error in API and logs.

## Determinism Checks
For each successful mutation:
1. `workflow_version` increments exactly once.
2. `case_status` equals workflow-derived status.
3. Queue projection matches workflow state.
4. `legacy_shadow_compare` log should show empty or expected drift details.

## Performance Checks
Capture from logs:
- `duration_ms` from `transition_commit` / `transition_rollback`.

SQL checks:
```sql
-- slow query baseline
SHOW INDEX FROM Vati_Payfiller_Case_Component_Workflow;
SHOW INDEX FROM Vati_Payfiller_Case_Components;
SHOW INDEX FROM Vati_Payfiller_Workflow_Transitions;
```

Recommended indexes (if missing):
```sql
CREATE INDEX idx_ccw_case_component_stage ON Vati_Payfiller_Case_Component_Workflow(case_id, component_key, stage);
CREATE INDEX idx_cc_case_required_component ON Vati_Payfiller_Case_Components(case_id, is_required, component_key);
CREATE INDEX idx_wt_app_case_created ON Vati_Payfiller_Workflow_Transitions(application_id, case_id, created_at);
```

## API Test Payload (example)
```json
{
  "application_id": "APP-...",
  "case_id": 123,
  "component_key": "education",
  "action": "approve",
  "reason": "ok",
  "expected_workflow_version": 5,
  "transition_request_id": "trn-12345-edu-approve-1"
}
```

## Pass Criteria
- No queue divergence after N transitions.
- No silent overwrites under concurrency.
- No mismatched case status after commit.
- All failures rollback cleanly.
- Logs provide complete traceability for each transition.
- Queue completion occurs only for:
  - all required components approved, or
  - terminal rejection policy reached.
