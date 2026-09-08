<?php

declare(strict_types=1);

namespace Tests\Unit\WhiteLabel;

use PHPUnit\Framework\TestCase;

/**
 * Static checks over the customer-facing backend surfaces: transactional email
 * views, the shared mail layout and theme, and the invoice PDF.
 *
 * These are pure file assertions with no framework boot, so they run without a
 * database or a configured environment.
 */
class BrandResidueTest extends TestCase
{
    private const UPSTREAM_SUPPORT_ADDRESSES = ['hello@hi.events', 'support@hi.events'];

    private function backendPath(string $relative): string
    {
        return dirname(__DIR__, 3).'/'.ltrim($relative, '/');
    }

    private function read(string $relative): string
    {
        $path = $this->backendPath($relative);
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /** @return string[] absolute paths */
    private function emailViews(): array
    {
        $root = $this->backendPath('resources/views/emails');
        $found = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    public function test_it_finds_the_email_views(): void
    {
        $this->assertGreaterThan(15, count($this->emailViews()));
    }

    public function test_no_email_routes_a_buyer_to_an_upstream_support_address(): void
    {
        $offenders = [];

        foreach ($this->emailViews() as $view) {
            $contents = file_get_contents($view);
            foreach (self::UPSTREAM_SUPPORT_ADDRESSES as $address) {
                if (str_contains($contents, $address)) {
                    $offenders[] = basename($view).' contains '.$address;
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_mail_layout_does_not_fall_back_to_an_upstream_logo(): void
    {
        $layout = $this->read('resources/views/vendor/mail/html/message.blade.php');

        $this->assertStringNotContainsString('hi-events-stacked-light.png', $layout);
        $this->assertStringContainsString('brand-wordmark', $layout);
    }

    public function test_application_and_mail_defaults_are_brand_owned(): void
    {
        $this->assertStringContainsString(
            "env('APP_NAME', 'Kamp Love')",
            $this->read('config/app.php')
        );
        $this->assertStringContainsString(
            "env('MAIL_FROM_NAME', 'Kamp Love')",
            $this->read('config/mail.php')
        );
        $this->assertStringNotContainsString(
            "'hello@hi.events'",
            $this->read('config/mail.php')
        );
    }

    public function test_the_email_theme_projects_the_kamp_palette(): void
    {
        $theme = $this->read('resources/views/vendor/mail/html/themes/default.css');

        // Allowlist, not denylist. The previous denylist named six stock values and
        // passed while #b0adc5 survived on .footer p at 2.00:1 against the new cream
        // ground. An allowlist cannot miss a value nobody thought to forbid.
        //
        // Email HTML supports no custom properties, so these are literal projections
        // of --kamp-* and must stay in step with frontend/src/styles/global.scss.
        preg_match_all('/#[0-9a-fA-F]{3,6}\b/', $theme, $matches);
        $used = array_unique(array_map('strtolower', $matches[0]));

        $allowed = [
            '#171717', // --kamp-ink
            '#585254', // --kamp-muted
            '#dadada', // --kamp-line
            '#f9f4f0', // --kamp-cream
            '#efe7db', // --kamp-sand
            '#2f5147', // --kamp-forest
            '#2f6b4f', // --kamp-ok
            '#9f3620', // --kamp-stop
            '#ffffff', '#fff',
        ];

        $this->assertSame(
            [],
            array_values(array_diff($used, $allowed)),
            'non-Kamp colour present in the email adapter table'
        );

        // The projection must actually be present, not merely free of stock values.
        foreach (['#f9f4f0', '#585254', '#2f5147', '#171717'] as $kamp) {
            $this->assertStringContainsString($kamp, $theme, "kamp value {$kamp} missing");
        }
    }

    public function test_email_footer_text_meets_the_normal_text_contrast_threshold(): void
    {
        // .footer p and .footer a render the copyright line and any configured
        // APP_EMAIL_FOOTER_TEXT on the .body ground. This pairing was 2.00:1.
        $theme = $this->read('resources/views/vendor/mail/html/themes/default.css');

        $this->assertMatchesRegularExpression('/\.footer p \{[^}]*color: #585254/s', $theme);
        $this->assertMatchesRegularExpression('/\.footer a \{[^}]*color: #585254/s', $theme);

        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrastRatio('#585254', '#f9f4f0'),
            'email footer text must meet the 4.5:1 normal-text threshold'
        );
    }

    /** WCAG 2.1 relative luminance and contrast ratio. */
    private function contrastRatio(string $foreground, string $background): float
    {
        $luminance = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channel = static function (int $value): float {
                $c = $value / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
                + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
                + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));
        };

        $a = $luminance($foreground);
        $b = $luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    public function test_the_invoice_pdf_uses_only_kamp_palette_values(): void
    {
        $invoice = $this->read('resources/views/invoice.blade.php');

        preg_match_all('/#[0-9a-fA-F]{3,6}\b/', $invoice, $matches);
        $used = array_unique(array_map('strtolower', $matches[0]));

        $allowed = [
            '#171717', '#585254', '#dadada', '#f9f4f0', '#efe7db',
            '#2f6b4f', '#e7f0ea', '#9f3620', '#f5e2dc', '#ffffff', '#fff',
        ];

        $this->assertSame([], array_values(array_diff($used, $allowed)));
    }

    public function test_the_licence_attribution_is_retained_in_email(): void
    {
        $layout = $this->read('resources/views/vendor/mail/html/message.blade.php');
        $theme = $this->read('resources/views/vendor/mail/html/themes/default.css');

        // Retained, not removed. Subordination is by order and scale only.
        $this->assertStringContainsString('Powered by', $layout);
        $this->assertStringContainsString('hi.events', $layout);

        // The notice must sit OUTSIDE the app.email_footer_text branch. If it were
        // inside the @else, configuring APP_EMAIL_FOOTER_TEXT to add brand identity
        // would also delete the notice, making a styling variable a licensing lever.
        //
        // Anchored on the footer-text @if specifically. A previous version of this
        // test used strpos($layout, '@else'), which finds the HEADER LOGO block's
        // else branch (message.blade.php has two @if/@else/@endif pairs), so it
        // asserted on a slice that can never contain the string and always passed.
        $branchStart = strpos($layout, '@if($appEmailFooter');
        $this->assertNotFalse($branchStart, 'footer-text conditional not found');

        $branchEnd = strpos($layout, '@endif', $branchStart);
        $this->assertNotFalse($branchEnd, 'footer-text conditional is unterminated');

        $footerTextBranch = substr($layout, $branchStart, $branchEnd - $branchStart);

        $this->assertStringContainsString('@else', $footerTextBranch, 'wrong block sliced');
        $this->assertStringNotContainsString(
            'Powered by',
            $footerTextBranch,
            'attribution must not live inside the email_footer_text conditional'
        );

        // ...and it must actually appear after that conditional closes.
        $afterBranch = substr($layout, $branchEnd);
        $this->assertStringContainsString('Powered by', $afterBranch);

        // Legibility floor: 13px, and not hidden.
        $this->assertMatchesRegularExpression('/\.attribution\s*\{[^}]*font-size:\s*13px/s', $theme);
        $this->assertStringNotContainsString('display: none', $theme);
    }
}
