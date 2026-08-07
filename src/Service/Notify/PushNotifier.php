<?php

namespace App\Service\Notify;

use App\Entity\DeviceToken;
use App\Entity\Review;
use App\Entity\User;
use App\Entity\Work;
use App\Repository\DeviceTokenRepository;
use App\Repository\SettingRepository;

/**
 * What the rest of the application calls when something is worth interrupting
 * somebody for.
 *
 * Controllers say `followed($alice, $bob)`. They do not know about tokens,
 * locales, channels, or that FCM exists — which is the point, because the day
 * an iOS build needs APNs, or web push needs VAPID, this is the only file that
 * learns about it.
 *
 * ---- the two speeds ---------------------------------------------------------
 *
 * Social events fire the moment they happen: a follow that arrives eight hours
 * late is a strange thing to receive. Catalog events do not, because they are
 * discovered by the nightly crawl at 02:00 and nobody wants to be told at 02:00
 * that a series aired. Those accumulate and go out as one digest per person, in
 * their own morning — see PushDigestCommand.
 */
class PushNotifier
{
    public const KIND_EPISODE = 'episode';
    public const KIND_RELEASE = 'release';
    public const KIND_ACTIVITY = 'activity';
    public const KIND_FOLLOWER = 'follower';

    /**
     * The kinds a person can switch off, and what each covers. Mirrors
     * TelegramNotifier::EVENTS — the admin screen and the app settings screen
     * both read their list from here rather than hard-coding one of their own.
     */
    public const KINDS = [
        self::KIND_EPISODE => 'New episode of a series you are watching',
        self::KIND_RELEASE => 'Release day for something on your wishlist',
        self::KIND_ACTIVITY => 'Ratings and reviews from people you follow',
        self::KIND_FOLLOWER => 'Somebody follows you',
    ];

    /**
     * One Android channel per kind, so muting friends' reviews in the system
     * settings does not also mute release day. Android will not let us rename a
     * channel once created, so these ids are permanent.
     */
    private const CHANNELS = [
        self::KIND_EPISODE => 'episodes',
        self::KIND_RELEASE => 'releases',
        self::KIND_ACTIVITY => 'activity',
        self::KIND_FOLLOWER => 'social',
    ];

    public function __construct(
        private readonly FcmSender $fcm,
        private readonly DeviceTokenRepository $deviceTokens,
        private readonly SettingRepository $settings,
    ) {
    }

    /** The master switch, for turning every push off without a deploy. */
    public function isEnabled(): bool
    {
        return $this->settings->bool(FcmSender::ENABLED, true) && $this->fcm->isConfigured();
    }

    /**
     * Somebody started following this person.
     *
     * @return int devices reached
     */
    public function followed(User $recipient, User $follower): int
    {
        return $this->dispatch(
            $recipient,
            self::KIND_FOLLOWER,
            fn (string $locale) => [
                PushMessages::title('follower', $locale),
                PushMessages::body('follower.body', $locale, $follower->getName() ?? $follower->getUsername()),
            ],
            ['link' => '/u/'.$follower->getUsername()],
            // One row per follower: being followed by two people is two events,
            // but the same person following, unfollowing and following again in
            // a minute is one.
            'follow:'.$follower->getId(),
        );
    }

    /**
     * Somebody this person follows rated or reviewed something.
     *
     * A rating with no text and a full review are different events to the
     * person receiving them, so they read differently — but they share a
     * collapse key, because a friend who rates and then writes about the same
     * film has done one thing.
     *
     * @return int devices reached
     */
    public function activity(User $recipient, Review $review): int
    {
        $author = $review->getUser();
        $work = $review->getWork();
        if (null === $author || null === $work) {
            return 0;
        }

        $name = $author->getName() ?? $author->getUsername();
        $hasText = '' !== trim((string) $review->getBody());

        return $this->dispatch(
            $recipient,
            self::KIND_ACTIVITY,
            fn (string $locale) => [
                $name,
                $hasText
                    ? PushMessages::body('activity.reviewed', $locale, $name, (string) $work->getTitle())
                    : PushMessages::body(
                        'activity.rated',
                        $locale,
                        $name,
                        (string) $work->getTitle(),
                        rtrim(rtrim((string) $review->getRating(), '0'), '.'),
                    ),
            ],
            ['link' => $this->workLink($work)],
            'review:'.$author->getId().':'.$work->getId(),
        );
    }

    /**
     * The morning digest: everything the catalog turned up for this person
     * overnight, as one interruption.
     *
     * @param list<Work> $episodes series that aired
     * @param list<Work> $releases wishlist titles out today
     *
     * @return int devices reached
     */
    public function digest(User $recipient, array $episodes, array $releases): int
    {
        if ([] === $episodes && [] === $releases) {
            return 0;
        }

        /*
         * The digest spans two kinds, so it is gated on wanting either. Someone
         * who switched off release day but still wants episodes gets a digest
         * containing only the episodes — filtered by the caller, which is why
         * an empty list here means "nothing survived", not "nothing happened".
         */
        $kind = [] === $releases ? self::KIND_EPISODE : self::KIND_RELEASE;
        $titles = array_map(
            static fn (Work $work) => (string) $work->getTitle(),
            [...$episodes, ...$releases],
        );

        $titleKey = match (true) {
            [] === $releases => 'digest.episodes',
            [] === $episodes => 'digest.releases',
            default => 'digest.mixed',
        };

        return $this->dispatch(
            $recipient,
            $kind,
            fn (string $locale) => [
                PushMessages::title($titleKey, $locale),
                PushMessages::titleList($titles),
            ],
            // One title goes straight there; several land on the shelf, because
            // there is no single page that means "these five things".
            ['link' => 1 === \count($titles) && [] !== $episodes
                ? $this->workLink($episodes[0])
                : '/shelf'],
            // Re-running the digest for the same day must not stack.
            'digest:'.(new \DateTimeImmutable())->format('Y-m-d'),
            // A digest is gated on the kinds it contains, not on one of them.
            [] === $releases ? [self::KIND_EPISODE] : ([] === $episodes ? [self::KIND_RELEASE] : [self::KIND_EPISODE, self::KIND_RELEASE]),
        );
    }

    /**
     * Send one message to every device a person has.
     *
     * @param callable(string): array{0: string, 1: string} $render        locale in, [title, body] out
     * @param array<string, string>                         $data          extra payload, merged with the routing keys
     * @param list<string>|null                             $requiredKinds kinds the person must want at least one of
     */
    private function dispatch(
        User $recipient,
        string $kind,
        callable $render,
        array $data = [],
        ?string $collapseKey = null,
        ?array $requiredKinds = null,
    ): int {
        if (!$this->isEnabled()) {
            return 0;
        }

        $wanted = array_filter(
            $requiredKinds ?? [$kind],
            static fn (string $k) => $recipient->wantsPush($k),
        );
        if ([] === $wanted) {
            return 0;
        }

        $devices = $this->deviceTokens->findForUser($recipient);
        if ([] === $devices) {
            return 0;
        }

        $sent = 0;
        foreach ($devices as $device) {
            // Rendered per device, not per user: two phones on one account can
            // be set to two languages, and each should be interrupted in its
            // own.
            [$title, $body] = $render(PushMessages::locale($recipient, $device->getLocale()));

            $delivered = $this->fcm->send(
                $device->getToken(),
                $title,
                $body,
                [
                    ...$data,
                    'kind' => $kind,
                    'channel' => self::CHANNELS[$kind] ?? 'general',
                ],
                $collapseKey,
            );

            if ($delivered) {
                ++$sent;
            }
        }

        return $sent;
    }

    /** The client route for a title, matching the SvelteKit and Android maps. */
    private function workLink(Work $work): string
    {
        return '/'.$work->getType().'/'.$work->getSlug();
    }

    /**
     * Devices belonging to a batch of people, for the digest.
     *
     * @param list<int> $userIds
     *
     * @return array<int, list<DeviceToken>>
     */
    public function devicesFor(array $userIds): array
    {
        return $this->deviceTokens->findForUserIds($userIds);
    }
}
