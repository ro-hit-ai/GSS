<?php
declare(strict_types=1);

// Lightweight semantic guard for collaborative verifier-group behavior.
// This intentionally validates the current queue-bucket model without touching the workflow engine.

$root = dirname(__DIR__);

require_once $root . '/api/shared/workflow/workflow_semantics.php';
require_once $root . '/api/verifier/queue_visibility.php';

$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(
    verifier_can_group_by_sections(['ecourt' => true], 'ADDITIONAL'),
    'Verifier with ecourt only should be able to participate in ADDITIONAL.'
);
$assert(
    verifier_can_group_by_sections(['socialmedia' => true], 'ADDITIONAL'),
    'Verifier with socialmedia only should be able to participate in ADDITIONAL.'
);
$assert(
    verifier_can_group_by_sections(['education' => true], 'EDUCATION'),
    'Verifier with education only should be able to participate in EDUCATION.'
);
$assert(
    !verifier_can_group_by_sections(['reference' => true], 'ADDITIONAL'),
    'Verifier without ADDITIONAL sections must not be eligible for ADDITIONAL.'
);

$componentAction = file_get_contents($root . '/api/shared/case_management/component_action.php') ?: '';
$projectionService = file_get_contents($root . '/api/shared/workflow/WorkflowProjectionService.php') ?: '';

$assert(
    strpos($componentAction, 'limited-section verifiers can never complete a mixed group') !== false,
    'Historical collaborative verifier comment is missing from component_action.php.'
);
$assert(
    strpos($projectionService, 'assigned_verifier_disallowed_section') === false,
    'Projection service still drops group components based on assigned verifier sections.'
);
$assert(
    strpos($projectionService, 'collaborative_group_semantics') !== false,
    'Projection service collaborative semantic guard marker is missing.'
);

if ($failures) {
    fwrite(STDERR, "Collaborative verifier semantic guard failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "Collaborative verifier semantic guard passed.\n");
exit(0);
