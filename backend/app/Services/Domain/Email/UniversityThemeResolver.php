<?php

namespace HiEvents\Services\Domain\Email;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use Psr\Log\LoggerInterface;

class UniversityThemeResolver
{
    private const REQUIRED_ROLES = ['primary', 'secondary', 'onPrimary', 'onSecondary', 'secondarySoft'];
    private const FALLBACK_KEY = '_kampFallback';
    private static array $manifestCache = [];
    private static array $manifestFailureReasons = [];
    private static array $loggedFallbacks = [];

    public function __construct(private readonly ?string $manifestPath = null, private readonly ?LoggerInterface $logger = null)
    {
    }

    public function resolveForEvent(EventDomainObject $event): ?UniversityEmailThemeDTO
    {
        return $this->resolveForSlug($event->getSlug());
    }

    public function resolveForSlug(?string $slug): ?UniversityEmailThemeDTO
    {
        if (!$slug || $slug === self::FALLBACK_KEY) {
            $this->logFallback('missing_or_reserved_slug');
            return null;
        }
        return $this->resolveEntry($slug, false);
    }

    public function resolveFallback(): ?UniversityEmailThemeDTO
    {
        return $this->resolveEntry(self::FALLBACK_KEY, true);
    }

    private function resolveEntry(string $key, bool $fallback): ?UniversityEmailThemeDTO
    {
        $path = $this->resolvedManifestPath();
        $manifest = $this->manifest($path);
        if ((self::$manifestFailureReasons[$path] ?? null) !== null) {
            $this->logFallback(self::$manifestFailureReasons[$path]);
            return null;
        }
        $entry = $manifest[$key] ?? null;
        if (!is_array($entry) || count($entry) !== count(self::REQUIRED_ROLES) || array_diff(array_keys($entry), self::REQUIRED_ROLES) !== []) {
            $this->logFallback($fallback ? 'invalid_fallback_entry' : 'missing_or_invalid_slug_entry', $key);
            return null;
        }
        foreach (self::REQUIRED_ROLES as $role) {
            if (!is_string($entry[$role]) || !preg_match('/^#[0-9a-f]{6}$/', $entry[$role])) {
                $this->logFallback($fallback ? 'invalid_fallback_color' : 'invalid_color_value', $key);
                return null;
            }
        }
        return new UniversityEmailThemeDTO($entry['primary'], $entry['secondary'], $entry['onPrimary'], $entry['onSecondary'], $entry['secondarySoft']);
    }

    private function manifest(string $path): array
    {
        if (array_key_exists($path, self::$manifestCache)) return self::$manifestCache[$path];
        if (!is_readable($path)) {
            self::$manifestFailureReasons[$path] = 'manifest_unreadable';
            return self::$manifestCache[$path] = [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            self::$manifestFailureReasons[$path] = 'manifest_malformed';
            return self::$manifestCache[$path] = [];
        }
        return self::$manifestCache[$path] = $decoded;
    }

    private function resolvedManifestPath(): string { return $this->manifestPath ?? base_path('../frontend/src/styles/universityThemes.json'); }

    private function logFallback(string $reason, ?string $entryKey = null): void
    {
        $key = implode(':', [$this->resolvedManifestPath(), $reason, $entryKey ?? '']);
        if (isset(self::$loggedFallbacks[$key])) return;
        self::$loggedFallbacks[$key] = true;
        $this->logger?->warning('University email theme fallback', ['reason' => $reason]);
    }
}
