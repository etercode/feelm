<?php

namespace App\Service\Admin;

use App\Dto\Admin\AdminUserRequest;
use App\Entity\User;
use App\Repository\AccessTokenRepository;
use App\Repository\UserRepository;
use App\Service\User\AvatarStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Everything the admin does to an account, and the rules about what it may not.
 *
 * The rules matter more than the writes. An administrator holds the only key to
 * the room, so the ways to lock yourself out of it — demoting yourself,
 * deleting yourself, removing the last administrator — are all refused here
 * rather than in the controller, where each would have to be remembered
 * separately for create, patch and delete.
 *
 * Failures are \InvalidArgumentException with a snake_case code, which is how
 * the rest of this application reports a domain error.
 */
final class UserAdmin
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccessTokenRepository $accessTokens,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AvatarStorage $avatars,
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function create(AdminUserRequest $payload, User $actor): User
    {
        $username = trim((string) $payload->username);
        $name = trim((string) $payload->name);

        if ('' === $username) {
            throw new \InvalidArgumentException('username_required');
        }
        if ('' === $name) {
            throw new \InvalidArgumentException('name_required');
        }
        if (null === $payload->password || '' === $payload->password) {
            throw new \InvalidArgumentException('password_required');
        }
        if ($this->users->existsActiveByUsername($username)) {
            throw new \InvalidArgumentException('username_already_used');
        }

        $user = (new User())
            ->setUsername($username)
            ->setName($name)
            ->setEmailVerified(false)
            ->setHandlePending(false);

        $user->setPassword($this->passwordHasher->hashPassword($user, $payload->password));

        $this->applyEmail($user, $payload);
        $this->applyProfile($user, $payload);
        $this->applyRoles($user, $payload, $actor);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function update(User $user, AdminUserRequest $payload, User $actor): User
    {
        if (null !== $payload->username) {
            $wanted = trim($payload->username);
            if ('' === $wanted) {
                throw new \InvalidArgumentException('username_required');
            }
            if ($wanted !== $user->getUsername() && $this->users->existsActiveByUsername($wanted)) {
                throw new \InvalidArgumentException('username_already_used');
            }
            // Renaming settles the question the welcome screen was asking.
            $user->setUsername($wanted)->setHandlePending(false);
        }

        if (null !== $payload->name) {
            $name = trim($payload->name);
            if ('' === $name) {
                throw new \InvalidArgumentException('name_required');
            }
            $user->setName($name);
        }

        $this->applyEmail($user, $payload);
        $this->applyProfile($user, $payload);
        $this->applyRoles($user, $payload, $actor);

        $this->entityManager->flush();

        return $user;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function setPassword(User $user, string $password): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        // Whoever was signed in as them was, by assumption, the problem.
        $this->accessTokens->revokeAllFor($user);
        $this->entityManager->flush();
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function delete(User $user, User $actor): void
    {
        if ($user->getId() === $actor->getId()) {
            throw new \InvalidArgumentException('cannot_delete_self');
        }
        if ($user->isDeleted()) {
            throw new \InvalidArgumentException('already_deleted');
        }

        $user->softDelete();

        /*
         * The bearer token would otherwise keep working for up to an hour.
         * UserChecker rejects deleted accounts on the next request either way,
         * but revoking is the part that does not depend on remembering to.
         */
        $this->accessTokens->revokeAllFor($user);
        $this->entityManager->flush();
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function restore(User $user): void
    {
        if (!$user->isDeleted()) {
            throw new \InvalidArgumentException('not_deleted');
        }

        /*
         * The handle and address were only free because this row was out of the
         * way. Somebody may have taken either in the meantime, and the partial
         * unique indexes would reject the flush with a driver error rather than
         * anything a person could read.
         */
        if ($this->users->existsActiveByUsername((string) $user->getUsername())) {
            throw new \InvalidArgumentException('username_already_used');
        }
        if (null !== $user->getEmail() && $this->users->existsActiveByEmail($user->getEmail())) {
            throw new \InvalidArgumentException('email_already_used');
        }

        $user->setDeletedAt(null);
        $this->entityManager->flush();
    }

    public function removeAvatar(User $user): void
    {
        $this->avatars->discard($user->getAvatar());
        $user->setAvatar(null);
        $this->entityManager->flush();
    }

    /* ------------------------------------------------------------- private */

    private function applyEmail(User $user, AdminUserRequest $payload): void
    {
        if (null !== $payload->email) {
            $email = trim($payload->email);
            $email = '' === $email ? null : $email;

            if (null !== $email && mb_strtolower($email) !== $user->getEmail() && $this->users->existsActiveByEmail($email)) {
                throw new \InvalidArgumentException('email_already_used');
            }

            $user->setEmail($email);

            // An address nobody has proven cannot stay marked as proven.
            if (null === $email) {
                $user->setEmailVerified(false);
            }
        }

        if (null !== $payload->emailVerified) {
            if ($payload->emailVerified && null === $user->getEmail()) {
                throw new \InvalidArgumentException('no_email_to_verify');
            }
            $user->setEmailVerified($payload->emailVerified);
        }
    }

    private function applyProfile(User $user, AdminUserRequest $payload): void
    {
        if (null !== $payload->tagline) {
            $user->setTagline($this->cleaned($payload->tagline));
        }
        if (null !== $payload->bio) {
            $user->setBio($this->cleaned($payload->bio));
        }
        if (null !== $payload->location) {
            $user->setLocation($this->cleaned($payload->location));
        }
    }

    /**
     * Roles are the one field a moderator may not touch, even though they can
     * reach every other part of this form.
     */
    private function applyRoles(User $user, AdminUserRequest $payload, User $actor): void
    {
        if (null === $payload->roles) {
            return;
        }

        if (!$actor->isAdmin()) {
            throw new \InvalidArgumentException('roles_require_admin');
        }

        $wanted = array_values(array_unique(array_filter(
            $payload->roles,
            static fn (string $role) => \in_array($role, User::ASSIGNABLE_ROLES, true),
        )));

        /*
         * Refusing self-demotion is the whole of the "there is always an
         * administrator" rule, and it is enough. Only an administrator reaches
         * this code, so demoting somebody else always leaves the one doing it
         * — a separate "last administrator" check would never fire. The way
         * back from an empty room is app:user:role, not a guard here.
         */
        if ($user->getId() === $actor->getId() && $user->isAdmin() && !\in_array(User::ROLE_ADMIN, $wanted, true)) {
            throw new \InvalidArgumentException('cannot_demote_self');
        }

        $user->setRoles($wanted);
    }

    /** Cleared inputs arrive as empty strings; the columns want null. */
    private function cleaned(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
