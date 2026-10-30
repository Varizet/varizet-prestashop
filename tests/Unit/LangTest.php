<?php

declare(strict_types=1);

namespace Varizet\PrestaShop\Tests\Unit;

/** Every key the module and the gateway use exists in both dictionaries. */
final class LangTest extends TestCase
{
    public function testDictionariesAreCompleteAndAligned(): void
    {
        $fa = require __DIR__ . '/../../varizet/lang/fa.php';
        $en = require __DIR__ . '/../../varizet/lang/en.php';
        self::assertSame(array_keys($fa), array_keys($en));

        $sources = (string) file_get_contents(__DIR__ . '/../../varizet/varizet.php');
        foreach (glob(__DIR__ . '/../../varizet/{src,controllers/front}/*.php', GLOB_BRACE) ?: [] as $file) {
            $sources .= file_get_contents($file);
        }
        preg_match_all("/(?:->t|->text)\\('([a-z_]+)'\\)/", $sources, $m);
        preg_match_all("/'((?:note|error|return|entry|text|help|state|button)_[a-z_]+)'/", $sources, $m2);
        $used = array_diff(array_unique(array_merge($m[1], $m2[1], ['note_needs_review_after_cancel', 'note_underpaid_after_cancel'])), ['return_url']);
        foreach ($used as $key) {
            self::assertArrayHasKey($key, $fa, $key);
        }
        self::assertGreaterThan(20, count($used));
        self::assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $fa['payment_option']);
    }
}
