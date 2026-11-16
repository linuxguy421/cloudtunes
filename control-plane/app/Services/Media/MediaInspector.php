<?php

namespace App\\Services\\Media;

use JsonException;
use Symfony\\Component\\Process\\Process;

class MediaInspector
{
    private const MIN_SAMPLE_RATE = 8000;
    private const MAX_SAMPLE_RATE = 192000;

    /**
     * Inspect an uploaded file and return its validated technical profile and tags.
     *
     * @return array{container:string,codec:string,sample_rate:int,channels:int,artist:string,title:string}
     */
    public function inspect(string $path): array
    {
        $process = new Process([
            'ffprobe',
            '-v', 'error',
            '-show_format',
            '-show_streams',
            '-of', 'json',
            $path,
        ]);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new InvalidMediaFileException('The uploaded file could not be read as valid audio.');
        }

        try {
            $probe = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidMediaFileException('The audio inspector returned an invalid response.');
        }

        $formatNames = explode(',', strtolower((string) ($probe['format']['format_name'] ?? '')));
        if (! in_array('ogg', $formatNames, true)) {
            throw new InvalidMediaFileException('The file must use the Ogg container.');
        }

        $audioStreams = array_values(array_filter(
            $probe['streams'] ?? [],
            static fn (array $stream): bool => ($stream['codec_type'] ?? null) === 'audio',
        ));

        if (count($audioStreams) !== 1) {
            throw new InvalidMediaFileException('The file must contain exactly one audio stream.');
        }

        $stream = $audioStreams[0];
        if (strtolower((string) ($stream['codec_name'] ?? '')) !== 'vorbis') {
            throw new InvalidMediaFileException('The audio codec must be Vorbis.');
        }

        $sampleRate = filter_var($stream['sample_rate'] ?? null, FILTER_VALIDATE_INT);
        if ($sampleRate === false || $sampleRate < self::MIN_SAMPLE_RATE || $sampleRate > self::MAX_SAMPLE_RATE) {
            throw new InvalidMediaFileException('The sample rate must be between 8,000 and 192,000 Hz.');
        }

        $channels = filter_var($stream['channels'] ?? null, FILTER_VALIDATE_INT);
        if ($channels !== 2) {
            throw new InvalidMediaFileException('The audio must be stereo (two channels).');
        }

        $tags = array_change_key_case(
            array_merge($probe['format']['tags'] ?? [], $stream['tags'] ?? []),
            CASE_LOWER,
        );

        $artist = $this->normalizeTag($tags['artist'] ?? null);
        $title = $this->normalizeTag($tags['title'] ?? null);

        if ($this->isPlaceholder($artist)) {
            throw new InvalidMediaFileException('The audio file must contain a valid ARTIST tag.');
        }

        if ($this->isPlaceholder($title)) {
            throw new InvalidMediaFileException('The audio file must contain a valid TITLE tag.');
        }

        return [
            'container' => 'ogg',
            'codec' => 'vorbis',
            'sample_rate' => $sampleRate,
            'channels' => $channels,
            'artist' => $artist,
            'title' => $title,
        ];
    }

    private function normalizeTag(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $normalized = preg_replace('/\\s+/u', ' ', trim($value));

        return is_string($normalized) ? $normalized : '';
    }

    private function isPlaceholder(string $value): bool
    {
        return $value === '' || in_array(mb_strtolower($value), [
            'unknown',
            'unknown artist',
            'unknown title',
            'n/a',
            'none',
            'null',
            'untitled',
            'track',
            '-',
        ], true);
    }
}
