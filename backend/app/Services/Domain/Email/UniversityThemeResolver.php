<?php

namespace HiEvents\Services\Domain\Email;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use Psr\Log\LoggerInterface;

class UniversityThemeResolver
{
    private const REQUIRED_ROLES = ['primary', 'secondary', 'onPrimary', 'onSecondary', 'secondarySoft'];

    private static array $manifestCache = [];
    private static array $manifestFailureReasons = [];
    private static array $loggedFallbacks = [];

    public function __construct(
        private readonly ?string $manifestPath = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function resolveForEvent(EventDomainObject $event): ?UniversityEmailThemeDTO
    {
        return $this->resolveForSlug($event->getSlug());
    }

    public function resolveForSlug(?string $slug): ?UniversityEmailThemeDTO
    {
        if (!$slug) {
            $this->logFallback('missing_slug');
            return null;
        }

        $path = $this->resolvedManifestPath();
        $manifest = $this->manifest($path);
        $manifestFailureReason = self::$manifestFailureReasons[$path] ?? null;
        if ($manifestFailureReason !== null) {
            $this->logFallback($manifestFailureReason);
            return null;
        }

        if (!array_key_exists($slug, $manifest)) {
            $this->logFallback('missing_slug_entry', $slug);
            return null;
        }

        $entry = $manifest[$slug];
        if (!is_array($entry)) {
            $this->logFallback('invalid_entry', $slug);
            return null;
        }

        if (count($entry) !== count(self::REQUIRED_ROLES) || array_diff(array_keys($entry), self::REQUIRED_ROLES) !== []) {
            $this->logFallback('incomplete_or_extra_entry', $slug);
            return null;
        }

        foreach (self::REQUIRED_ROLES as $role) {
            if (!is_string($entry[$role]) || !preg_match('/^#[0-9a-f]{6}$/', $entry[$role])) {
                $this->logFallback('invalid_color_value', $slug);
                return null;
            }
        }

        return new UniversityEmailThemeDTO(
            primary: $entry['primary'],
            secondary: $entry['secondary'],
            onPrimary: $entry['onPrimary'],
            onSecondary: $entry['onSecondary'],
            secondarySoft: $entry['secondarySoft'],
        );
    }

    private function manifest(string $path): array
    {
        if (array_key_exists($path, self::$manifestCache)) {
            return self::$manifestCache[$path];
        }

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

    private function resolvedManifestPath(): string
    {
        return $this->manifestPath ?? base_path('../frontend/src/styles/universityThemes.json');
    }

    private function logFallback(string $reason, ?string $entryKey = null): void
    {
        $deduplicationKey = implode(':', [$this->resolvedManifestPath(), $reason, $entryKey ?? '']);
        if (isset(self::$loggedFallbacks[$deduplicationKey])) {
            return;
        }

        self::$loggedFallbacks[$deduplicationKey] = true;
        $this->logger?->warning('University email theme fallback', ['reason' => $reason]);
    }
}
