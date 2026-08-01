<?php

namespace App\Service\Catalog;

/**
 * Decides whether a TMDB series is a real show or somebody's afternoon.
 *
 * TMDB is community-edited: anyone with an account can add a title, and a large
 * part of the tail of the export is a YouTube upload, a lecture series, a family
 * video or an abandoned stub with a name and nothing else. There is no field
 * that says "a user added this" — everything on TMDB was added by a user — so
 * the only honest filter is one built from what a real broadcast series has and
 * a stub does not.
 *
 * Measured against a stratified sample of 500 series drawn across the export's
 * popularity order, BASIC rejects:
 *
 *     top 1% of the export    2%
 *     1–10%                   4%
 *     10–30%                  7%
 *     30–60%                 19%
 *     60–100%                53%
 *     ── whole export ──     29%   (228,142 ids → ~163,000 kept)
 *
 * That gradient is the argument for it: it barely touches the shows anyone has
 * heard of and does most of its work in the part of the catalog nobody has
 * looked at. Every BASIC rule means "there is nothing here to put on a page" —
 * no poster is a grey rectangle, no episodes is an empty season list — so a
 * false positive costs a title that could not have been displayed anyway.
 *
 * STRICT adds "somebody produced or broadcast it", which takes the cut to 49%.
 * It is honestly worse: in the sample it also threw away Papuwa (a 1990s anime)
 * and Shane the Chef (a CBeebies show), because TMDB simply has no network
 * recorded for them. It is offered for a deliberately conservative catalog, not
 * recommended.
 *
 * Reasons are stored on the queue row, so a rule that turns out to be wrong can
 * be reversed with `--requeue=<reason>` rather than re-crawled from nothing.
 */
final class SeriesQualityGate
{
    public const OFF = 'off';
    public const BASIC = 'basic';
    public const STRICT = 'strict';

    public const LEVELS = [self::OFF, self::BASIC, self::STRICT];

    /**
     * Why this series should not be stored, or null to keep it.
     *
     * @param array<string, mixed> $detail a /tv/{id} payload
     */
    public function reject(array $detail, string $level = self::BASIC): ?string
    {
        if (self::OFF === $level) {
            return null;
        }

        if (true === ($detail['adult'] ?? false)) {
            return 'adult';
        }

        if ('' === trim((string) ($detail['name'] ?? ''))) {
            return 'no_title';
        }

        // Never went out. TMDB keeps announced and abandoned shows alike, and
        // they are indistinguishable from a stub someone typed in and left.
        if ('' === trim((string) ($detail['first_air_date'] ?? ''))) {
            return 'never_aired';
        }

        if (($detail['number_of_episodes'] ?? 0) < 1) {
            return 'no_episodes';
        }

        // The catalog is a wall of posters; a title without one cannot join it.
        if ('' === trim((string) ($detail['poster_path'] ?? ''))) {
            return 'no_poster';
        }

        if (self::STRICT === $level
            && [] === ($detail['networks'] ?? [])
            && [] === ($detail['production_companies'] ?? [])) {
            return 'no_broadcaster';
        }

        return null;
    }
}
