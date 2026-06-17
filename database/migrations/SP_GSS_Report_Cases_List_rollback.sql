-- ============================================================
-- Rollback: SP_Vati_Payfiller_GSS_Report_Cases_List
-- Run this to remove the stored procedure.
-- Also revert api/gssadmin/reports/candidate_component_report.php
-- to its pre-refactor version from version control.
-- ============================================================

DROP PROCEDURE IF EXISTS SP_Vati_Payfiller_GSS_Report_Cases_List;
DROP PROCEDURE IF EXISTS SP_GSS_Report_Cases_List;
