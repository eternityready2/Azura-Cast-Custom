<?php

declare(strict_types=1);

namespace App\Radio\SmartBlock;

use App\Entity\Enums\PlaylistOrders;
use App\Entity\Repository\StationPlaylistSmartBlockCriteriaRepository;
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
        $matchingIds = [];
        foreach ($matchingMedia as $media) {
            $matchingIds[$media->id] = $media;
        }

        $existingCount = 0;
        $removedRecords = 0;

        foreach ($playlist->media_items as $spm) {
            // Rows managed by the Folder feature are left alone.
            if (null !== $spm->folder) {
                continue;
            }

            if (isset($matchingIds[$spm->media_id])) {
                $existingCount++;
                unset($matchingIds[$spm->media_id]);
            } else {
                $this->em->remove($spm);
                $removedRecords++;
            }
        }

        $addedRecords = 0;
        $weight = $this->highestExistingWeight($playlist);
        $isSequential = PlaylistOrders::Sequential === $playlist->order;

        foreach ($matchingIds as $media) {
            $weight++;

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
