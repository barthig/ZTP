<?php
declare(strict_types=1);

$handle = curl_init('http://127.0.0.1/api/v1/products');
curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
$body = curl_exec($handle);
$status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
exit($status === 200 && is_string($body) && is_array(json_decode($body, true)) ? 0 : 1);
