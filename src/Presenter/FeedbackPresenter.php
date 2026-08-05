<?php

namespace App\Presenter;

use App\Entity\Feedback;

final class FeedbackPresenter
{
    /**
     * What the author sees of their own report.
     *
     * `canEdit` is sent rather than left for the client to derive from the
     * status. The rule is the server's, and a browser that works it out
     * independently is a second copy of it that can drift — and would, the
     * first time a status is added.
     *
     * @return array<string, mixed>
     */
    public static function one(Feedback $report): array
    {
        return [
            'id' => $report->getId(),
            'category' => $report->getCategory(),
            'status' => $report->getStatus(),
            'body' => $report->getBody(),
            'note' => $report->getNote(),
            'canEdit' => $report->isEditableByAuthor(),
            'images' => self::images($report),
            'createdAt' => $report->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $report->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'acceptedAt' => $report->getAcceptedAt()?->format(\DateTimeInterface::ATOM),
            'resolvedAt' => $report->getResolvedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * The same, plus who wrote it.
     *
     * @return array<string, mixed>
     */
    public static function admin(Feedback $report): array
    {
        $user = $report->getUser();

        return self::one($report) + [
            'user' => null === $user ? null : [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'name' => $user->getName(),
                'avatar' => $user->getAvatar(),
            ],
        ];
    }

    /**
     * A purged image keeps its place and says so, rather than being dropped.
     *
     * A report whose screenshots have been reclaimed should read as "there were
     * two here" and not as one that never had any — otherwise a thread stops
     * making sense the moment the disk is tidied.
     *
     * @return list<array<string, mixed>>
     */
    private static function images(Feedback $report): array
    {
        $out = [];

        foreach ($report->getImages() as $image) {
            $out[] = [
                'id' => $image->getId(),
                'url' => $image->isPurged() ? null : $image->getPath(),
                'purged' => $image->isPurged(),
            ];
        }

        return $out;
    }
}
