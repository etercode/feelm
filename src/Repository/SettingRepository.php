<?php

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    /**
     * Every row, read once per request.
     *
     * There are a dozen of these and half the callers ask for two of them in a
     * row, so the table is small enough to be cheaper as one query than as a
     * lookup each. Reset by `set()` rather than left to go stale.
     *
     * @var array<string, string|null>|null
     */
    private ?array $cache = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    /** @return array<string, string|null> */
    public function all(): array
    {
        if (null === $this->cache) {
            $this->cache = [];
            foreach ($this->findAll() as $setting) {
                $this->cache[$setting->getName()] = $setting->getValue();
            }
        }

        return $this->cache;
    }

    public function get(string $name, ?string $fallback = null): ?string
    {
        return $this->all()[$name] ?? $fallback;
    }

    public function bool(string $name, bool $fallback = false): bool
    {
        $value = $this->get($name);

        return null === $value ? $fallback : \in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Upsert. Flushed here rather than left to the caller because settings are
     * written one at a time from a form, never as part of a larger unit of
     * work, and a half-saved notification config is worse than a slow one.
     */
    public function set(string $name, ?string $value): void
    {
        $setting = $this->find($name) ?? new Setting($name);
        $setting->setValue($value);

        $manager = $this->getEntityManager();
        $manager->persist($setting);
        $manager->flush();

        $this->cache = null;
    }

    /** @param array<string, string|null> $values */
    public function setMany(array $values): void
    {
        $manager = $this->getEntityManager();

        foreach ($values as $name => $value) {
            $setting = $this->find($name) ?? new Setting($name);
            $setting->setValue($value);
            $manager->persist($setting);
        }

        $manager->flush();
        $this->cache = null;
    }
}
