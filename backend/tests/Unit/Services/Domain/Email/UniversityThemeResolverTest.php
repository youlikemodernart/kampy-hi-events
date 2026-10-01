<?php

namespace Tests\Unit\Services\Domain\Email;

use HiEvents\Services\Domain\Email\UniversityThemeResolver;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class UniversityThemeResolverTest extends TestCase
{
    public function test_resolves_gvsu_from_the_shared_manifest(): void
    {
        $resolver = new UniversityThemeResolver(base_path('../frontend/src/styles/universityThemes.json'));

        $theme = $resolver->resolveForSlug('grand-valley-state-university');

        $this->assertNotNull($theme);
        $this->assertSame('#0032a0', $theme->primary);
        $this->assertSame('#13155c', $theme->secondary);
        $this->assertSame('#ffffff', $theme->onPrimary);
        $this->assertSame('#ffffff', $theme->onSecondary);
        $this->assertSame('#e7e7ed', $theme->secondarySoft);
    }

    public function test_resolves_the_reserved_shared_kamp_fallback_through_the_same_validation_path(): void
    {
        $resolver = new UniversityThemeResolver(base_path('../frontend/src/styles/universityThemes.json'));

        $fallback = $resolver->resolveFallback();

        $this->assertNotNull($fallback);
        $this->assertSame('#2b663d', $fallback->primary);
        $this->assertNull($resolver->resolveForSlug('_kampFallback'));
    }

    public function test_uses_a_changed_manifest_secondary_for_the_email_cta_role(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'university-theme-');
        file_put_contents($path, json_encode([
            'grand-valley-state-university' => [
                'primary' => '#0032a0', 'secondary' => '#123456', 'onPrimary' => '#ffffff',
                'onSecondary' => '#ffffff', 'secondarySoft' => '#e7e7ed',
            ],
        ]));

        $theme = (new UniversityThemeResolver($path))->resolveForSlug('grand-valley-state-university');

        $this->assertSame('#123456', $theme?->secondary);
        unlink($path);
    }

    public function test_manifest_cache_survives_separate_resolver_instances_for_the_process_lifetime(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'university-theme-cache-');
        $entry = [
            'primary' => '#0032a0', 'secondary' => '#123456', 'onPrimary' => '#ffffff',
            'onSecondary' => '#ffffff', 'secondarySoft' => '#e7e7ed',
        ];
        file_put_contents($path, json_encode(['grand-valley-state-university' => $entry]));
        $this->assertSame('#123456', (new UniversityThemeResolver($path))->resolveForSlug('grand-valley-state-university')?->secondary);

        $entry['secondary'] = '#654321';
        file_put_contents($path, json_encode(['grand-valley-state-university' => $entry]));
        $this->assertSame('#123456', (new UniversityThemeResolver($path))->resolveForSlug('grand-valley-state-university')?->secondary);
        unlink($path);
    }

    public function test_rejects_unknown_incomplete_invalid_and_extra_theme_entries_with_redacted_deduplicated_logs(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'university-theme-');
        file_put_contents($path, json_encode([
            'incomplete' => ['primary' => '#0032a0'],
            'invalid' => [
                'primary' => '#0032a0', 'secondary' => '#13155c', 'onPrimary' => 'white',
                'onSecondary' => '#ffffff', 'secondarySoft' => '#e7e7ed',
            ],
            'extra' => [
                'primary' => '#0032a0', 'secondary' => '#13155c', 'onPrimary' => '#ffffff',
                'onSecondary' => '#ffffff', 'secondarySoft' => '#e7e7ed', 'status' => '#ff0000',
            ],
        ]));
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->times(4)->withArgs(function (string $message, array $context): bool {
            $this->assertSame('University email theme fallback', $message);
            $this->assertArrayHasKey('reason', $context);
            $this->assertCount(1, $context);
            $this->assertStringNotContainsString('recipient', json_encode($context));
            $this->assertStringNotContainsString('order', json_encode($context));
            return true;
        });
        $resolver = new UniversityThemeResolver($path, $logger);

        $this->assertNull($resolver->resolveForSlug('unknown'));
        $this->assertNull($resolver->resolveForSlug('incomplete'));
        $this->assertNull($resolver->resolveForSlug('invalid'));
        $this->assertNull($resolver->resolveForSlug('extra'));
        $this->assertNull($resolver->resolveForSlug('extra'));
        unlink($path);
    }

    public function test_logs_manifest_failures_once_without_sensitive_context(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->with('University email theme fallback', ['reason' => 'manifest_unreadable']);
        $firstResolver = new UniversityThemeResolver('/missing/universityThemes.json', $logger);
        $secondResolver = new UniversityThemeResolver('/missing/universityThemes.json', $logger);

        $this->assertNull($firstResolver->resolveForSlug('grand-valley-state-university'));
        $this->assertNull($secondResolver->resolveForSlug('grand-valley-state-university'));

        $path = tempnam(sys_get_temp_dir(), 'university-theme-');
        file_put_contents($path, '{');
        $malformedLogger = Mockery::mock(LoggerInterface::class);
        $malformedLogger->shouldReceive('warning')->once()->with('University email theme fallback', ['reason' => 'manifest_malformed']);
        $this->assertNull((new UniversityThemeResolver($path, $malformedLogger))->resolveForSlug('grand-valley-state-university'));
        unlink($path);
    }
}
