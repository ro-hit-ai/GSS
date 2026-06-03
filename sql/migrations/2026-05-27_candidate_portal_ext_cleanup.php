<?php
require __DIR__ . '/../../config/db.php';

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function index_exists(PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function exec_sql(PDO $pdo, string $sql): void {
    $pdo->exec($sql);
}

function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    if (!column_exists($pdo, $table, $column)) {
        exec_sql($pdo, "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        echo "Added {$table}.{$column}\n";
    }
}

add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Basic_details', 'resume_file', "varchar(255) DEFAULT NULL AFTER `photo_path`");
add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Basic_details', 'resume_original_name', "varchar(255) DEFAULT NULL AFTER `resume_file`");

add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Contact_details', 'current_proof_type', "varchar(120) DEFAULT NULL AFTER `proof_file`");
add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Contact_details', 'current_proof_file', "varchar(255) DEFAULT NULL AFTER `current_proof_type`");
add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Contact_details', 'current_proof_original_name', "varchar(255) DEFAULT NULL AFTER `current_proof_file`");
add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Contact_details', 'permanent_proof_type', "varchar(120) DEFAULT NULL AFTER `current_proof_original_name`");
add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Contact_details', 'permanent_proof_file', "varchar(255) DEFAULT NULL AFTER `permanent_proof_type`");
add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Contact_details', 'permanent_proof_original_name', "varchar(255) DEFAULT NULL AFTER `permanent_proof_file`");

add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Ecourt_Details', 'applicant_legal_name', "varchar(255) DEFAULT NULL AFTER `application_id`");
add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Ecourt_Details', 'father_name', "varchar(255) DEFAULT NULL AFTER `applicant_legal_name`");
add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Ecourt_Details', 'current_address_snapshot', "text DEFAULT NULL AFTER `father_name`");
add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Ecourt_Details', 'permanent_address_snapshot', "text DEFAULT NULL AFTER `current_address_snapshot`");
add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Ecourt_Details', 'same_as_current', "tinyint(1) NOT NULL DEFAULT '0' AFTER `permanent_address_snapshot`");

add_column_if_missing($pdo, 'Vati_Payfiller_Candidate_Identification_details', 'proof_group', "varchar(32) NOT NULL DEFAULT 'primary' AFTER `document_index`");
exec_sql($pdo, "UPDATE `Vati_Payfiller_Candidate_Identification_details` SET `proof_group`='primary' WHERE `proof_group` IS NULL OR TRIM(`proof_group`)=''");

$stmt = $pdo->query("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Vati_Payfiller_Candidate_Identification_details' AND NON_UNIQUE = 0 GROUP BY INDEX_NAME");
$uniqueIndexes = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
foreach ($uniqueIndexes as $indexName) {
    $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Vati_Payfiller_Candidate_Identification_details' AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX ASC");
    $colStmt->execute([$indexName]);
    $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($cols === ['application_id', 'document_index']) {
        exec_sql($pdo, "ALTER TABLE `Vati_Payfiller_Candidate_Identification_details` DROP INDEX `{$indexName}`");
        echo "Dropped unique index {$indexName}\n";
    }
}

if (!index_exists($pdo, 'Vati_Payfiller_Candidate_Identification_details', 'uniq_identification_app_doc_group')) {
    exec_sql($pdo, "ALTER TABLE `Vati_Payfiller_Candidate_Identification_details` ADD UNIQUE KEY `uniq_identification_app_doc_group` (`application_id`, `document_index`, `proof_group`)");
    echo "Added uniq_identification_app_doc_group\n";
}
if (!index_exists($pdo, 'Vati_Payfiller_Candidate_Identification_details', 'idx_identification_app_doc')) {
    exec_sql($pdo, "ALTER TABLE `Vati_Payfiller_Candidate_Identification_details` ADD KEY `idx_identification_app_doc` (`application_id`, `document_index`)");
    echo "Added idx_identification_app_doc\n";
}

if (table_exists($pdo, 'Vati_Payfiller_Candidate_Basic_Ext')) {
    exec_sql($pdo, "INSERT INTO `Vati_Payfiller_Candidate_Basic_details` (`application_id`, `resume_file`, `resume_original_name`) SELECT be.`application_id`, be.`resume_file`, be.`resume_original_name` FROM `Vati_Payfiller_Candidate_Basic_Ext` be LEFT JOIN `Vati_Payfiller_Candidate_Basic_details` bd ON bd.`application_id` = be.`application_id` WHERE bd.`application_id` IS NULL");
    exec_sql($pdo, "UPDATE `Vati_Payfiller_Candidate_Basic_details` bd JOIN `Vati_Payfiller_Candidate_Basic_Ext` be ON be.`application_id` = bd.`application_id` SET bd.`resume_file` = COALESCE(NULLIF(be.`resume_file`, ''), bd.`resume_file`), bd.`resume_original_name` = COALESCE(NULLIF(be.`resume_original_name`, ''), bd.`resume_original_name`)");
    exec_sql($pdo, "DROP TABLE `Vati_Payfiller_Candidate_Basic_Ext`");
    echo "Dropped Vati_Payfiller_Candidate_Basic_Ext\n";
}

if (table_exists($pdo, 'Vati_Payfiller_Candidate_Contact_Ext')) {
    exec_sql($pdo, "UPDATE `Vati_Payfiller_Candidate_Contact_details` cd JOIN `Vati_Payfiller_Candidate_Contact_Ext` ce ON ce.`application_id` = cd.`application_id` SET cd.`current_proof_type` = COALESCE(NULLIF(ce.`current_proof_type`, ''), cd.`current_proof_type`, cd.`proof_type`), cd.`current_proof_file` = COALESCE(NULLIF(ce.`current_proof_file`, ''), cd.`current_proof_file`, cd.`proof_file`), cd.`current_proof_original_name` = COALESCE(NULLIF(ce.`current_proof_original_name`, ''), cd.`current_proof_original_name`), cd.`permanent_proof_type` = COALESCE(NULLIF(ce.`permanent_proof_type`, ''), cd.`permanent_proof_type`), cd.`permanent_proof_file` = COALESCE(NULLIF(ce.`permanent_proof_file`, ''), cd.`permanent_proof_file`), cd.`permanent_proof_original_name` = COALESCE(NULLIF(ce.`permanent_proof_original_name`, ''), cd.`permanent_proof_original_name`)");
    exec_sql($pdo, "UPDATE `Vati_Payfiller_Candidate_Contact_details` SET `current_proof_type` = COALESCE(NULLIF(`current_proof_type`, ''), `proof_type`), `current_proof_file` = COALESCE(NULLIF(`current_proof_file`, ''), `proof_file`) WHERE `application_id` IS NOT NULL");
    exec_sql($pdo, "DROP TABLE `Vati_Payfiller_Candidate_Contact_Ext`");
    echo "Dropped Vati_Payfiller_Candidate_Contact_Ext\n";
}

if (table_exists($pdo, 'Vati_Payfiller_Candidate_Ecourt_Ext')) {
    exec_sql($pdo, "INSERT INTO `Vati_Payfiller_Candidate_Ecourt_Details` (`application_id`, `applicant_legal_name`, `father_name`, `current_address_snapshot`, `permanent_address_snapshot`, `same_as_current`) SELECT ee.`application_id`, ee.`applicant_legal_name`, ee.`father_name`, ee.`current_address_snapshot`, ee.`permanent_address_snapshot`, ee.`same_as_current` FROM `Vati_Payfiller_Candidate_Ecourt_Ext` ee LEFT JOIN `Vati_Payfiller_Candidate_Ecourt_Details` ed ON ed.`application_id` = ee.`application_id` WHERE ed.`application_id` IS NULL");
    exec_sql($pdo, "UPDATE `Vati_Payfiller_Candidate_Ecourt_Details` ed JOIN `Vati_Payfiller_Candidate_Ecourt_Ext` ee ON ee.`application_id` = ed.`application_id` SET ed.`applicant_legal_name` = COALESCE(NULLIF(ee.`applicant_legal_name`, ''), ed.`applicant_legal_name`), ed.`father_name` = COALESCE(NULLIF(ee.`father_name`, ''), ed.`father_name`), ed.`current_address_snapshot` = COALESCE(NULLIF(ee.`current_address_snapshot`, ''), ed.`current_address_snapshot`), ed.`permanent_address_snapshot` = COALESCE(NULLIF(ee.`permanent_address_snapshot`, ''), ed.`permanent_address_snapshot`), ed.`same_as_current` = COALESCE(ee.`same_as_current`, ed.`same_as_current`)");
    exec_sql($pdo, "DROP TABLE `Vati_Payfiller_Candidate_Ecourt_Ext`");
    echo "Dropped Vati_Payfiller_Candidate_Ecourt_Ext\n";
}

if (table_exists($pdo, 'Vati_Payfiller_Candidate_Identification_Ext')) {
    exec_sql($pdo, "UPDATE `Vati_Payfiller_Candidate_Identification_details` d JOIN `Vati_Payfiller_Candidate_Identification_Ext` e ON e.`application_id` = d.`application_id` AND e.`document_index` = d.`document_index` AND e.`proof_group` = 'primary' SET d.`proof_group` = 'primary', d.`documentId_type` = COALESCE(NULLIF(e.`document_type`, ''), d.`documentId_type`), d.`id_number` = COALESCE(NULLIF(e.`id_number`, ''), d.`id_number`), d.`name` = COALESCE(NULLIF(e.`name`, ''), d.`name`), d.`issue_date` = COALESCE(e.`issue_date`, d.`issue_date`), d.`expiry_date` = COALESCE(e.`expiry_date`, d.`expiry_date`), d.`upload_document` = COALESCE(NULLIF(e.`upload_document`, ''), d.`upload_document`)");
    exec_sql($pdo, "INSERT INTO `Vati_Payfiller_Candidate_Identification_details` (`application_id`, `document_index`, `proof_group`, `documentId_type`, `id_number`, `name`, `upload_document`, `country`, `issue_date`, `expiry_date`) SELECT e.`application_id`, e.`document_index`, e.`proof_group`, e.`document_type`, e.`id_number`, e.`name`, e.`upload_document`, NULL, e.`issue_date`, e.`expiry_date` FROM `Vati_Payfiller_Candidate_Identification_Ext` e LEFT JOIN `Vati_Payfiller_Candidate_Identification_details` d ON d.`application_id` = e.`application_id` AND d.`document_index` = e.`document_index` AND d.`proof_group` = e.`proof_group` WHERE d.`id` IS NULL");
    exec_sql($pdo, "DROP TABLE `Vati_Payfiller_Candidate_Identification_Ext`");
    echo "Dropped Vati_Payfiller_Candidate_Identification_Ext\n";
}

echo "migration-ok\n";
