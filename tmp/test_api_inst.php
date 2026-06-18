<?php
$ch = curl_init('http://localhost/GSS/api/master/institutions/search.php?limit=100');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
echo "API response:\n";
echo substr($res, 0, 500);
