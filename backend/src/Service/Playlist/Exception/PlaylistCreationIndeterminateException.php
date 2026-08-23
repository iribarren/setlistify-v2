<?php

declare(strict_types=1);

namespace App\Service\Playlist\Exception;

/**
 * F-14: `creationAttemptedAt` is set but `providerPlaylistId` is null — the one unclosable window
 * (P-3). `CreationStage` throws this rather than risk a second `createPlaylist()` call; it lands
 * the job in `failed`/`creation_indeterminate`, recoverable only via `POST …/create-anyway`.
 */
final class PlaylistCreationIndeterminateException extends \RuntimeException
{
}
