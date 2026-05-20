<?php
session_start();
$_SESSION['auth_moduleAccess']='gss_admin';
$_SESSION['auth_user_id']=1;
$_SERVER['REQUEST_METHOD']='GET';
$_GET['role']='gss_admin';
$_GET['application_id']='APP-20260520075926356';
ob_start();
include 'api/shared/candidate_report_get.php';
$out=ob_get_clean();
echo $out;
