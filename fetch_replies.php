<?php
// Canonical CLI entrypoint for reply ingestion.
// Keep the historical cron path stable, but route execution through the
// richer mailbox parser that persists reply threading metadata.
require __DIR__ . '/js/modules/shared/GSS/fetch_replies.php';
