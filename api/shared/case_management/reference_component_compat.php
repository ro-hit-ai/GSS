<?php

if (!function_exists('reference_compat_norm_key')) {
    function reference_compat_norm_key(string $componentKey): string
    {
        $key = strtolower(trim($componentKey));
        $key = str_replace(['-', ' '], '_', $key);
        if ($key === 'educationreference' || $key === 'education_ref') return 'education_reference';
        if ($key === 'employmentreference' || $key === 'employment_ref') return 'employment_reference';
        if ($key === 'ref') return 'reference';
        return $key;
    }
}

if (!function_exists('reference_compat_effective_keys')) {
    function reference_compat_effective_keys(array $keys): array
    {
        $seen = [];
        $ordered = [];
        $hasSplitReference = false;

        foreach ($keys as $key) {
            $normalized = reference_compat_norm_key((string)$key);
            if ($normalized === '') continue;
            if ($normalized === 'education_reference' || $normalized === 'employment_reference') {
                $hasSplitReference = true;
            }
            if (isset($seen[$normalized])) continue;
            $seen[$normalized] = true;
            $ordered[] = $normalized;
        }

        if (!$hasSplitReference) {
            return $ordered;
        }

        return array_values(array_filter($ordered, static function (string $key): bool {
            return $key !== 'reference';
        }));
    }
}

if (!function_exists('reference_compat_filter_rows')) {
    function reference_compat_filter_rows(array $rows, string $field = 'component_key'): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $keys[] = (string)($row[$field] ?? '');
        }

        $effective = array_flip(reference_compat_effective_keys($keys));
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = reference_compat_norm_key((string)($row[$field] ?? ''));
            if ($key === '' || !isset($effective[$key]) || isset($seen[$key])) continue;
            $seen[$key] = true;
            $row[$field] = $key;
            $out[] = $row;
        }

        return $out;
    }
}

if (!function_exists('reference_compat_effective_component_map')) {
    function reference_compat_effective_component_map(array $map): array
    {
        $effective = array_flip(reference_compat_effective_keys(array_keys($map)));
        $out = [];

        foreach ($map as $key => $value) {
            $normalized = reference_compat_norm_key((string)$key);
            if ($normalized === '' || !isset($effective[$normalized]) || array_key_exists($normalized, $out)) continue;
            $out[$normalized] = $value;
        }

        return $out;
    }
}

if (!function_exists('reference_compat_apply_to_routing_state')) {
    function reference_compat_apply_to_routing_state(array $state): array
    {
        if (isset($state['components']) && is_array($state['components'])) {
            $state['components'] = reference_compat_effective_component_map($state['components']);
        }

        foreach ([
            'visible_sections',
            'owned_active_components',
            'claimable_next_components',
            'locked_future_components',
            'hidden_unrelated_components',
            'completed_components',
        ] as $key) {
            if (isset($state[$key]) && is_array($state[$key])) {
                $state[$key] = reference_compat_effective_keys($state[$key]);
            }
        }

        return $state;
    }
}
