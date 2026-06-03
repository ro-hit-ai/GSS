# Dynamic Routing Baseline

## Current Dependencies

- `allowed_sections` is still used as the compatibility authorization source in verifier queue visibility and candidate report access.
- `workflow_semantics.php` maps verifier groups to broad section families, including `basic` and a combined education/employment/reference group.
- `Vati_Payfiller_Case_Components` already stores dynamic case components and remains the routing source of truth.
- `Vati_Payfiller_Verifier_Case_Queue` is a whole-case queue table, so component-level assignment must be introduced without breaking existing dashboards.
- Whole-case claim currently assigns all required case components to the claiming verifier.
- Basic Details is used as a routed workload component in older group logic, even though it should be shared context only.

## Routing Direction

- Verifier capability rows should be read first from `Vati_Payfiller_Verifier_Component_Capabilities`.
- If a verifier has no capability rows, routing falls back to `allowed_sections`.
- `basic` is excluded from verifier workload routing and remains visible context for assigned/report users.
- `reference` is kept as a legacy case component and can be matched by `education_reference` or `employment_reference` during rollout.

## Rollout Notes

- Existing dashboards continue using the whole-case queue while claim and visibility checks become component-aware.
- Mixed-component cases can leave unmatched components unassigned in `Vati_Payfiller_Case_Components`.
- Later rollout phases should split queue ownership by component to fully support simultaneous multi-verifier assignment.

