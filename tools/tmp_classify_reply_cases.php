<?php
require 'config/db.php';
$pdo = getDB();
$sql = "SELECT application_id, component_key, COALESCE(thread_id,'') AS thread_id, direction, COALESCE(thread_owner_role,'') AS thread_owner_role, COALESCE(actor_role,'') AS actor_role, COUNT(*) AS cnt
        FROM Vati_Payfiller_Workflow_Communications
        WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY application_id, component_key, COALESCE(thread_id,''), direction, COALESCE(thread_owner_role,''), COALESCE(actor_role,'')
        ORDER BY application_id, component_key, thread_id, direction";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$apps = [];
foreach ($rows as $r) {
    $app = trim((string)$r['application_id']);
    $comp = strtolower(trim((string)$r['component_key']));
    $thread = trim((string)$r['thread_id']);
    if ($app === '' || $comp === '') continue;
    if (!isset($apps[$app])) $apps[$app] = [];
    if (!isset($apps[$app][$comp])) $apps[$app][$comp] = ['threads'=>[], 'outgoing_roles'=>[], 'incoming_owner_roles'=>[], 'incoming_unowned'=>0, 'notes'=>[]];
    $bucket =& $apps[$app][$comp];
    if (!isset($bucket['threads'][$thread])) $bucket['threads'][$thread] = ['outgoing_roles'=>[], 'incoming_owner_roles'=>[], 'incoming_unowned'=>0];
    $t =& $bucket['threads'][$thread];
    $dir = strtolower(trim((string)$r['direction']));
    $owner = strtolower(trim((string)$r['thread_owner_role']));
    $actor = strtolower(trim((string)$r['actor_role']));
    $cnt = (int)$r['cnt'];
    if ($dir === 'outgoing') {
        if ($owner !== '') { $bucket['outgoing_roles'][$owner] = true; $t['outgoing_roles'][$owner] = true; }
        elseif ($actor !== '') { $bucket['outgoing_roles'][$actor] = true; $t['outgoing_roles'][$actor] = true; }
    } elseif ($dir === 'incoming') {
        if ($owner !== '') { $bucket['incoming_owner_roles'][$owner] = true; $t['incoming_owner_roles'][$owner] = true; }
        else { $bucket['incoming_unowned'] += $cnt; $t['incoming_unowned'] += $cnt; }
    }
}
$out = [];
foreach ($apps as $app => $components) {
  foreach ($components as $comp => $bucket) {
    $status = 'clean';
    $reason = [];
    $threadCount = 0;
    foreach ($bucket['threads'] as $threadId => $t) {
      if ($threadId === '') continue;
      $threadCount++;
      if (count($t['outgoing_roles']) > 1) { $status = 'ambiguous'; $reason[] = 'shared_thread_multi_roles'; }
      if ($t['incoming_unowned'] > 0 && count($t['outgoing_roles']) > 1) { $status = 'ambiguous'; $reason[] = 'unowned_incoming_on_shared_thread'; }
    }
    if ($status !== 'ambiguous') {
      if ($bucket['incoming_unowned'] > 0) {
        if (count($bucket['outgoing_roles']) === 1) {
          $status = 'repairable'; $reason[] = 'single_role_unowned_incoming';
        } elseif (count($bucket['outgoing_roles']) === 0) {
          $status = 'orphaned'; $reason[] = 'incoming_without_outgoing';
        } else {
          $status = 'ambiguous'; $reason[] = 'unowned_incoming_multi_role_component';
        }
      }
    }
    if ($status === 'clean' && count($bucket['outgoing_roles']) === 0 && !empty($bucket['incoming_owner_roles'])) {
      $status = 'legacy-owned'; $reason[] = 'owned_incoming_only';
    }
    $out[] = [
      'application_id' => $app,
      'component_key' => $comp,
      'status' => $status,
      'outgoing_roles' => array_values(array_keys($bucket['outgoing_roles'])),
      'incoming_owner_roles' => array_values(array_keys($bucket['incoming_owner_roles'])),
      'incoming_unowned' => $bucket['incoming_unowned'],
      'thread_count' => $threadCount,
      'reasons' => array_values(array_unique($reason)),
    ];
  }
}
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);