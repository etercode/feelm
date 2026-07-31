<?php

namespace App\Service\Auth;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns verified Google claims into one of our accounts.
 *
 * Three cases, in this order:
 *
 *   1. We have seen this Google account before — sign that user in.
 *   2. We have not, but an account holds the same address AND Google says the
 *      address is verified — link them.
 *   3. Otherwise — make a new account.
 *
 * The verified check in case 2 is the whole defence against a known attack: if
 * matching on the address alone were enough, anybody could register with
 * somebody else's address today and be handed their account the day that person
 * first signs in with Google. Google saying "we have confirmed they own this
 * mailbox" is what makes the link safe. An unverified address falls through to
 * case 3 and gets its own account.
 */
final class GoogleSignIn
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserIdentityRepository $identities,
    ) {
    }

    /**
     * @param array{sub: string, email: string|null, emailVerified: bool, name: string|null, picture: string|null} $claims
     */
    public function resolve(array $claims): User
    {
        $existing = $this->identities->findOneByProvider(UserIdentity::PROVIDER_GOOGLE, $claims['sub']);
        if (null !== $existing) {
            $user = $existing->getUser();
            if ($user instanceof User && !$user->isDeleted()) {
                return $user;
            }
        }

        $user = $this->linkable($claims) ?? $this->create($claims);

        $this->entityManager->persist(new UserIdentity($user, UserIdentity::PROVIDER_GOOGLE, $claims['sub']));
        $this->entityManager->flush();

        return $user;
    }

    /** An existing account this Google identity is allowed to join. */
    private function linkable(array $claims): ?User
    {
        if (null === $claims['email'] || !$claims['emailVerified']) {
            return null;
        }

        $user = $this->users->findOneActiveByEmail($claims['email']);
        if (null === $user) {
            return null;
        }

        // Google has confirmed the address, so the account can be trusted to
        // say so too from now on.
        $user->setEmailVerified(true);

        return $user;
    }

    private function create(array $claims): User
    {
        /*
         * An address Google will not vouch for is not written down at all.
         *
         * Storing it would either collide with the account that already holds
         * it, or — worse, when nobody holds it — let somebody park an address
         * they have not proven they own, and lock its real owner out of ever
         * registering with it. The account still gets made; it just starts
         * without an address, and its owner can add one later.
         */
        $email = $claims['emailVerified'] ? $claims['email'] : null;

        $user = (new User())
            ->setUsername($this->freeHandle($claims))
            ->setName($claims['name'] ?: $this->handleSeed($claims))
            ->setEmail($email)
            ->setEmailVerified(null !== $email)
            // No password at all, rather than a random one nobody can use:
            // "you have not set a password" is answerable, a password only the
            // server has ever seen is not.
            ->setPassword(null)
            // They have not chosen this handle, they were given it. The front
            // end offers one chance to change it before it settles.
            ->setHandlePending(true);

        $this->entityManager->persist($user);

        return $user;
    }

    /** A handle nobody is using, seeded from their address or their name. */
    private function freeHandle(array $claims): string
    {
        $base = preg_replace('/[^a-z0-9_]+/', '', mb_strtolower($this->handleSeed($claims))) ?: 'friend';
        $base = mb_substr(ltrim($base, '_'), 0, 24) ?: 'friend';

        if (mb_strlen($base) < 3) {
            $base .= 'user';
        }

        $handle = $base;
        // Bounded, and the random tail below is the escape hatch: this only has
        // to stop the common collisions, not every possible one.
        for ($suffix = 2; $suffix < 50; ++$suffix) {
            if (!$this->users->existsActiveByUsername($handle)) {
                return $handle;
            }
            $handle = $base.$suffix;
        }

        return mb_substr($base, 0, 18).bin2hex(random_bytes(3));
    }

    private function handleSeed(array $claims): string
    {
        if (null !== $claims['email'] && str_contains($claims['email'], '@')) {
            return strstr($claims['email'], '@', true) ?: 'friend';
        }

        return $claims['name'] ?? 'friend';
    }
}
