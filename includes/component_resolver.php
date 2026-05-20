<?php

// DEPRECATED FOR WORKFLOW COMPONENT GENERATION:
// Verification type -> component mapping ownership has moved to
// api/shared/case_component_binding.php::case_component_binding_map_verification_type_to_components().
// Keep this file only for legacy non-workflow display helpers until fully retired.

function component_registry(): array
{
    static $registry = null;
    if ($registry === null) {
        $registry = require __DIR__ . '/../config/component_registry.php';
    }
    return is_array($registry) ? $registry : [];
}

function component_normalize_text(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim((string)$value);
}

function component_build_search_text(string $typeName, string $typeCategory = ''): string
{
    return component_normalize_text(trim($typeName . ' ' . $typeCategory));
}

function component_meta_from_registry_key(string $key): array
{
    $registry = component_registry();
    $meta = isset($registry[$key]) && is_array($registry[$key]) ? $registry[$key] : [];

    return [
        'component_key' => $key,
        'display_label' => (string)($meta['label'] ?? $key),
        'candidate_page' => (string)($meta['candidate_page'] ?? ''),
        'candidate_subsection' => (string)($meta['candidate_subsection'] ?? ''),
        'allowed_section_key' => (string)($meta['allowed_section_key'] ?? ''),
        'group_key' => (string)($meta['group_key'] ?? ''),
        'count_applicable' => !empty($meta['count_applicable']),
    ];
}

function component_resolve_by_alias(string $searchText): ?array
{
    if ($searchText === '') {
        return null;
    }

    foreach (component_registry() as $key => $meta) {
        $aliases = isset($meta['aliases']) && is_array($meta['aliases']) ? $meta['aliases'] : [];
        foreach ($aliases as $alias) {
            $aliasNorm = component_normalize_text((string)$alias);
            if ($aliasNorm === '') {
                continue;
            }
            if ($searchText === $aliasNorm) {
                return component_meta_from_registry_key((string)$key);
            }
        }
    }

    return null;
}

function component_text_has_any(string $searchText, array $needles): bool
{
    foreach ($needles as $needle) {
        $needleNorm = component_normalize_text((string)$needle);
        if ($needleNorm !== '' && strpos($searchText, $needleNorm) !== false) {
            return true;
        }
    }
    return false;
}

function component_resolve_by_heuristics(string $searchText): ?array
{
    // Deterministic mode: heuristic resolution is intentionally disabled.
    return null;
}

function resolve_component_meta(string $typeName, string $typeCategory = ''): array
{
    $searchText = component_build_search_text($typeName, $typeCategory);
    $heuristic = component_resolve_by_heuristics($searchText);
    if ($heuristic !== null) {
        return $heuristic;
    }
    $resolved = component_resolve_by_alias($searchText);
    if ($resolved !== null) {
        return $resolved;
    }

    return [
        'component_key' => '',
        'display_label' => trim($typeName) !== '' ? trim($typeName) : trim($typeCategory),
        'candidate_page' => '',
        'candidate_subsection' => '',
        'allowed_section_key' => '',
        'group_key' => '',
        'count_applicable' => false,
    ];
}

function resolve_component_key(string $typeName, string $typeCategory = ''): string
{
    $meta = resolve_component_meta($typeName, $typeCategory);
    return (string)($meta['component_key'] ?? '');
}

function resolve_component_label(string $typeName, string $typeCategory = ''): string
{
    $meta = resolve_component_meta($typeName, $typeCategory);
    return (string)($meta['display_label'] ?? trim($typeName));
}

function resolve_component_page(string $typeName, string $typeCategory = ''): string
{
    $meta = resolve_component_meta($typeName, $typeCategory);
    return (string)($meta['candidate_page'] ?? '');
}

function resolve_component_subsection(string $typeName, string $typeCategory = ''): string
{
    $meta = resolve_component_meta($typeName, $typeCategory);
    return (string)($meta['candidate_subsection'] ?? '');
}
