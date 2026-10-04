<?php

namespace Tests\Unit\Mail\Event;

use DOMDocument;
use DOMXPath;
use HiEvents\Mail\Event\EventReminder;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use HiEvents\Services\Domain\Message\DTO\EventReminderContext;
use Tests\TestCase;

class EventReminderTest extends TestCase
{
    private const EVENT_URL = 'https://tickets.kamplove.org/event/7/grand-valley-state-university';

    private const PREFERENCE_URL = 'https://tickets.kamplove.org/preferences';

    private const SUPPORT_EMAIL = 'support@kamplove.org';

    private const SUPPORT_LINE = 'Questions? Contact us at support@kamplove.org.';

    private const ADDRESS = '1 Fixture Way, Allendale, MI';

    public function test_html_and_text_contain_the_same_required_facts(): void
    {
        $context = new EventReminderContext(
            'Kamp Love GVSU', 'https://tickets.kamplove.org/event/7/grand-valley-state-university',
            'October 8, 2026 7:00 PM', 'America/Detroit', 'Kirkhof Center', 'support@kamplove.org',
            'tickets@kamplove.org', 'support@kamplove.org', 'Kamp Love', 'https://tickets.kamplove.org/preferences',
            'Kamp Love GVSU — October 8, 2026 7:00 PM America/Detroit',
            new UniversityEmailThemeDTO('#0032a0', '#13155c', '#ffffff', '#ffffff', '#e7e7ed'),
        );
        $mail = new EventReminder($context);
        $html = $mail->render();
        $text = view('emails.event.reminder-text', ['context' => $context->payload(), 'theme' => $context->theme])->render();

        foreach (['https://tickets.kamplove.org/event/7/grand-valley-state-university', 'support@kamplove.org', 'Kamp Love GVSU', 'October 8, 2026 7:00 PM', 'America/Detroit', 'Kirkhof Center', 'https://tickets.kamplove.org/preferences'] as $fact) {
            $this->assertStringContainsString($fact, $html);
            $this->assertStringContainsString($fact, $text);
        }
        $this->assertStringContainsString('#0032a0', $html);
        $this->assertStringContainsString('#13155c', $html);
    }

    public function test_html_and_text_present_the_same_facts_in_the_same_order(): void
    {
        $context = $this->reminderContext();
        $html = $this->renderHtml($context);
        $visible = $this->visibleText($html);
        $text = $this->renderText($context);
        $facts = [
            'Kamp Love',
            "You're registered for Grand Valley Kamp Night",
            'Event Details',
            'Grand Valley Kamp Night',
            'October 8, 2026 7:00 PM America/Detroit',
            'Kirkhof Center',
            'View event details',
            self::EVENT_URL,
            self::SUPPORT_LINE,
            self::ADDRESS,
            'Email preferences',
        ];

        $this->assertStringStartsWith("Kamp Love You're registered for Grand Valley Kamp Night Event Details", $visible);
        $this->assertStringStartsWith("Kamp Love\n\nYou're registered for Grand Valley Kamp Night\n\nEvent Details\n", $text);
        $this->assertFactsInOrder($facts, $visible);
        $this->assertFactsInOrder($facts, $text);
        $this->assertStringContainsString('href="'.self::PREFERENCE_URL.'"', $html);
        $this->assertStringContainsString('Email preferences: '.self::PREFERENCE_URL, $text);
    }

    public function test_html_declares_locale_bound_language_and_light_color_scheme(): void
    {
        app()->setLocale('fr_CA');
        $html = $this->renderHtml($this->reminderContext());
        app()->setLocale('en');

        $this->assertStringContainsString('<html lang="fr-CA">', $html);
        $this->assertStringNotContainsString('lang="en"', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertStringContainsString('<meta name="color-scheme" content="light">', $html);
        $this->assertStringContainsString('<meta name="supported-color-schemes" content="light">', $html);
        $this->assertStringContainsString('<title>Grand Valley Kamp Night</title>', $html);
        $this->assertStringContainsString('<html lang="en">', $this->renderHtml($this->reminderContext()));
    }

    public function test_html_uses_the_canonical_shell_with_each_theme_role_in_its_place(): void
    {
        $html = $this->renderHtml($this->reminderContext(theme: new UniversityEmailThemeDTO('#101820', '#203040', '#f0f0f1', '#f0f0f2', '#e0e0e3')));
        $xpath = $this->xpath($html);

        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('<body style="margin:0;padding:0;background-color:#f4f5f7;color:#222222;', $html);
        $this->assertStringContainsString('style="width:100%;table-layout:fixed;border-collapse:collapse;background-color:#f4f5f7;"', $html);
        $this->assertStringContainsString('<td align="center" style="padding:12px;">', $html);
        $this->assertStringContainsString('style="width:100%;max-width:600px;table-layout:fixed;border-collapse:collapse;background-color:#ffffff;border:1px solid #d8dbe2;border-radius:10px;"', $html);
        $this->assertStringContainsString('<td height="10" style="height:10px;line-height:10px;font-size:0;background-color:#101820;color:#f0f0f1;', $html);
        $this->assertStringContainsString('padding:28px 20px 32px;word-break:break-word;overflow-wrap:anywhere;', $html);
        $this->assertStringContainsString('color:#101820;font-size:14px;line-height:20px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Kamp Love</p>', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<h1 style="margin:0 0 18px;color:#222222;font-size:28px;line-height:34px;font-weight:700;">You&#039;re registered for Grand Valley Kamp Night</h1>', $html);
        $this->assertStringContainsString('background-color:#e0e0e3;border-radius:8px;"><tr><td style="padding:18px 20px;color:#222222;font-size:16px;line-height:25px;">', $html);
        $this->assertSame(2, substr_count($html, '#101820'));
        $this->assertSame(1, substr_count($html, '#f0f0f1'));
        $this->assertSame(1, substr_count($html, '#f0f0f2'));
        $this->assertSame(1, substr_count($html, '#e0e0e3'));
        foreach ($xpath->query('//a') as $link) {
            $role = $link->parentNode->hasAttribute('bgcolor') ? 'color:#f0f0f2;' : 'color:#203040;';
            $this->assertStringContainsString($role, $link->getAttribute('style'));
        }
        foreach (['width="600"', '<style', '<link', '<img', 'class='] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    public function test_call_to_action_is_a_full_width_bulletproof_button_with_a_wrapped_fallback_url(): void
    {
        $html = $this->renderHtml($this->reminderContext());
        $xpath = $this->xpath($html);
        $cells = $xpath->query('//td[@bgcolor]');

        $this->assertSame(1, $cells->length);
        $cell = $cells->item(0);
        $button = $xpath->query('ancestor::table[1]', $cell)->item(0);
        $cta = $xpath->query('a', $cell)->item(0);
        $this->assertSame('#13155c', $cell->getAttribute('bgcolor'));
        $this->assertStringContainsString('border-radius:999px;background-color:#13155c;', $cell->getAttribute('style'));
        $this->assertSame('100%', $button->getAttribute('width'));
        $this->assertStringContainsString('width:100%;border-collapse:separate;', $button->getAttribute('style'));
        $this->assertSame(self::EVENT_URL, $cta->getAttribute('href'));
        $this->assertSame('View event details', trim($cta->textContent));
        $this->assertStringContainsString('display:block;width:100%;box-sizing:border-box;padding:15px 12px;color:#ffffff;', $cta->getAttribute('style'));
        $this->assertStringNotContainsString('display:inline-block', $html);

        $eventLinks = [];
        foreach ($xpath->query('//a[@href="'.self::EVENT_URL.'"]') as $link) {
            $eventLinks[] = trim($link->textContent);
        }
        $fallback = $xpath->query('//a[normalize-space(.)="'.self::EVENT_URL.'"]')->item(0);
        $this->assertSame(['View event details', self::EVENT_URL], $eventLinks);
        $this->assertStringContainsString('word-break:break-all;overflow-wrap:anywhere;', $fallback->getAttribute('style'));
        $this->assertStringContainsString('word-break:break-all;overflow-wrap:anywhere;', $fallback->parentNode->getAttribute('style'));
    }

    public function test_preheader_is_hidden_in_html_and_not_duplicated_in_text(): void
    {
        $context = $this->reminderContext();
        $html = $this->renderHtml($context);
        $xpath = $this->xpath($html);
        $preheader = $xpath->query('//body/*[1]')->item(0);

        $this->assertSame('div', $preheader->nodeName);
        $this->assertStringContainsString('display:none;', $preheader->getAttribute('style'));
        $this->assertStringContainsString('mso-hide:all;', $preheader->getAttribute('style'));
        $this->assertSame(1, substr_count($html, 'mso-hide:all;">'.e($context->preheader).'</div>'));
        $this->assertStringNotContainsString($context->preheader, $this->renderText($context));
    }

    public function test_support_address_appears_once_as_a_mailto_link_without_a_reply_claim(): void
    {
        $context = $this->reminderContext();
        $html = $this->renderHtml($context);
        $text = $this->renderText($context);
        $visible = $this->visibleText($html);
        $xpath = $this->xpath($html);
        $mailto = $xpath->query('//a[starts-with(@href, "mailto:")]');

        $this->assertSame(1, substr_count($visible, self::SUPPORT_EMAIL));
        $this->assertStringContainsString(self::SUPPORT_LINE, $visible);
        $this->assertSame(1, $mailto->length);
        $this->assertSame('mailto:'.self::SUPPORT_EMAIL, $mailto->item(0)->getAttribute('href'));
        $this->assertSame(self::SUPPORT_EMAIL, trim($mailto->item(0)->textContent));
        $this->assertSame(1, substr_count($text, self::SUPPORT_EMAIL));
        $this->assertStringContainsString(self::SUPPORT_LINE, $text);
        $this->assertStringNotContainsStringIgnoringCase('reply', $visible);
        $this->assertStringNotContainsStringIgnoringCase('reply', $text);
    }

    public function test_location_renders_when_present_and_is_absent_when_null(): void
    {
        $present = $this->reminderContext();
        $absent = $this->reminderContext(location: null);
        $presentHtml = $this->renderHtml($present);
        $absentHtml = $this->renderHtml($absent);
        $absentText = $this->renderText($absent);

        $this->assertStringContainsString('America/Detroit Kirkhof Center View event details', $this->visibleText($presentHtml));
        $this->assertStringContainsString("America/Detroit\nKirkhof Center\n\nView event details:\n", $this->renderText($present));
        $this->assertSame(3, substr_count($presentHtml, '<br>'));

        $this->assertStringContainsString('America/Detroit View event details', $this->visibleText($absentHtml));
        $this->assertStringContainsString("America/Detroit\n\nView event details:\n", $absentText);
        $this->assertStringNotContainsString('Kirkhof Center', $absentHtml);
        $this->assertStringNotContainsString('Kirkhof Center', $absentText);
        $this->assertSame(2, substr_count($absentHtml, '<br>'));
    }

    public function test_physical_address_and_preference_link_render_only_when_non_empty(): void
    {
        $preferenceHref = 'href="'.self::PREFERENCE_URL.'"';
        $preferenceLine = 'Email preferences: '.self::PREFERENCE_URL;

        $both = $this->reminderContext();
        $html = $this->renderHtml($both);
        $this->assertStringEndsWith(self::SUPPORT_LINE.' '.self::ADDRESS.' Email preferences', $this->visibleText($html));
        $this->assertStringEndsWith(self::SUPPORT_LINE."\n\n".self::ADDRESS."\n".$preferenceLine, rtrim($this->renderText($both)));
        $this->assertStringContainsString('border-top:1px solid #d8dbe2;', $html);
        $this->assertStringContainsString($preferenceHref, $html);
        $this->assertSame(5, substr_count($html, '<p '));

        $addressOnly = $this->reminderContext(preferenceUrl: '');
        $html = $this->renderHtml($addressOnly);
        $this->assertStringEndsWith(self::SUPPORT_LINE.' '.self::ADDRESS, $this->visibleText($html));
        $this->assertStringEndsWith(self::SUPPORT_LINE."\n\n".self::ADDRESS, rtrim($this->renderText($addressOnly)));
        $this->assertStringContainsString('border-top:1px solid #d8dbe2;', $html);
        $this->assertStringNotContainsString($preferenceHref, $html);
        $this->assertSame(4, substr_count($html, '<p '));

        $preferenceOnly = $this->reminderContext(physicalAddress: '');
        $html = $this->renderHtml($preferenceOnly);
        $this->assertStringEndsWith(self::SUPPORT_LINE.' Email preferences', $this->visibleText($html));
        $this->assertStringEndsWith(self::SUPPORT_LINE."\n\n".$preferenceLine, rtrim($this->renderText($preferenceOnly)));
        $this->assertStringContainsString('border-top:1px solid #d8dbe2;', $html);
        $this->assertStringContainsString($preferenceHref, $html);
        $this->assertSame(4, substr_count($html, '<p '));

        $neither = $this->reminderContext(physicalAddress: ' ', preferenceUrl: '');
        $html = $this->renderHtml($neither);
        $this->assertStringEndsWith(self::SUPPORT_LINE, $this->visibleText($html));
        $this->assertStringEndsWith(self::SUPPORT_LINE, rtrim($this->renderText($neither)));
        $this->assertStringNotContainsString('border-top:1px solid #d8dbe2;', $html);
        $this->assertStringNotContainsString('Email preferences', $html);
        $this->assertSame(3, substr_count($html, '<p '));
    }

    public function test_title_with_ampersand_and_apostrophe_is_escaped_in_html_and_literal_in_text(): void
    {
        $title = "St. Mary's & Grand Valley Kamp";
        $context = $this->reminderContext(title: $title);
        $html = $this->renderHtml($context);
        $text = $this->renderText($context);

        $this->assertStringContainsString('<title>St. Mary&#039;s &amp; Grand Valley Kamp</title>', $html);
        $this->assertStringContainsString('You&#039;re registered for St. Mary&#039;s &amp; Grand Valley Kamp</h1>', $html);
        $this->assertStringContainsString('St. Mary&#039;s &amp; Grand Valley Kamp<br>', $html);
        $this->assertStringNotContainsString($title, $html);
        $this->assertStringContainsString("You're registered for {$title}\n", $text);
        $this->assertStringContainsString("Event Details\n{$title}\n", $text);
        foreach (['&amp;', '&#039;', '&#39;', '&apos;', '&quot;', '&lt;', '&gt;'] as $entity) {
            $this->assertStringNotContainsString($entity, $text);
        }
    }

    public function test_reminder_introduces_no_ticket_language_or_ticket_url(): void
    {
        $context = $this->reminderContext();
        $html = $this->renderHtml($context);
        $text = $this->renderText($context);
        $xpath = $this->xpath($html);
        $links = [];
        foreach ($xpath->query('//a') as $link) {
            $links[] = $link->getAttribute('href');
        }
        preg_match_all('~https?://\S+~', $text, $textUrls);
        $known = [self::EVENT_URL, self::PREFERENCE_URL, self::SUPPORT_EMAIL];

        $this->assertSame([self::EVENT_URL, self::EVENT_URL, 'mailto:'.self::SUPPORT_EMAIL, self::PREFERENCE_URL], $links);
        $this->assertSame([self::EVENT_URL, self::PREFERENCE_URL], $textUrls[0]);
        $this->assertStringNotContainsStringIgnoringCase('ticket', str_replace($known, '', $this->visibleText($html)));
        $this->assertStringNotContainsStringIgnoringCase('ticket', str_replace($known, '', $text));
    }

    private function reminderContext(
        string $title = 'Grand Valley Kamp Night',
        ?string $location = 'Kirkhof Center',
        string $physicalAddress = self::ADDRESS,
        string $preferenceUrl = self::PREFERENCE_URL,
        ?UniversityEmailThemeDTO $theme = null,
    ): EventReminderContext {
        return new EventReminderContext(
            $title,
            self::EVENT_URL,
            'October 8, 2026 7:00 PM',
            'America/Detroit',
            $location,
            self::SUPPORT_EMAIL,
            'tickets@kamplove.org',
            self::SUPPORT_EMAIL,
            $physicalAddress,
            $preferenceUrl,
            $title.' — October 8, 2026 7:00 PM America/Detroit',
            $theme ?? new UniversityEmailThemeDTO('#0032a0', '#13155c', '#ffffff', '#ffffff', '#e7e7ed'),
        );
    }

    private function renderHtml(EventReminderContext $context): string
    {
        return (new EventReminder($context))->render();
    }

    private function renderText(EventReminderContext $context): string
    {
        $content = (new EventReminder($context))->content();

        return view($content->text, $content->with)->render();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function visibleText(string $html): string
    {
        $xpath = $this->xpath($html);
        foreach (iterator_to_array($xpath->query('//*[contains(@style, "display:none")]')) as $hidden) {
            $hidden->parentNode->removeChild($hidden);
        }

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $xpath->query('//body')->item(0)->textContent));
    }

    private function assertFactsInOrder(array $facts, string $haystack): void
    {
        $offset = 0;
        foreach ($facts as $fact) {
            $position = strpos($haystack, $fact, $offset);
            $this->assertNotFalse($position, "Expected [{$fact}] after offset {$offset}.");
            $offset = $position + strlen($fact);
        }
    }
}
