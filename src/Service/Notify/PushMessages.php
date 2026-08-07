<?php

namespace App\Service\Notify;

use App\Entity\User;

/**
 * The words a push arrives in.
 *
 * ---- why the server has its own catalog -------------------------------------
 *
 * Everywhere else, the API answers in keys and the client owns the dictionary —
 * which is why there is no symfony/translation here and no translations/ tree.
 * Push is the one thing that cannot work that way: the `notification` block is
 * drawn by Android itself, before any of our code runs, so whatever it says has
 * to be final by the time it leaves this server. The `data` block still carries
 * the key so a running app can re-render from its own resources.
 *
 * ---- why there are no plural forms ------------------------------------------
 *
 * Russian needs three plural forms and Azerbaijani none, and a notification body
 * is truncated by the system at roughly forty characters anyway. Rather than
 * carry a plural engine for two strings, counts are rendered as "Severance +2",
 * which is grammatical in every language Feelm speaks because it is not a
 * sentence. The meaning lives in the title, which never varies by count.
 */
class PushMessages
{
    /**
     * Titles, per kind and locale.
     *
     * @var array<string, array<string, string>>
     */
    private const TITLES = [
        'digest.episodes' => [
            'en' => 'New episodes today',
            'az' => 'Bu gün yeni bölümlər',
            'tr' => 'Bugün yeni bölümler',
            'ru' => 'Новые серии сегодня',
        ],
        'digest.releases' => [
            'en' => 'Out today',
            'az' => 'Bu gün çıxır',
            'tr' => 'Bugün çıkıyor',
            'ru' => 'Выходит сегодня',
        ],
        'digest.mixed' => [
            'en' => 'Today on Feelm',
            'az' => 'Bu gün Feelm-də',
            'tr' => "Bugün Feelm'de",
            'ru' => 'Сегодня в Feelm',
        ],
        'follower' => [
            'en' => 'New follower',
            'az' => 'Yeni izləyici',
            'tr' => 'Yeni takipçi',
            'ru' => 'Новый подписчик',
        ],
    ];

    /**
     * Bodies that take arguments. %s in the order the caller passes them.
     *
     * @var array<string, array<string, string>>
     */
    private const BODIES = [
        // name
        'follower.body' => [
            'en' => '%s started following you',
            'az' => '%s sizi izləməyə başladı',
            'tr' => '%s sizi takip etmeye başladı',
            'ru' => '%s подписался на вас',
        ],
        // name, title, rating
        'activity.rated' => [
            'en' => '%s rated %s %s★',
            'az' => '%s %s əsərinə %s★ verdi',
            'tr' => '%s, %s için %s★ verdi',
            'ru' => '%s оценил %s на %s★',
        ],
        // name, title
        'activity.reviewed' => [
            'en' => '%s reviewed %s',
            'az' => '%s %s haqqında rəy yazdı',
            'tr' => '%s, %s hakkında yorum yaptı',
            'ru' => '%s написал рецензию на %s',
        ],
    ];

    /**
     * Pick the language a push should speak.
     *
     * The device wins. Somebody who set Feelm to Azerbaijani on their phone has
     * said what language they want to be interrupted in, and that is a stronger
     * signal than a locale chosen once on the website years ago. The account is
     * only the fallback for devices registered before the app started sending
     * its locale.
     */
    public static function locale(User $user, ?string $deviceLocale): string
    {
        $candidate = strtolower(substr((string) $deviceLocale, 0, 2));
        if (\in_array($candidate, User::SUPPORTED_LOCALES, true)) {
            return $candidate;
        }

        return $user->getLocale();
    }

    public static function title(string $key, string $locale): string
    {
        return self::TITLES[$key][$locale]
            ?? self::TITLES[$key][User::DEFAULT_LOCALE]
            ?? $key;
    }

    public static function body(string $key, string $locale, string ...$args): string
    {
        $template = self::BODIES[$key][$locale]
            ?? self::BODIES[$key][User::DEFAULT_LOCALE]
            ?? $key;

        return sprintf($template, ...$args);
    }

    /**
     * "Severance", "Severance +1", "Severance, Silo +3".
     *
     * Two names then a count, because a notification that lists five titles is
     * a notification that shows one and a half of them and an ellipsis. The
     * separator is a plain comma in every language Feelm speaks.
     *
     * @param list<string> $titles
     */
    public static function titleList(array $titles): string
    {
        $shown = \array_slice($titles, 0, 2);
        $rest = \count($titles) - \count($shown);

        $text = implode(', ', $shown);

        return $rest > 0 ? $text.' +'.$rest : $text;
    }
}
