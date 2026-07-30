<?php

namespace App\Presenter;

use App\Entity\Entry;

final class EntryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function one(Entry $entry): array
    {
        return [
            'id' => $entry->getId(),
            'userId' => $entry->getUser()?->getId(),
            'itemId' => $entry->getWork()?->getId(),
            'status' => $entry->getStatus(),
            'rating' => $entry->getRating(),
            'progress' => $entry->getProgress(),
            'at' => $entry->getUpdatedAt()?->format('Y-m-d'),
            'updatedAt' => $entry->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
