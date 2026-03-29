<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

interface ReloadableEntityManagerInterface extends EntityManagerInterface
{
    /**
     * Recreate the underlying EntityManager if it was closed (e.g. after a failed flush).
     */
    public function open(): void;

    /**
     * Fetch a fresh instance of an entity object, even if the EntityManager has been cleared.
     *
     * phpcs:disable SlevomatCodingStandard.TypeHints.ReturnTypeHint
     *
     * @template TEntity as object
     *
     * @param TEntity $entity
     *
     * @return TEntity
     */
    public function refetch(object $entity): object;

    /**
     * Fetch a fresh reference to an entity object, even if the EntityManager has been cleared.
     *
     * phpcs:disable SlevomatCodingStandard.TypeHints.ReturnTypeHint
     *
     * @template TEntity as object
     *
     * @param TEntity $entity
     *
     * @return TEntity
     */
    public function refetchAsReference(object $entity): object;
}
