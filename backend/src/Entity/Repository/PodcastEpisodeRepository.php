<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Doctrine\Repository;
use App\Entity\Enums\PodcastEpisodeStorageType;
use App\Entity\Enums\PodcastSources;
use App\Entity\Podcast;
use App\Entity\PodcastEpisode;
use App\Entity\PodcastMedia;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StorageLocation;
use App\Exception\StorageLocationFullException;
use App\Flysystem\ExtendedFilesystemInterface;
use App\Flysystem\StationFilesystems;
use App\Media\AlbumArt;
use App\Media\MetadataManager;
use InvalidArgumentException;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToRetrieveMetadata;
use LogicException;

/**
 * @extends Repository<PodcastEpisode>
 */
final class PodcastEpisodeRepository extends Repository
{
    public const string MEDIA_FOLDER_PREFIX = 'podcast_imports/';

    protected string $entityClass = PodcastEpisode::class;

    public function __construct(
        private readonly MetadataManager $metadataManager,
        private readonly StorageLocationRepository $storageLocationRepo,
        private readonly StationMediaRepository $stationMediaRepo,
        private readonly StationFilesystems $stationFilesystems,
    ) {
    }

    public function fetchEpisodeForPodcast(Podcast $podcast, string $episodeId): ?PodcastEpisode
    {
        return $this->repository->findOneBy([
            'id' => $episodeId,
            'podcast' => $podcast,
        ]);
    }

    public function fetchEpisodeForStation(Station $station, string $episodeId): ?PodcastEpisode
    {
        return $this->fetchEpisodeForStorageLocation(
            $station->podcasts_storage_location,
            $episodeId
        );
    }

    public function fetchEpisodeForStorageLocation(
        StorageLocation $storageLocation,
        string $episodeId
    ): ?PodcastEpisode {
        return $this->em->createQuery(
            <<<'DQL'
                SELECT pe
                FROM App\Entity\PodcastEpisode pe
                JOIN pe.podcast p
                WHERE pe.id = :id
                AND p.storage_location = :storageLocation
            DQL
        )->setParameter('id', $episodeId)
            ->setParameter('storageLocation', $storageLocation)
            ->getOneOrNullResult();
    }

    private function getStationForPodcast(Podcast $podcast): ?Station
    {
        $stations = $this->storageLocationRepo->getStationsForLocation($podcast->storage_location);
        return $stations[0] ?? null;
    }

    public function writeEpisodeArt(
        PodcastEpisode $episode,
        string $rawArtworkString
    ): void {
        $episodeArtworkString = AlbumArt::resize($rawArtworkString);

        $storageLocation = $episode->podcast->storage_location;
        $fs = $this->storageLocationRepo->getAdapter($storageLocation)
            ->getFilesystem();

        $episodeArtworkSize = strlen($episodeArtworkString);
        if (!$storageLocation->canHoldFile($episodeArtworkSize)) {
            throw new StorageLocationFullException();
        }

        $episodeArtworkPath = PodcastEpisode::getArtPath($episode->id);
        $fs->write($episodeArtworkPath, $episodeArtworkString);

        $storageLocation->addStorageUsed($episodeArtworkSize);
        $this->em->persist($storageLocation);

        $episode->art_updated_at = time();
        $this->em->persist($episode);
    }

    public function removeEpisodeArt(
        PodcastEpisode $episode,
        ?ExtendedFilesystemInterface $fs = null
    ): void {
        $artworkPath = PodcastEpisode::getArtPath($episode->id);

        $storageLocation = $episode->podcast->storage_location;
        $fs ??= $this->storageLocationRepo->getAdapter($storageLocation)
            ->getFilesystem();

        try {
            $size = $fs->fileSize($artworkPath);
        } catch (UnableToRetrieveMetadata) {
            $size = 0;
        }

        try {
            $fs->delete($artworkPath);
        } catch (UnableToDeleteFile) {
        }

        $storageLocation->removeStorageUsed($size);
        $this->em->persist($storageLocation);

        $episode->art_updated_at = 0;
        $this->em->persist($episode);
    }

    public function uploadMedia(
        PodcastEpisode $episode,
        string $originalPath,
        string $uploadPath,
        ?ExtendedFilesystemInterface $fs = null
    ): void {
        $podcast = $episode->podcast;

        if ($podcast->source !== PodcastSources::Manual && $podcast->source !== PodcastSources::Import) {
            throw new LogicException('Cannot upload media to this podcast type.');
        }

        $metadata = $this->metadataManager->read($uploadPath);
        if (!in_array($metadata->getMimeType(), ['audio/x-m4a', 'audio/mpeg'])) {
            throw new InvalidArgumentException(
                sprintf('Invalid Podcast Media mime type: %s', $metadata->getMimeType())
            );
        }

        $size = filesize($uploadPath) ?: 0;
        $ext = pathinfo($originalPath, PATHINFO_EXTENSION) ?: 'mp3';

        if ($podcast->episode_storage_type === PodcastEpisodeStorageType::Media) {
            $this->uploadMediaToMediaFolder($episode, $originalPath, $uploadPath, $ext, $size, $metadata);
        } else {
            $this->uploadMediaToPodcastFolder($episode, $originalPath, $uploadPath, $ext, $size, $metadata, $fs);
        }

        $artwork = $metadata->getArtwork();
        if (!empty($artwork) && 0 === $episode->art_updated_at) {
            $this->writeEpisodeArt($episode, $artwork);
        }

        $this->em->persist($episode);
        $this->em->flush();
    }

    private function uploadMediaToMediaFolder(
        PodcastEpisode $episode,
        string $originalPath,
        string $uploadPath,
        string $ext,
        int $size,
        \App\Media\MetadataInterface $metadata
    ): void {
        $podcast = $episode->podcast;
        $station = $this->getStationForPodcast($podcast);
        if ($station === null) {
            throw new LogicException('Cannot resolve station for podcast (media folder).');
        }

        $mediaStorage = $station->media_storage_location;
        if (!$mediaStorage->canHoldFile($size)) {
            throw new StorageLocationFullException();
        }

        $fsMedia = $this->stationFilesystems->getMediaFilesystem($station);

        $this->removeEpisodePlaylistMediaIfImport($episode, $station);

        $path = self::MEDIA_FOLDER_PREFIX . $podcast->id . '/' . $episode->id . '.' . $ext;

        $stationMedia = new StationMedia($mediaStorage, $path);
        $this->stationMediaRepo->loadFromFile($stationMedia, $uploadPath, $fsMedia);
        if (($episode->title ?? '') !== '') {
            $stationMedia->title = $episode->title;
            $stationMedia->updateMetaFields();
        }

        $fsMedia->uploadAndDeleteOriginal($uploadPath, $path);
        $mediaStorage->addStorageUsed($size);
        $this->em->persist($mediaStorage);
        $this->em->persist($stationMedia);

        $episode->playlist_media = $stationMedia;
        $episode->media = null;
    }

    private function uploadMediaToPodcastFolder(
        PodcastEpisode $episode,
        string $originalPath,
        string $uploadPath,
        string $ext,
        int $size,
        \App\Media\MetadataInterface $metadata,
        ?ExtendedFilesystemInterface $fs
    ): void {
        $podcast = $episode->podcast;
        $storageLocation = $podcast->storage_location;
        $fs ??= $this->storageLocationRepo->getAdapter($storageLocation)->getFilesystem();

        if (!$storageLocation->canHoldFile($size)) {
            throw new StorageLocationFullException();
        }

        $existingMedia = $episode->media;
        if ($existingMedia instanceof PodcastMedia) {
            $this->deleteMedia($existingMedia, $fs);
            $episode->media = null;
        }

        $path = $podcast->id . '/' . $episode->id . '.' . $ext;

        $podcastMedia = new PodcastMedia($storageLocation);
        $podcastMedia->path = $path;
        $podcastMedia->original_name = basename($originalPath);
        $podcastMedia->length = $metadata->getDuration();
        $podcastMedia->mime_type = $metadata->getMimeType();

        $fs->uploadAndDeleteOriginal($uploadPath, $path);

        $podcastMedia->episode = $episode;
        $this->em->persist($podcastMedia);
        $storageLocation->addStorageUsed($size);
        $this->em->persist($storageLocation);
        $episode->media = $podcastMedia;
    }

    private function removeEpisodePlaylistMediaIfImport(PodcastEpisode $episode, Station $station): void
    {
        if ($episode->podcast->source !== PodcastSources::Import) {
            return;
        }
        $playlistMedia = $episode->playlist_media;
        if (!$playlistMedia instanceof StationMedia) {
            return;
        }
        $this->deleteStationMediaAndFile($playlistMedia);
        $episode->playlist_media = null;
    }

    private function deleteStationMediaAndFile(StationMedia $media): void
    {
        $storageLocation = $media->storage_location;
        $fs = $this->storageLocationRepo->getAdapter($storageLocation)->getFilesystem();
        $path = $media->path;
        try {
            $size = $fs->fileSize($path);
        } catch (UnableToRetrieveMetadata) {
            $size = 0;
        }
        try {
            $fs->delete($path);
        } catch (UnableToDeleteFile) {
        }
        $storageLocation->removeStorageUsed($size);
        $this->em->persist($storageLocation);
        $this->em->remove($media);
    }

    public function deleteMedia(
        PodcastMedia $media,
        ?ExtendedFilesystemInterface $fs = null
    ): void {
        $storageLocation = $media->storage_location;
        $fs ??= $this->storageLocationRepo->getAdapter($storageLocation)
            ->getFilesystem();

        $mediaPath = $media->path;

        try {
            $size = $fs->fileSize($mediaPath);
        } catch (UnableToRetrieveMetadata) {
            $size = 0;
        }

        try {
            $fs->delete($mediaPath);
        } catch (UnableToDeleteFile) {
        }

        $storageLocation->removeStorageUsed($size);
        $this->em->persist($storageLocation);

        $this->em->remove($media);
        $this->em->flush();
    }

    public function delete(
        PodcastEpisode $episode,
        ?ExtendedFilesystemInterface $fs = null
    ): void {
        $podcast = $episode->podcast;
        $fs ??= $this->storageLocationRepo->getAdapter($podcast->storage_location)->getFilesystem();

        $media = $episode->media;
        if (null !== $media) {
            $this->deleteMedia($media, $fs);
        }

        if ($podcast->source === PodcastSources::Import && $episode->playlist_media instanceof StationMedia) {
            $this->deleteStationMediaAndFile($episode->playlist_media);
            $episode->playlist_media = null;
        }

        $this->removeEpisodeArt($episode, $fs);

        $this->em->remove($episode);
        $this->em->flush();
    }
}
