<?php

declare(strict_types=1);

namespace App\Service\Streaming\Model;

/**
 * How authoritative a candidate's credited artist is, per the provider's own signal (D-119, spec 12
 * §3 signal 6). Generic across providers — an adapter maps its own "verified artist" flag or
 * equivalent onto one of these three cases, or omits the signal entirely by leaving
 * `TrackCandidate::$artistAuthority` at `Unknown`.
 */
enum ArtistAuthority: string
{
    case Official = 'official';
    case Verified = 'verified';
    case Unknown = 'unknown';
}
