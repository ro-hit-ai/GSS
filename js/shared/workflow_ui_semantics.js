(function () {
    function norm(v) {
        return String(v || '').toLowerCase().trim();
    }
    function upper(v) {
        return String(v || '').toUpperCase().trim();
    }

    var TAXONOMY = {
        component_status: ['approved', 'rejected', 'hold', 'waiting_candidate', 'insufficient_documents', 'correction_submitted', 'in_progress', 'pending', 'reopened', 'blocked', 'invalidated_by_validator_reopen', 'invalidated_by_verifier_reopen'],
        stage_status: ['pending', 'in_progress', 'correction_submitted', 'completed', 'rejected', 'hold', 'insufficient_documents', 'waiting_candidate', 'blocked', 'reopened', 'invalidated_by_validator_reopen', 'invalidated_by_verifier_reopen'],
        queue_operational: ['pending', 'in_progress', 'correction_submitted', 'waiting_candidate', 'hold', 'insufficient_documents', 'reopened', 'blocked', 'done', 'completed'],
        case_status: ['pending_validator', 'pending_verifier', 'pending_qa', 'approved', 'completed', 'verified', 'clear', 'rejected', 'stop_bgv']
    };

    function labelByRole(status, role) {
        var s = norm(status);
        var r = norm(role);
        var map = {
            validator: {
                pending: 'VA PENDING',
                in_progress: 'VA PENDING',
                correction_submitted: 'CORRECTION SUBMITTED',
                hold: 'VA HOLD',
                insufficient_documents: 'NEED DOCS',
                approved: 'VA APPROVED',
                rejected: 'VA REJECTED',
                completed: 'VA COMPLETED',
                done: 'VA COMPLETED',
                reopened: 'DECISION UPDATE',
                invalidated_by_validator_reopen: 'VA INVALIDATED',
                invalidated_by_verifier_reopen: 'VA INVALIDATED',
                blocked: 'VA HOLD',
                waiting_candidate: 'WAITING CANDIDATE',
                pending_validator: 'VA PENDING',
                pending_verifier: 'VE PENDING',
                pending_qa: 'QA PENDING',
                verified: 'VA COMPLETED',
                clear: 'VA COMPLETED',
                mail_sent: 'MAIL SENT'
            },
            verifier: {
                pending: 'VE PENDING',
                in_progress: 'VE PENDING',
                correction_submitted: 'CORRECTION SUBMITTED',
                hold: 'VE HOLD',
                insufficient_documents: 'NEED DOCS',
                approved: 'VE APPROVED',
                rejected: 'VE REJECTED',
                completed: 'VE COMPLETED',
                done: 'VE COMPLETED',
                reopened: 'DECISION UPDATE',
                invalidated_by_validator_reopen: 'VE INVALIDATED',
                invalidated_by_verifier_reopen: 'VE INVALIDATED',
                blocked: 'VE HOLD',
                waiting_candidate: 'WAITING CANDIDATE',
                pending_validator: 'VA PENDING',
                pending_verifier: 'VE PENDING',
                pending_qa: 'QA PENDING',
                verified: 'VE COMPLETED',
                clear: 'VE COMPLETED',
                mail_sent: 'MAIL SENT'
            },
            qa: {
                pending: 'QA PENDING',
                in_progress: 'QA PENDING',
                correction_submitted: 'CORRECTION SUBMITTED',
                hold: 'QA HOLD',
                insufficient_documents: 'NEED DOCS',
                approved: 'QA APPROVED',
                rejected: 'QA REJECTED',
                completed: 'QA COMPLETED',
                done: 'QA COMPLETED',
                reopened: 'DECISION UPDATE',
                invalidated_by_validator_reopen: 'QA INVALIDATED',
                invalidated_by_verifier_reopen: 'QA INVALIDATED',
                blocked: 'QA HOLD',
                waiting_candidate: 'WAITING CANDIDATE',
                pending_validator: 'VA PENDING',
                pending_verifier: 'VE PENDING',
                pending_qa: 'QA PENDING',
                verified: 'QA COMPLETED',
                clear: 'QA COMPLETED',
                mail_sent: 'MAIL SENT'
            }
        };
        var roleMap = map[r] || map.validator;
        return upper(roleMap[s] || String(status || '-'));
    }

    function badgeClass(status) {
        var s = norm(status);
        if (s === 'approved' || s === 'completed' || s === 'done' || s === 'verified' || s === 'clear') return 'success';
        if (s === 'rejected') return 'danger';
        if (s === 'invalidated_by_validator_reopen' || s === 'invalidated_by_verifier_reopen') return 'secondary';
        if (s === 'hold' || s === 'blocked') return 'warning';
        if (s === 'correction_submitted') return 'info';
        if (s === 'insufficient_documents') return 'yellow';
        if (s === 'waiting_candidate' || s === 'reopened') return 'purple';
        if (s === 'mail_sent') return 'cyan';
        if (s === 'reopened') return 'secondary';
        if (s === 'in_progress') return 'primary';
        return 'light';
    }

    function isFinalVerifierStatus(s) {
        s = norm(s);
        return s === 'approved'
            || s === 'rejected'
            || s === 'hold'
            || s === 'insufficient_documents'
            || s === 'waiting_candidate'
            || s === 'done'
            || s === 'completed'
            || s === 'verified'
            || s === 'clear'
            || s === 'blocked'
            || s === 'reopened';
    }

    function isUnresolvedStatus(s) {
        s = norm(s);
        return s === '' || s === 'pending' || s === 'in_progress' || s === 'invalidated_by_validator_reopen' || s === 'invalidated_by_verifier_reopen';
    }

    function isInvalidatedStatus(s) {
        s = norm(s);
        return s === 'invalidated_by_validator_reopen' || s === 'invalidated_by_verifier_reopen';
    }

    function isFinalStatus(s) {
        s = norm(s);
        return s === 'approved'
            || s === 'rejected'
            || s === 'hold'
            || s === 'insufficient_documents'
            || s === 'waiting_candidate'
            || s === 'done'
            || s === 'completed'
            || s === 'verified'
            || s === 'clear'
            || s === 'blocked'
            || s === 'reopened';
    }

    function composeContextLabel(baseLabel, upstreamLabels) {
        var base = String(baseLabel || '').trim();
        var extras = Array.isArray(upstreamLabels) ? upstreamLabels.filter(function (x) { return String(x || '').trim() !== ''; }) : [];
        if (!extras.length) return base;
        return base + ' (' + extras.join(', ') + ')';
    }

    function stageOutcomeLabel(status, role) {
        var s = norm(status);
        if (!s) return '';
        return labelByRole(s, role);
    }

    function correctionSubmittedResult(status, role) {
        if (norm(status) !== 'correction_submitted') return null;
        return { owner: role, status: 'correction_submitted', label: labelByRole('correction_submitted', role) };
    }

    function isVerifierFirstMode(mode) {
        return norm(mode) === 'verifier_first';
    }

    function resolveComponentWorkflowStatus(stageRow, opts) {
        var row = (stageRow && typeof stageRow === 'object') ? stageRow : {};
        var options = (opts && typeof opts === 'object') ? opts : {};
        var caseStatus = norm(options.case_status || options.caseStatus || '');
        var workflowMode = norm(options.workflow_mode || options.workflowMode || '');
        var val = norm(row.validator && row.validator.status);
        var ver = norm(row.verifier && row.verifier.status);
        var qa = norm(row.qa && row.qa.status);
        var cand = norm(row.candidate && row.candidate.status);
        var correctionSubmitted = correctionSubmittedResult(val, 'validator')
            || correctionSubmittedResult(ver, 'verifier')
            || correctionSubmittedResult(qa, 'qa');
        if (correctionSubmitted) return correctionSubmitted;

        // Stage-priority active owner resolution.
        // Legacy cases: validator unresolved -> verifier unresolved -> qa unresolved.
        // Verifier-first cases: verifier unresolved -> qa unresolved, with validator as compatibility-only state.
        if (isVerifierFirstMode(workflowMode)) {
            if (isUnresolvedStatus(ver)) {
                return { owner: 'verifier', status: 'pending', label: labelByRole('pending', 'verifier') };
            }
            if (isFinalStatus(ver) && isUnresolvedStatus(qa)) {
                if (isInvalidatedStatus(qa)) {
                    return { owner: 'qa', status: qa, label: labelByRole(qa, 'qa') };
                }
                var qaPendingVF = labelByRole('pending', 'qa');
                var verOutcomeVF = stageOutcomeLabel(ver, 'verifier');
                return {
                    owner: 'qa',
                    status: 'pending',
                    label: composeContextLabel(qaPendingVF, [verOutcomeVF]),
                    upstream: { validator: '', verifier: verOutcomeVF }
                };
            }
            if (qa) {
                return { owner: 'qa', status: qa, label: labelByRole(qa, 'qa') };
            }
            if (ver) {
                return { owner: 'verifier', status: ver, label: labelByRole(ver, 'verifier') };
            }
            if (val) {
                return { owner: 'verifier', status: 'pending', label: labelByRole('pending', 'verifier') };
            }
        }

        if (isUnresolvedStatus(val)) {
            return { owner: 'validator', status: 'pending', label: labelByRole('pending', 'validator') };
        }
        if (isFinalStatus(val) && isUnresolvedStatus(ver)) {
            if (isInvalidatedStatus(ver)) {
                return { owner: 'verifier', status: ver, label: labelByRole(ver, 'verifier') };
            }
            var verPending = labelByRole('pending', 'verifier');
            var valOutcome = stageOutcomeLabel(val, 'validator');
            return {
                owner: 'verifier',
                status: 'pending',
                label: composeContextLabel(verPending, [valOutcome]),
                upstream: { validator: valOutcome, verifier: '' }
            };
        }
        if (isFinalStatus(ver) && isUnresolvedStatus(qa)) {
            if (isInvalidatedStatus(qa)) {
                return { owner: 'qa', status: qa, label: labelByRole(qa, 'qa') };
            }
            var qaPending = labelByRole('pending', 'qa');
            var verOutcome = stageOutcomeLabel(ver, 'verifier');
            var valOutcome2 = stageOutcomeLabel(val, 'validator');
            return {
                owner: 'qa',
                status: 'pending',
                label: composeContextLabel(qaPending, [verOutcome, valOutcome2]),
                upstream: { validator: valOutcome2, verifier: verOutcome }
            };
        }

        // Finalized ownership resolution (closest finalized stage wins).
        if (qa && isFinalStatus(qa) && isFinalVerifierStatus(ver)) {
            return { owner: 'qa', status: qa, label: labelByRole(qa, 'qa') };
        }
        if (ver && isFinalStatus(ver) && isFinalStatus(val)) {
            return { owner: 'verifier', status: ver, label: labelByRole(ver, 'verifier') };
        }
        if (val && isFinalStatus(val)) {
            return { owner: 'validator', status: val, label: labelByRole(val, 'validator') };
        }

        // Defensive fallback when partial rows exist.
        if (qa) {
            return { owner: 'qa', status: qa, label: labelByRole(qa, 'qa') };
        }
        if (ver) {
            return { owner: 'verifier', status: ver, label: labelByRole(ver, 'verifier') };
        }
        if (val) {
            return { owner: 'validator', status: val, label: labelByRole(val, 'validator') };
        }
        if (cand) {
            return { owner: 'candidate', status: cand, label: upper(cand.replace(/_/g, ' ')) };
        }
        return { owner: '', status: '', label: '' };
    }

    function inSet(v, set) {
        return set.indexOf(norm(v)) >= 0;
    }

    function resolveStatus(row) {
        var op = norm(row && (row.operational_status || row.operational_status_label));
        if (op) return op;
        var q = norm(row && row.status);
        var c = norm(row && row.case_status);
        var cur = norm(row && row.current_stage);
        if (q) return q;
        if (c) return c;
        return cur;
    }

    function matchesFilter(row, filterKey) {
        var f = norm(filterKey || 'all');
        if (f === '' || f === 'all') return true;
        var status = resolveStatus(row);
        var active = Number(row && row.is_active_work) === 1;
        var evalVisible = Number(row && (row.evaluated_visible != null ? row.evaluated_visible : row.is_evaluated)) === 1;
        var vis = norm(row && row.visibility_class);

        if (f === 'active_work') return active || vis === 'active_work';
        if (f === 'awaiting_evaluation') return status === 'pending';
        if (f === 'waiting_candidate') return inSet(status, ['waiting_candidate', 'insufficient_documents']);
        if (f === 'evaluated') return evalVisible || vis === 'evaluated_history';
        if (f === 'reopened') return status === 'reopened';
        if (f === 'downstream_processing') return evalVisible && !active;
        if (f === 'review_complete') return inSet(status, ['approved', 'rejected', 'completed', 'done', 'clear', 'verified']);
        return true;
    }

    window.WF_UI = {
        labelByRole: labelByRole,
        badgeClass: badgeClass,
        matchesFilter: matchesFilter,
        resolveStatus: resolveStatus,
        resolveComponentWorkflowStatus: resolveComponentWorkflowStatus,
        taxonomy: TAXONOMY
    };
})();
