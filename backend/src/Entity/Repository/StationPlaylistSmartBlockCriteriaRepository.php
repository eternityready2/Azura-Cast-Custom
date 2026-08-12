<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Doctrine\Repository;
use App\Entity\Enums\SmartBlockCriteriaComparison;
use App\Entity\Enums\SmartBlockCriteriaField;
use App\Entity\Enums\SmartBlockLimitType;
use App\Entity\Enums\SmartBlockMatchType;
use App\Entity\SongHistory;
use App\Entity\StationMedia;
use App\Entity\StationMediaCustomField;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistSmartBlockCriteria;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends Repository<StationPlaylistSmartBlockCriteria>
 */
final class StationPlaylistSmartBlockCriteriaRepository extends Repository
{
    protected string $entityClass = StationPlaylistSmartBlockCriteria::class;

    /**
     * Resolve the set of StationMedia currently matching a Smart Block playlist's
     * criteria. Used both by the sync task (to keep membership up to date) and by the
     * "Preview" API endpoint (so the person editing the playlist can see results live).
     *
     * @return StationMedia[]
     */
    public function getMatchingMedia(StationPlaylist $playlist): array
    {
        // Skip any criterion that's incomplete (e.g. "Custom Field" selected but no
        // actual field chosen yet, mid-edit) instead of treating it as impossible to
        // satisfy -- an unusable row should be ignored, not silently poison "Match ALL"
        // into never matching anything.
        $criteria = $playlist->smart_block_criteria->filter(
            static fn (StationPlaylistSmartBlockCriteria $c): bool =>
                SmartBlockCriteriaField::CustomField !== $c->field || null !== $c->custom_field
        );

        if (0 === $criteria->count()) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('sm')
            ->from(StationMedia::class, 'sm')
            ->where('sm.storage_location = :storageLocation')
            ->andWhere('sm.do_not_play_reason IS NULL')
            ->setParameter('storageLocation', $playlist->station->media_storage_location);

        $conditions = [];
        $paramIndex = 0;

        foreach ($criteria as $criterion) {
            $conditions[] = $this->buildCondition($qb, $criterion, $paramIndex);
            $paramIndex++;
        }

        $combined = (SmartBlockMatchType::Any === $playlist->smart_block_match_type)
            ? $qb->expr()->orX(...$conditions)
            : $qb->expr()->andX(...$conditions);

        $qb->andWhere($combined)
            ->orderBy('RAND()');

        $limit = $playlist->smart_block_limit;

        // Track-count limits can be applied directly in SQL. Duration limits can't (SQL
        // can't LIMIT on a running sum of a randomly-ordered result), so for those we
        // pull a generous random pool and trim it in PHP by accumulating track lengths
        // until the requested duration is reached.
        if (null !== $limit && $limit > 0 && SmartBlockLimitType::Tracks === $playlist->smart_block_limit_type) {
            $qb->setMaxResults($limit);
        } elseif (null !== $limit && $limit > 0) {
            $qb->setMaxResults(500);
        }

        /** @var StationMedia[] $results */
        $results = $qb->getQuery()->getResult();

        if (null !== $limit && $limit > 0 && SmartBlockLimitType::Duration === $playlist->smart_block_limit_type) {
            $trimmed = [];
            $totalSeconds = 0.0;

            foreach ($results as $media) {
                if ($totalSeconds >= $limit) {
                    break;
                }

                $trimmed[] = $media;
                $totalSeconds += $media->length;
            }

            return $trimmed;
        }

        return $results;
    }

    private function buildCondition(
        QueryBuilder $qb,
        StationPlaylistSmartBlockCriteria $criterion,
        int $paramIndex
    ): string {
        if (SmartBlockCriteriaField::CustomField === $criterion->field) {
            return $this->buildCustomFieldCondition($qb, $criterion, $paramIndex);
        }

        if (SmartBlockCriteriaField::Duration === $criterion->field) {
            return $this->buildNumericCondition($qb, 'sm.length', $criterion, $paramIndex);
        }

        if (SmartBlockCriteriaField::LastPlayed === $criterion->field) {
            return $this->buildLastPlayedCondition($qb, $criterion, $paramIndex);
        }

        $column = match ($criterion->field) {
            SmartBlockCriteriaField::Genre => 'sm.genre',
            SmartBlockCriteriaField::Artist => 'sm.artist',
            SmartBlockCriteriaField::Album => 'sm.album',
            SmartBlockCriteriaField::Title => 'sm.title',
            default => 'sm.genre',
        };

        return $this->buildTextCondition($qb, $column, $criterion, $paramIndex);
    }

    private function buildTextCondition(
        QueryBuilder $qb,
        string $column,
        StationPlaylistSmartBlockCriteria $criterion,
        int $paramIndex
    ): string {
        $paramName = 'val' . $paramIndex;
        $value = $criterion->value ?? '';

        return match ($criterion->comparison) {
            SmartBlockCriteriaComparison::Is => (function () use ($qb, $column, $paramName, $value) {
                $qb->setParameter($paramName, mb_strtolower($value));
                return sprintf('LOWER(%s) = :%s', $column, $paramName);
            })(),
            SmartBlockCriteriaComparison::IsNot => (function () use ($qb, $column, $paramName, $value) {
                $qb->setParameter($paramName, mb_strtolower($value));
                return sprintf('(%s IS NULL OR LOWER(%s) != :%s)', $column, $column, $paramName);
            })(),
            SmartBlockCriteriaComparison::Contains => (function () use ($qb, $column, $paramName, $value) {
                $qb->setParameter($paramName, '%' . mb_strtolower($value) . '%');
                return sprintf('LOWER(%s) LIKE :%s', $column, $paramName);
            })(),
            SmartBlockCriteriaComparison::NotContains => (function () use ($qb, $column, $paramName, $value) {
                $qb->setParameter($paramName, '%' . mb_strtolower($value) . '%');
                return sprintf('(%s IS NULL OR LOWER(%s) NOT LIKE :%s)', $column, $column, $paramName);
            })(),
            default => sprintf('%s IS NOT NULL', $column),
        };
    }

    private function buildNumericCondition(
        QueryBuilder $qb,
        string $column,
        StationPlaylistSmartBlockCriteria $criterion,
        int $paramIndex
    ): string {
        $paramName = 'val' . $paramIndex;
        $value = (float)($criterion->value ?? 0);

        if (SmartBlockCriteriaComparison::Between === $criterion->comparison) {
            $paramName2 = 'val' . $paramIndex . 'b';
            $value2 = (float)($criterion->value2 ?? $value);
            [$low, $high] = $value <= $value2 ? [$value, $value2] : [$value2, $value];

            $qb->setParameter($paramName, $low);
            $qb->setParameter($paramName2, $high);
            return sprintf('%s BETWEEN :%s AND :%s', $column, $paramName, $paramName2);
        }

        $qb->setParameter($paramName, $value);

        return match ($criterion->comparison) {
            SmartBlockCriteriaComparison::IsNot => sprintf('%s != :%s', $column, $paramName),
            SmartBlockCriteriaComparison::GreaterThan => sprintf('%s > :%s', $column, $paramName),
            SmartBlockCriteriaComparison::LessThan => sprintf('%s < :%s', $column, $paramName),
            default => sprintf('%s = :%s', $column, $paramName),
        };
    }

    /**
     * "Last Played" criteria mirror Airtime Pro's "Last Played > N days ago" filter --
     * value is a number of days. A track that has never played counts as infinitely
     * long ago (so it always satisfies a "greater than" / "not recently played" rule).
     */
    private function buildLastPlayedCondition(
        QueryBuilder $qb,
        StationPlaylistSmartBlockCriteria $criterion,
        int $paramIndex
    ): string {
        $subAlias = 'sh' . $paramIndex;
        $maxPlayedExpr = sprintf(
            '(SELECT MAX(%s.timestamp_start) FROM %s %s WHERE %s.media = sm)',
            $subAlias,
            SongHistory::class,
            $subAlias,
            $subAlias
        );

        $now = new DateTimeImmutable('now');
        $daysAgo = static fn (float $days): DateTimeImmutable => $now->modify(
            sprintf('-%d seconds', (int)round($days * 86400))
        );

        $value = (float)($criterion->value ?? 0);
        $paramName = 'val' . $paramIndex;

        return match ($criterion->comparison) {
            SmartBlockCriteriaComparison::LessThan => (function () use (
                $qb,
                $maxPlayedExpr,
                $paramName,
                $daysAgo,
                $value
            ) {
                // "Played less than N days ago" -- must have a play, and it must be
                // more recent than the threshold.
                $qb->setParameter($paramName, $daysAgo($value));
                return sprintf('(%s IS NOT NULL AND %s > :%s)', $maxPlayedExpr, $maxPlayedExpr, $paramName);
            })(),
            SmartBlockCriteriaComparison::Between => (function () use (
                $qb,
                $maxPlayedExpr,
                $paramName,
                $daysAgo,
                $value,
                $criterion,
                $paramIndex
            ) {
                $paramName2 = 'val' . $paramIndex . 'b';
                $value2 = (float)($criterion->value2 ?? $value);
                [$lowDays, $highDays] = $value <= $value2 ? [$value, $value2] : [$value2, $value];

                // Fewer days ago = more recent = a later timestamp.
                $qb->setParameter($paramName, $daysAgo($highDays));
                $qb->setParameter($paramName2, $daysAgo($lowDays));
                return sprintf(
                    '(%s IS NOT NULL AND %s BETWEEN :%s AND :%s)',
                    $maxPlayedExpr,
                    $maxPlayedExpr,
                    $paramName,
                    $paramName2
                );
            })(),
            default => (function () use ($qb, $maxPlayedExpr, $paramName, $daysAgo, $value) {
                // GreaterThan (and Is/IsNot, treated the same way here) -- "played more
                // than N days ago" including tracks that have never been played at all.
                $qb->setParameter($paramName, $daysAgo($value));
                return sprintf('(%s IS NULL OR %s < :%s)', $maxPlayedExpr, $maxPlayedExpr, $paramName);
            })(),
        };
    }

    /**
     * Custom Fields (e.g. "BPM", "Mood", "Energy") are stored as free-text key/value
     * rows on {@see StationMediaCustomField}, so both text and numeric comparisons are
     * supported -- numeric ones CAST the stored value.
     */
    private function buildCustomFieldCondition(
        QueryBuilder $qb,
        StationPlaylistSmartBlockCriteria $criterion,
        int $paramIndex
    ): string {
        if (null === $criterion->custom_field) {
            // No field selected yet (mid-edit in the UI) -- never matches.
            return '1 = 0';
        }

        $fieldParam = 'field' . $paramIndex;
        $qb->setParameter($fieldParam, $criterion->custom_field);

        $isNumeric = in_array(
            $criterion->comparison,
            [
                SmartBlockCriteriaComparison::GreaterThan,
                SmartBlockCriteriaComparison::LessThan,
                SmartBlockCriteriaComparison::Between,
            ],
            true
        );

        $paramName = 'val' . $paramIndex;
        $subAlias = 'smcf' . $paramIndex;

        if ($isNumeric) {
            $value = (float)($criterion->value ?? 0);

            if (SmartBlockCriteriaComparison::Between === $criterion->comparison) {
                $paramName2 = 'val' . $paramIndex . 'b';
                $value2 = (float)($criterion->value2 ?? $value);
                [$low, $high] = $value <= $value2 ? [$value, $value2] : [$value2, $value];

                $qb->setParameter($paramName, $low);
                $qb->setParameter($paramName2, $high);
                $valueCondition = sprintf(
                    'CAST(%s.value AS DECIMAL(18,4)) BETWEEN :%s AND :%s',
                    $subAlias,
                    $paramName,
                    $paramName2
                );
            } else {
                $qb->setParameter($paramName, $value);
                $operator = SmartBlockCriteriaComparison::GreaterThan === $criterion->comparison ? '>' : '<';
                $valueCondition = sprintf(
                    'CAST(%s.value AS DECIMAL(18,4)) %s :%s',
                    $subAlias,
                    $operator,
                    $paramName
                );
            }
        } else {
            $value = mb_strtolower($criterion->value ?? '');

            $valueCondition = match ($criterion->comparison) {
                SmartBlockCriteriaComparison::Contains => (function () use ($qb, $paramName, $subAlias, $value) {
                    $qb->setParameter($paramName, '%' . $value . '%');
                    return sprintf('LOWER(%s.value) LIKE :%s', $subAlias, $paramName);
                })(),
                SmartBlockCriteriaComparison::IsNot,
                SmartBlockCriteriaComparison::NotContains => (function () use ($qb, $paramName, $subAlias, $value) {
                    $qb->setParameter($paramName, $value);
                    return sprintf('LOWER(%s.value) != :%s', $subAlias, $paramName);
                })(),
                default => (function () use ($qb, $paramName, $subAlias, $value) {
                    $qb->setParameter($paramName, $value);
                    return sprintf('LOWER(%s.value) = :%s', $subAlias, $paramName);
                })(),
            };
        }

        // For "IsNot"/"NotContains", a track with NO value for this field at all should
        // still count as matching (it definitionally doesn't have the excluded value).
        $isNegative = in_array(
            $criterion->comparison,
            [SmartBlockCriteriaComparison::IsNot, SmartBlockCriteriaComparison::NotContains],
            true
        );

        $existsDql = sprintf(
            '(SELECT %s.id FROM %s %s WHERE %s.media = sm AND %s.field = :%s AND %s)',
            $subAlias,
            StationMediaCustomField::class,
            $subAlias,
            $subAlias,
            $subAlias,
            $fieldParam,
            $valueCondition
        );

        if ($isNegative) {
            $noFieldAlias = $subAlias . 'n';
            $noFieldDql = sprintf(
                '(SELECT %s.id FROM %s %s WHERE %s.media = sm AND %s.field = :%s)',
                $noFieldAlias,
                StationMediaCustomField::class,
                $noFieldAlias,
                $noFieldAlias,
                $noFieldAlias,
                $fieldParam
            );

            return sprintf('(EXISTS %s OR NOT EXISTS %s)', $existsDql, $noFieldDql);
        }

        return sprintf('EXISTS %s', $existsDql);
    }
}
