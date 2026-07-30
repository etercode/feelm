<?php

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Two things in the database have no entity: the generated tsvector column on
 * works and the search_terms lexicon. (The TMDB crawl queue is excluded from
 * comparison altogether via doctrine.dbal.schema_filter.) Declaring them here means
 * `doctrine:migrations:diff` sees them as expected rather than generating a
 * DROP for them the next time someone changes an entity.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class GeneratedSchemaListener
{
    public const SEARCH_VECTOR_DEFINITION = "tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('simple', coalesce(title, '')), 'A') ||
            setweight(to_tsvector('simple', coalesce(original_title, '')), 'B') ||
            setweight(to_tsvector('simple', coalesce(tagline, '')), 'C') ||
            setweight(to_tsvector('simple', coalesce(overview, '')), 'D')
        ) STORED";

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if ($schema->hasTable('works')) {
            $works = $schema->getTable('works');
            if (!$works->hasColumn('search_vector')) {
                $works->addColumn('search_vector', 'string', [
                    'notnull' => false,
                    'columnDefinition' => self::SEARCH_VECTOR_DEFINITION,
                ]);
                $works->addIndex(['search_vector'], 'idx_work_search_vector');
            }
            if (!$works->hasIndex('idx_work_title_trgm')) {
                $works->addIndex(['title'], 'idx_work_title_trgm');
            }
        }

        if ($schema->hasTable('people') && !$schema->getTable('people')->hasIndex('idx_person_name_trgm')) {
            $schema->getTable('people')->addIndex(['name'], 'idx_person_name_trgm');
        }

        // Indexes Doctrine cannot express: a covering order on the join table,
        // and a uniqueness rule that has to treat a null character as ''.
        if ($schema->hasTable('work_genre') && !$schema->getTable('work_genre')->hasIndex('idx_work_genre_genre')) {
            $schema->getTable('work_genre')->addIndex(['genre_id', 'work_id'], 'idx_work_genre_genre');
        }

        if (!$schema->hasTable('search_terms')) {
            $terms = $schema->createTable('search_terms');
            $terms->addColumn('term', 'string', ['length' => 80]);
            $terms->addColumn('uses', 'integer', ['default' => 1]);
            $terms->setPrimaryKey(['term']);
            $terms->addIndex(['term'], 'idx_search_term_trgm');
            $terms->addIndex(['uses'], 'idx_search_term_uses');
        }
    }
}
