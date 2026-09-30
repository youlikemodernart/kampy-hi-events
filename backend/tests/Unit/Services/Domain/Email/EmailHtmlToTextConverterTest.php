<?php

namespace Tests\Unit\Services\Domain\Email;

use HiEvents\Services\Domain\Email\EmailHtmlToTextConverter;
use PHPUnit\Framework\TestCase;

class EmailHtmlToTextConverterTest extends TestCase
{
    public function test_it_preserves_blocks_breaks_links_and_entities_as_readable_text(): void
    {
        $html = '<h2>Details &amp; help</h2><p>Line one<br>Line two</p>'
            .'<ul><li><a href="https://tickets.example.test/help?a=1&amp;b=2">Help</a></li>'
            .'<li><a href="https://tickets.example.test/ticket">https://tickets.example.test/ticket</a></li></ul>';

        $this->assertSame(
            "Details & help\n\nLine one\nLine two\n\nHelp (https://tickets.example.test/help?a=1&b=2)\n\nhttps://tickets.example.test/ticket",
            EmailHtmlToTextConverter::convert($html),
        );
    }

    public function test_it_returns_empty_text_for_empty_html(): void
    {
        $this->assertSame('', EmailHtmlToTextConverter::convert(''));
    }
}
