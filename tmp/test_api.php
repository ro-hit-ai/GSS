<?php
$ch = curl_init('http://localhost/GSS/api/master/institutions/search.php?list=university_board');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
echo "API response:\n";
echo substr($res, 0, 500);
