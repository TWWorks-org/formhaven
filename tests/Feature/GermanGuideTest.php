<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The one German page (/guides/kontaktformular-dsgvo) runs on the shared guide template. It must
 * say it is German, pair with the English GDPR guide for hreflang, show no English template words
 * in its own <main>, and take every price from config/billing.php.
 */
class GermanGuideTest extends TestCase
{
    private const DE = '/guides/kontaktformular-dsgvo';

    private const EN = '/guides/gdpr-compliant-contact-form';

    private function mainOf(string $html): string
    {
        preg_match('#<main\b[^>]*>(.*)</main>#s', $html, $m);

        return html_entity_decode(strip_tags($m[1] ?? ''));
    }

    public function test_the_german_page_is_marked_german_and_the_english_guides_stay_english(): void
    {
        $this->get(self::DE)->assertOk()->assertSee('<html lang="de">', false);
        $this->get(self::EN)->assertOk()->assertSee('<html lang="en">', false);
        $this->get('/guides/astro-contact-form')->assertOk()->assertSee('<html lang="en">', false);
    }

    public function test_both_pages_carry_the_same_hreflang_pair(): void
    {
        $pair = [
            '<link rel="alternate" hreflang="de" href="'.url(self::DE).'">',
            '<link rel="alternate" hreflang="en" href="'.url(self::EN).'">',
            '<link rel="alternate" hreflang="x-default" href="'.url(self::EN).'">',
        ];

        foreach ([self::DE, self::EN] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            foreach ($pair as $link) {
                $this->assertStringContainsString($link, $html, "{$path} lacks {$link}");
            }
        }

        // A guide with no counterpart carries no alternates at all.
        $this->assertStringNotContainsString('hreflang=', $this->get('/guides/astro-contact-form')->getContent());
    }

    public function test_no_english_template_words_are_left_on_the_german_page(): void
    {
        $html = $this->get(self::DE)->assertOk()->getContent();
        preg_match('#<main\b[^>]*>(.*)</main>#s', $html, $m);

        foreach (['Get your form address', 'Set it up', 'Questions about this setup', 'More to read', '>Copy<', 'days free, no card', 'per form a month', 'Start your'] as $english) {
            $this->assertStringNotContainsString($english, $m[1], "The German page still shows \"{$english}\"");
        }
        $this->assertStringContainsString('data-copied="Kopiert"', $m[1]);
        $this->assertStringContainsString('href="'.route('register').'"', $m[1], 'The German page lost the trial signup link');
    }

    public function test_the_german_prices_follow_the_config(): void
    {
        $main = $this->mainOf($this->get(self::DE)->assertOk()->getContent());
        $this->assertStringContainsString("1\u{00A0}€ pro Formular im Monat", $main);
        $this->assertStringContainsString("ab dem 11. Formular 0,50\u{00A0}€", $main);
        $this->assertStringContainsString("ab 5\u{00A0}€ im Monat", $main);
        $this->assertStringContainsString(config('billing.trial_days').' Tage kostenlos', $main);

        config(['billing.per_form.monthly_eur' => 3, 'billing.per_form.yearly_eur' => 30, 'billing.per_form.lower_monthly_eur' => 1.5, 'billing.per_form.full_price_forms' => 8, 'billing.per_form.min_forms' => 4, 'billing.trial_days' => 21]);
        $main = $this->mainOf($this->get(self::DE)->assertOk()->getContent());
        // The FAQ answer and the closing band each state it; both follow the config.
        $this->assertStringContainsString("Danach 3\u{00A0}€ pro Formular im Monat oder 30\u{00A0}€ pro Formular und Jahr", $main);
        $this->assertStringContainsString("Danach 3\u{00A0}€ pro Formular im Monat, ab dem 9. Formular", $main);
        $this->assertStringContainsString("ab dem 9. Formular 1,50\u{00A0}€", $main);
        // 4 forms at 3 euros.
        $this->assertStringContainsString("mindestens 4 Formulare, also ab 12\u{00A0}€ im Monat", $main);
        $this->assertStringContainsString('21 Tage kostenlos', $main);
    }
}
