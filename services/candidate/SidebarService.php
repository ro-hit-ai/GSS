<?php

final class SidebarService
{
    public const ORDER = [
        'basic-details',
        'identification',
        'contact',
        'education',
        'employment',
        'ecourt',
        'social',
        'reference',
    ];

    public static function sortSections(array $sections): array
    {
        $rank = array_flip(self::ORDER);
        usort($sections, static function (array $left, array $right) use ($rank): int {
            return ($rank[$left['key'] ?? ''] ?? 99) <=> ($rank[$right['key'] ?? ''] ?? 99);
        });
        return $sections;
    }

    public static function issuePriority(string $type): int
    {
        return [
            'correction_required' => 1,
            'document_rejected' => 2,
            'mandatory_document_missing' => 3,
            'document_missing' => 3,
            'waiting_mobile_upload' => 4,
            'invalid_format' => 5,
            'invalid_date' => 5,
            'date_overlap' => 5,
            'future_date' => 5,
            'missing_required_field' => 6,
            'incomplete_data' => 7,
        ][$type] ?? 99;
    }
}
