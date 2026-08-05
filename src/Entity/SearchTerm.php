<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The vocabulary a search query is spell-checked against.
 *
 * Rebuilt wholesale by SearchTermsIndex in raw SQL, and read by WorkSearch in
 * raw SQL. Nothing hydrates this class; it is here so Doctrine knows the table
 * exists and does not propose dropping it.
 *
 * Two of its three indexes cannot be described here: a GIN index with
 * gin_trgm_ops, which is what makes "intersteller" find "interstellar", and a
 * DESC btree on uses. #[ORM\Index] has no vocabulary for either an operator
 * class or a sort direction, so the migration owns both and a schema diff will
 * always offer to drop them.
 *
 * That is the trade being made deliberately: index noise in a generated diff is
 * something you read past, and a dropped index can be rebuilt from the
 * migration. A dropped table is rows that are gone. Being declared and slightly
 * wrong beats being invisible and perfectly quiet.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'search_terms')]
class SearchTerm
{
    #[ORM\Id]
    #[ORM\Column(length: 80)]
    private string $term = '';

    /** How many works use it — the tie-break when several spellings are close. */
    #[ORM\Column(options: ['default' => 1])]
    private int $uses = 1;

    public function getTerm(): string
    {
        return $this->term;
    }

    public function getUses(): int
    {
        return $this->uses;
    }
}
