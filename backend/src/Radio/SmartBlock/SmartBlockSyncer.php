<?php

declare(strict_types=1);

namespace App\Radio\SmartBlock;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Enums\SmartBlockSortOrder;
use App\Entity\Repository\StationPlaylistSmartBlockCriteriaRepository;
use App\Entity\StationMedia;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistMedia;
use App\Message\WritePlaylistFileMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBus;

/**
 * Reconciles a Smart Block playlist's {@see StationPlaylistMedia} membership against its
 * current criteria. Shared by the recurring background sync
 * ({@see \App\Sync\Task\CheckSmartBlockPlaylistsTask}) and the "Save" action in the
 * Smart Block editor, so saving criteria gives immediate feedback rather than waiting
 * for the next scheduled run.
 */
final readonly class SmartBlockSyncer
{
    public function __construct(
        private StationPlaylistSmartBlockCriteriaRepository $criteriaRepo,
        private EntityManagerInterface $em,
        private MessageBus $messageBus,
    ) {
    }

    /**
     * @return array{added: int, removed: int, total: int}
     */
    public function sync(StationPlaylist $playlist, bool $dispatchWriteMessage = true): array
    {
        $matchingMedia = $this->criteriaRepo->getMatchingMedia($playlist);

        // Apply the Smart Block's own sort order to the matched pool before assigning
        // weights. Weight order IS playback order for Sequential playlists, and it
        // seeds the shuffle for Shuffle/Random playlists -- so this must happen here,
        // not in the AutoDJ, to be effective on-air.
        $matchingMedia = $this->applySortOrder($matchingMedia, $playlist->smart_block_sort_order);

        $matchingIds = [];
        foreach ($matchingMedia as $media) {
            $matchingIds[$media->id] = $media;
        }

        // Build a set of media IDs already in the playlist (excluding folder-managed rows).
        $existingMediaIds = [];
        $existingCount = 0;
        $removedRecords = 0;

        foreach ($playlist->media_items as $spm) {
            // Rows managed by the Folder feature are left alone.
            if (null !== $spm->folder) {
                continue;
            }

            if (isset($matchingIds[$spm->media_id])) {
                $existingMediaIds[$spm->media_id] = true;
                $existingCount++;
                unset($matchingIds[$spm->media_id]);
            } else {
                $this->em->remove($spm);
                $removedRecords++;
            }
        }

        $addedRecords = 0;
        $isSequential = PlaylistOrders::Sequential === $playlist->order;

        // Assign weights to the full sorted pool (existing + new) so the on-air order
        // reflects the chosen sort -- existing rows keep their slot implicitly because
        // they were removed from $matchingIds above; only genuinely new tracks are added.
        $weight = 0;
        foreach ($matchingMedia as $media) {
            $weight++;

            // If avoid_duplicates is on and this track is already a member, skip adding
            // a second copy -- the existing StationPlaylistMedia row stays as-is.
            if ($playlist->smart_block_avoid_duplicates && isset($existingMediaIds[$media->id])) {
                continue;
            }

            // Only add tracks that aren't already members (they were kept in $matchingIds).
            if (!isset($matchingIds[$media->id])) {
                continue;
            }

            $record = new StationPlaylistMedia($playlist, $media);
            $record->weight = $isSequential ? $weight : random_int(1, max($weight, 1));
            $this->em->persist($record);

            $addedRecords++;
        }

        $this->em->flush();

        if ($dispatchWriteMessage && ($addedRecords > 0 || $removedRecords > 0)) {
            $message = new WritePlaylistFileMessage();
            $message->playlist_id = $playlist->id;

            $this->messageBus->dispatch($message);
        }

        return [
            'added' => $addedRecords,
            'removed' => $removedRecords,
            'total' => $existingCount + $addedRecords,
        ];
    }

    /**
     * Sort a pool of StationMedia according to the Smart Block's configured sort order.
     * Random is already handled upstream by the repository (ORDER BY RAND()), so we
     * only need to re-sort for the deterministic options.
     *
     * @param StationMedia[] $media
     * @return StationMedia[]
     */
    private function applySortOrder(array $media, SmartBlockSortOrder $order): array
    {
        return match ($order) {
            SmartBlockSortOrder::Random => $media, // Already randomised by the repo query.

            SmartBlockSortOrder::NewestFirst => (static function (array $m): array {
                usort($m, static fn(StationMedia $a, StationMedia $b) => $b->uploaded_at <=> $a->uploaded_at);
                return $m;
            })($media),

            SmartBlockSortOrder::OldestFirst => (static function (array $m): array {
                usort($m, static fn(StationMedia $a, StationMedia $b) => $a->uploaded_at <=> $b->uploaded_at);
                return $m;
            })($media),

            SmartBlockSortOrder::AlphaTitle => (static function (array $m): array {
                usort($m, static fn(StationMedia $a, StationMedia $b) =>
                    strcasecmp($a->title ?? '', $b->title ?? ''));
                return $m;
            })($media),

            SmartBlockSortOrder::AlphaArtist => (static function (array $m): array {
                usort($m, static fn(StationMedia $a, StationMedia $b) =>
                    strcasecmp($a->artist ?? '', $b->artist ?? ''));
                return $m;
            })($media),
        };
    }

    private function highestExistingWeight(StationPlaylist $playlist): int
    {
        $highest = 0;
        foreach ($playlist->media_items as $spm) {
            if ($spm->weight > $highest) {
                $highest = $spm->weight;
            }
        }
        return $highest;
    }
}
