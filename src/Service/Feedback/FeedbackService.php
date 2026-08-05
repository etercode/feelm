<?php

namespace App\Service\Feedback;

use App\Entity\Feedback;
use App\Entity\FeedbackImage;
use App\Entity\User;
use App\Repository\FeedbackRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The rules about who may change a report, in one place.
 *
 * Every one of these throws \InvalidArgumentException with a snake_case code
 * that the controllers hand straight back as {"error": "..."} — the convention
 * the rest of the API already uses. Nothing here decides an HTTP status; that
 * is the controller's business.
 */
class FeedbackService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FeedbackRepository $feedback,
        private readonly FeedbackImageStorage $images,
    ) {
    }

    public function create(User $user, string $category, string $body): Feedback
    {
        $report = (new Feedback())
            ->setUser($user)
            ->setCategory($category)
            ->setBody($body);

        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return $report;
    }

    /**
     * @throws \InvalidArgumentException locked_by_admin|not_yours
     */
    public function updateByAuthor(Feedback $report, User $user, ?string $category, ?string $body): Feedback
    {
        $this->assertAuthor($report, $user);

        if (!$report->isEditableByAuthor()) {
            throw new \InvalidArgumentException('locked_by_admin');
        }

        if (null !== $category) {
            $report->setCategory($category);
        }
        if (null !== $body) {
            $report->setBody($body);
        }

        $this->entityManager->flush();

        return $report;
    }

    /** @throws \InvalidArgumentException locked_by_admin|not_yours */
    public function deleteByAuthor(Feedback $report, User $user): void
    {
        $this->assertAuthor($report, $user);

        if (!$report->isEditableByAuthor()) {
            throw new \InvalidArgumentException('locked_by_admin');
        }

        $this->deleteWithFiles($report);
    }

    /**
     * Attaches a screenshot.
     *
     * The cap is checked against what is stored rather than what was sent, so
     * four separate one-image requests are refused the same way one request
     * with five images would be.
     *
     * @throws \InvalidArgumentException too_many_images|locked_by_admin|not_yours|<storage codes>
     */
    public function addImage(Feedback $report, User $user, UploadedFile $file): FeedbackImage
    {
        $this->assertAuthor($report, $user);

        if (!$report->isEditableByAuthor()) {
            throw new \InvalidArgumentException('locked_by_admin');
        }

        if ($report->getImages()->count() >= Feedback::MAX_IMAGES) {
            throw new \InvalidArgumentException('too_many_images');
        }

        $image = $this->images->store($report, $file, $report->getImages()->count());
        $report->addImage($image);

        $this->entityManager->persist($image);
        $this->entityManager->flush();

        return $image;
    }

    /** @throws \InvalidArgumentException locked_by_admin|not_yours|image_not_found */
    public function removeImage(Feedback $report, User $user, int $imageId): void
    {
        $this->assertAuthor($report, $user);

        if (!$report->isEditableByAuthor()) {
            throw new \InvalidArgumentException('locked_by_admin');
        }

        foreach ($report->getImages() as $image) {
            if ($image->getId() === $imageId) {
                $this->images->discard($image->getPath());
                $report->removeImage($image);
                $this->entityManager->remove($image);
                $this->entityManager->flush();

                return;
            }
        }

        throw new \InvalidArgumentException('image_not_found');
    }

    /** Administrators only — the caller has already been through #[IsGranted]. */
    public function moderate(Feedback $report, string $status, ?string $note = null): Feedback
    {
        $report->setStatus($status);

        if (null !== $note) {
            $report->setNote($note);
        }

        $this->entityManager->flush();

        return $report;
    }

    public function updateByAdmin(Feedback $report, ?string $category, ?string $body, ?string $note): Feedback
    {
        if (null !== $category) {
            $report->setCategory($category);
        }
        if (null !== $body) {
            $report->setBody($body);
        }
        if (null !== $note) {
            $report->setNote($note);
        }

        $this->entityManager->flush();

        return $report;
    }

    public function deleteByAdmin(Feedback $report): void
    {
        $this->deleteWithFiles($report);
    }

    /**
     * Files first, then the row.
     *
     * The other order can leave a file nobody points at, which nothing will
     * ever clean up because the record that named it is gone. This order can
     * at worst leave a row whose file is already missing, which every reader
     * here copes with.
     */
    private function deleteWithFiles(Feedback $report): void
    {
        foreach ($report->getImages() as $image) {
            $this->images->discard($image->getPath());
        }

        $this->entityManager->remove($report);
        $this->entityManager->flush();
    }

    /** @throws \InvalidArgumentException not_yours */
    private function assertAuthor(Feedback $report, User $user): void
    {
        if ($report->getUser()?->getId() !== $user->getId()) {
            throw new \InvalidArgumentException('not_yours');
        }
    }
}
