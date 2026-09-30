<?php

namespace HiEvents\Services\Domain\Email;

class EmailHtmlToTextConverter
{
    public static function convert(string $html): string
    {
        $withUrls = preg_replace_callback(
            '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
            static function (array $matches): string {
                $label = trim(html_entity_decode(strip_tags($matches[3]), ENT_QUOTES | ENT_HTML5));
                $url = trim(html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5));

                return $label === '' || $label === $url ? $url : sprintf('%s (%s)', $label, $url);
            },
            $html,
        );
        $withBreaks = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $withUrls ?? $html);
        $withBreaks = preg_replace('/<\/?(?:p|div|h[1-6]|li|tr|table|ul|ol)\b[^>]*>/i', "\n", $withBreaks ?? $html);

        return trim((string) preg_replace('/\n{3,}/', "\n\n", preg_replace('/[ \t]+\n/', "\n", html_entity_decode(strip_tags($withBreaks ?? $html), ENT_QUOTES | ENT_HTML5))));
    }
}
