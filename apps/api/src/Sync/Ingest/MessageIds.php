<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

/**
 * Message-IDs as stored: trimmed, without angle brackets. Comparison stays exact (the local part is case-sensitive).
 */
final class MessageIds
{
    public static function normalize(?string $messageId): ?string
    {
        if (null === $messageId) {
            return null;
        }
        $id = trim($messageId);
        if (str_starts_with($id, '<') && str_ends_with($id, '>')) {
            $id = trim(substr($id, 1, -1));
        }

        return '' === $id ? null : $id;
    }

    /**
     * @param list<string|null> $messageIds
     *
     * @return list<string> unique, normalized, without $exclude
     */
    public static function normalizeList(array $messageIds, ?string $exclude = null): array
    {
        $normalized = [];
        foreach ($messageIds as $messageId) {
            $id = self::normalize($messageId);
            if (null !== $id && $id !== $exclude) {
                $normalized[$id] = true;
            }
        }

        return array_keys($normalized);
    }
}
