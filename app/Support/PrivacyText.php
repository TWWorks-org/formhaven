<?php

namespace App\Support;

use App\Models\Form;

/**
 * The paragraph a site's privacy policy needs for one form, filled in from that form's real
 * settings (how long FormHaven keeps a copy, or none) and from config/legal.php (who runs it,
 * where messages are stored, who sends the emails, how long backup copies last). The site owner
 * is the controller; this text describes FormHaven as their processor.
 */
class PrivacyText
{
    public const LANGUAGES = ['en', 'nl', 'de'];

    /** @return array{language: string, heading: string, paragraph: string, form_notice_html: string, facts: array<string, mixed>, note: string, generated_on: string} */
    public static function for(Form $form, string $language): array
    {
        if (! in_array($language, self::LANGUAGES, true)) {
            throw new \InvalidArgumentException('language must be one of: '.implode(', ', self::LANGUAGES).'.');
        }

        if (Mode::selfHosted()) {
            return self::selfHosted($form, $language);
        }

        $days = (int) $form->retention_days;
        $vars = [
            ':entity' => config('legal.entity'),
            ':country' => config("legal.country.{$language}"),
            ':provider' => config('legal.hosting.provider'),
            ':place' => config("legal.hosting.place.{$language}"),
            ':mailer' => config('legal.mailer'),
            ':url' => route('legal', 'subprocessors'),
            ':days' => $days,
            ':backup' => (int) config('legal.backup_days'),
        ];

        $text = self::TEXT[$language];
        $retention = $form->store_submissions
            ? $text[$days === 1 ? 'kept_one' : 'kept']
            : $text['none'];

        return [
            'language' => $language,
            'heading' => $text['heading'],
            'paragraph' => strtr($text['paragraph'].' '.$retention.' '.$text['backup'].self::monitorSentence($form, $language).' '.$text['subprocessors'], $vars),
            'form_notice_html' => $text['notice'],
            'facts' => [
                'processor' => 'FormHaven, run by '.config('legal.entity'),
                'processor_country' => config('legal.country.en'),
                'stored_at' => config('legal.hosting.provider').', '.config('legal.hosting.place.en'),
                'emails_sent_by' => config('legal.mailer'),
                'formhaven_keeps_copy_days' => $form->store_submissions ? $days : 0,
                // A form that keeps nothing still holds a message whose email could not be delivered, for this long at most.
                'kept_if_undelivered_days' => $days,
                // Nightly checked copies of the database on the same server (the hosted backup job).
                'backup_copies_kept_days' => (int) config('legal.backup_days'),
                'dpa' => route('legal', 'dpa'),
                'subprocessors' => route('legal', 'subprocessors'),
                'delivery_reports_to' => DidMyFormSend::enabled($form) ? 'DidMyFormSend (didmyformsend.com)' : null,
            ],
            'note' => $text['note'],
            'generated_on' => now()->toDateString(),
        ];
    }

    /**
     * A self-hosted install: it names only what its own .env says (LEGAL_ENTITY, LEGAL_COUNTRY_*,
     * LEGAL_HOSTING_*, LEGAL_MAILER). With none set it says the site owner's own FormHaven install
     * stores the messages, and claims no company, country, transfer basis, backup or legal page.
     */
    private static function selfHosted(Form $form, string $language): array
    {
        $days = (int) $form->retention_days;
        $entity = trim((string) config('legal.entity'));
        $country = trim((string) config("legal.country.{$language}"));
        $provider = trim((string) config('legal.hosting.provider'));
        $place = trim((string) config("legal.hosting.place.{$language}"));
        $mailer = trim((string) config('legal.mailer'));
        $text = self::SELF_HOSTED_TEXT[$language];

        $parts = [$text['intro']];
        $parts[] = $entity !== ''
            ? strtr($text['operator'], [':entity' => $entity, ':in_country' => $country !== '' ? strtr($text['in'], [':x' => $country]) : ''])
            : $text['own_install'];
        if ($provider !== '' || $place !== '') {
            $parts[] = strtr($text['storage'], [
                ':provider' => $provider !== '' ? strtr($text['of'], [':x' => $provider]) : '',
                ':place' => $place !== '' ? strtr($text['in'], [':x' => $place]) : '',
            ]);
        }
        if ($mailer !== '') {
            $parts[] = strtr($text['mailer'], [':mailer' => $mailer]);
        }
        $parts[] = $text['basis'];
        $parts[] = strtr($form->store_submissions ? self::TEXT[$language][$days === 1 ? 'kept_one' : 'kept'] : self::TEXT[$language]['none'], [':days' => $days]);
        if (DidMyFormSend::enabled($form)) {
            $parts[] = trim(self::monitorSentence($form, $language));
        }

        $storedAt = trim($provider.($provider !== '' && $place !== '' ? ', ' : '').$place);

        return [
            'language' => $language,
            'heading' => self::TEXT[$language]['heading'],
            'paragraph' => implode(' ', $parts),
            'form_notice_html' => self::TEXT[$language]['notice'],
            'facts' => [
                'processor' => $entity !== '' ? 'FormHaven, run by '.$entity : 'FormHaven, run by the site owner on their own install',
                'processor_country' => config('legal.country.en') ?: null,
                'stored_at' => $storedAt !== '' ? $storedAt : 'this FormHaven install',
                'emails_sent_by' => $mailer !== '' ? $mailer : null,
                'formhaven_keeps_copy_days' => $form->store_submissions ? $days : 0,
                'kept_if_undelivered_days' => $days,
                // Backups, a DPA and a subprocessor list depend on how the install is run: none is claimed.
                'backup_copies_kept_days' => null,
                'dpa' => null,
                'subprocessors' => null,
                'delivery_reports_to' => DidMyFormSend::enabled($form) ? 'DidMyFormSend (didmyformsend.com)' : null,
            ],
            'note' => self::TEXT[$language]['note'],
            'generated_on' => now()->toDateString(),
        ];
    }

    /**
     * " <sentence>" for a form with a DidMyFormSend key, else "". DidMyFormSend is named as run by
     * the operator only on the hosted service, where the operator config names who runs both; a
     * self-hosted install's operator does not run it, so there it is a separate service.
     */
    private static function monitorSentence(Form $form, string $language): string
    {
        if (! DidMyFormSend::enabled($form)) {
            return '';
        }

        $entity = trim((string) config('legal.entity'));
        $service = Mode::hosted() && $entity !== ''
            ? strtr(self::MONITOR[$language]['by'], [':entity' => $entity])
            : self::MONITOR[$language]['separate'];

        return ' '.strtr(self::MONITOR[$language]['sentence'], [':service' => $service]);
    }

    private const MONITOR = [
        'en' => [
            'sentence' => 'To check that these emails reach us, FormHaven tells DidMyFormSend, :service, after each message whether the emails about it went out, with the time, the name of the mail service and our own notification addresses in shortened and hashed form. Nothing you write in the form is sent to it.',
            'by' => 'a monitoring service also run by :entity',
            'separate' => 'a separate monitoring service',
        ],
        'nl' => [
            'sentence' => 'Om te controleren dat deze e-mails ons bereiken, laat FormHaven na elk bericht aan DidMyFormSend, :service, weten of de e-mails erover zijn verstuurd, met het tijdstip, de naam van de maildienst en onze eigen meldingsadressen in ingekorte en gehashte vorm. Niets wat je in het formulier schrijft wordt daarheen gestuurd.',
            'by' => 'een monitoringdienst die ook door :entity wordt beheerd',
            'separate' => 'een aparte monitoringdienst',
        ],
        'de' => [
            'sentence' => 'Um zu prüfen, ob diese E-Mails uns erreichen, teilt FormHaven nach jeder Nachricht DidMyFormSend, :service, mit, ob die E-Mails dazu versendet wurden, zusammen mit der Uhrzeit, dem Namen des Mail-Dienstes und unseren eigenen Benachrichtigungsadressen in gekürzter und gehashter Form. Nichts, was Sie in das Formular schreiben, wird dorthin übermittelt.',
            'by' => 'einem ebenfalls von :entity betriebenen Überwachungsdienst',
            'separate' => 'einem separaten Überwachungsdienst',
        ],
    ];

    private const SELF_HOSTED_TEXT = [
        'en' => [
            'intro' => 'When you send us a message through the contact form on this website, we receive the details you enter (such as your name, email address and message) so we can answer you.',
            'operator' => 'The form is processed with FormHaven, run by :entity:in_country, which handles the messages on our behalf.',
            'own_install' => 'The form is processed with FormHaven, form software we run ourselves: our own installation stores the messages and sends them to us by email.',
            'storage' => 'The messages are stored on servers:provider:place.',
            'mailer' => 'The emails to us are sent through :mailer.',
            'of' => ' of :x',
            'in' => ' in :x',
            'basis' => 'We process these details to answer your enquiry and, where it concerns a possible order, to take steps before entering into a contract (Article 6(1)(b) GDPR); otherwise on the basis of our legitimate interest in answering messages (Article 6(1)(f) GDPR). We keep your message for as long as we need it to handle your enquiry.',
        ],
        'nl' => [
            'intro' => 'Als je ons een bericht stuurt via het contactformulier op deze website, ontvangen we de gegevens die je invult (zoals je naam, e-mailadres en bericht) zodat we je kunnen antwoorden.',
            'operator' => 'Het formulier wordt verwerkt met FormHaven, beheerd door :entity:in_country, dat de berichten namens ons verwerkt.',
            'own_install' => 'Het formulier wordt verwerkt met FormHaven, formuliersoftware die we zelf draaien: onze eigen installatie bewaart de berichten en stuurt ze ons per e-mail.',
            'storage' => 'De berichten worden bewaard op servers:provider:place.',
            'mailer' => 'De e-mails aan ons worden verstuurd via :mailer.',
            'of' => ' van :x',
            'in' => ' in :x',
            'basis' => 'We verwerken deze gegevens om je vraag te beantwoorden en, als het om een mogelijke opdracht gaat, om een overeenkomst voor te bereiden (artikel 6 lid 1 sub b AVG); in andere gevallen op basis van ons gerechtvaardigd belang om berichten te beantwoorden (artikel 6 lid 1 sub f AVG). We bewaren je bericht zolang we het nodig hebben om je vraag af te handelen.',
        ],
        'de' => [
            'intro' => 'Wenn Sie uns über das Kontaktformular auf dieser Website eine Nachricht senden, erhalten wir die von Ihnen eingegebenen Angaben (z. B. Name, E-Mail-Adresse und Nachricht), um Ihre Anfrage zu beantworten.',
            'operator' => 'Das Formular wird mit FormHaven verarbeitet, betrieben von :entity:in_country, das die Nachrichten in unserem Auftrag verarbeitet.',
            'own_install' => 'Das Formular wird mit FormHaven verarbeitet, einer Formularsoftware, die wir selbst betreiben: Unsere eigene Installation speichert die Nachrichten und sendet sie uns per E-Mail.',
            'storage' => 'Die Nachrichten werden auf Servern:provider:place gespeichert.',
            'mailer' => 'Die E-Mails an uns werden über :mailer versendet.',
            'of' => ' der :x',
            'in' => ' in :x',
            'basis' => 'Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO, soweit Ihre Anfrage auf einen Vertrag abzielt, sonst unser berechtigtes Interesse an der Beantwortung von Anfragen (Art. 6 Abs. 1 lit. f DSGVO). Wir speichern Ihre Nachricht, solange sie für die Bearbeitung nötig ist.',
        ],
    ];

    private const TEXT = [
        'en' => [
            'heading' => 'Contact form',
            'paragraph' => 'When you send us a message through the contact form on this website, we receive the details you enter (such as your name, email address and message) so we can answer you. The form is processed by FormHaven, run by :entity in :country, which acts as our processor under a data processing agreement. FormHaven stores the messages on servers of :provider in :place and sends them to us by email through :mailer, under the EU-US Data Privacy Framework and the European Commission\'s Standard Contractual Clauses. We process these details to answer your enquiry and, where it concerns a possible order, to take steps before entering into a contract (Article 6(1)(b) GDPR); otherwise on the basis of our legitimate interest in answering messages (Article 6(1)(f) GDPR). We keep your message for as long as we need it to handle your enquiry.',
            'kept' => 'FormHaven deletes its copy after :days days.',
            'kept_one' => 'FormHaven deletes its copy after 1 day.',
            'none' => 'FormHaven deletes your answers as soon as the email to us has been sent. If that email cannot be delivered, it keeps them for at most :days days, so they are not lost.',
            'backup' => 'FormHaven\'s nightly backups on the same servers hold a copy for up to :backup days longer.',
            'subprocessors' => 'FormHaven lists the companies it uses at :url.',
            'notice' => 'We use your details only to answer your message. <a href="/privacy">How we handle your data</a>.',
            'note' => 'Not legal advice: check it against the site\'s own privacy policy.',
        ],
        'nl' => [
            'heading' => 'Contactformulier',
            'paragraph' => 'Als je ons een bericht stuurt via het contactformulier op deze website, ontvangen we de gegevens die je invult (zoals je naam, e-mailadres en bericht) zodat we je kunnen antwoorden. Het formulier wordt verwerkt door FormHaven, van :entity in :country, dat als onze verwerker optreedt op basis van een verwerkersovereenkomst. FormHaven bewaart de berichten op servers van :provider in :place en stuurt ze ons per e-mail via :mailer, op basis van het EU-VS Data Privacy Framework en de standaardcontractbepalingen van de Europese Commissie. We verwerken deze gegevens om je vraag te beantwoorden en, als het om een mogelijke opdracht gaat, om een overeenkomst voor te bereiden (artikel 6 lid 1 sub b AVG); in andere gevallen op basis van ons gerechtvaardigd belang om berichten te beantwoorden (artikel 6 lid 1 sub f AVG). We bewaren je bericht zolang we het nodig hebben om je vraag af te handelen.',
            'kept' => 'FormHaven verwijdert zijn kopie na :days dagen.',
            'kept_one' => 'FormHaven verwijdert zijn kopie na 1 dag.',
            'none' => 'FormHaven verwijdert je antwoorden zodra de e-mail aan ons is verstuurd. Komt die e-mail niet aan, dan bewaart FormHaven ze hooguit :days dagen, zodat ze niet verloren gaan.',
            'backup' => 'De nachtelijke back-ups van FormHaven op dezelfde servers bewaren een kopie hooguit :backup dagen langer.',
            'subprocessors' => 'De bedrijven die FormHaven inschakelt staan op :url.',
            'notice' => 'We gebruiken je gegevens alleen om je bericht te beantwoorden. <a href="/privacy">Hoe we met je gegevens omgaan</a>.',
            'note' => 'Geen juridisch advies: leg het naast de eigen privacyverklaring van de site.',
        ],
        'de' => [
            'heading' => 'Kontaktformular',
            'paragraph' => 'Wenn Sie uns über das Kontaktformular auf dieser Website eine Nachricht senden, erhalten wir die von Ihnen eingegebenen Angaben (z. B. Name, E-Mail-Adresse und Nachricht), um Ihre Anfrage zu beantworten. Das Formular wird von FormHaven verarbeitet, betrieben von :entity in :country, als unser Auftragsverarbeiter auf Grundlage eines Vertrags zur Auftragsverarbeitung (Art. 28 DSGVO). FormHaven speichert die Nachrichten auf Servern der :provider in :place und sendet sie uns per E-Mail über :mailer, abgesichert durch das EU-US Data Privacy Framework und die Standardvertragsklauseln der EU-Kommission. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO, soweit Ihre Anfrage auf einen Vertrag abzielt, sonst unser berechtigtes Interesse an der Beantwortung von Anfragen (Art. 6 Abs. 1 lit. f DSGVO). Wir speichern Ihre Nachricht, solange sie für die Bearbeitung nötig ist.',
            'kept' => 'FormHaven löscht seine Kopie nach :days Tagen.',
            'kept_one' => 'FormHaven löscht seine Kopie nach 1 Tag.',
            'none' => 'FormHaven löscht Ihre Angaben, sobald die E-Mail an uns versendet ist. Kann sie nicht zugestellt werden, bewahrt FormHaven sie höchstens :days Tage auf, damit sie nicht verloren gehen.',
            'backup' => 'In den nächtlichen Sicherungen von FormHaven auf denselben Servern bleibt eine Kopie höchstens :backup Tage länger erhalten.',
            'subprocessors' => 'Die von FormHaven eingesetzten Unternehmen sind unter :url aufgeführt.',
            'notice' => 'Wir verwenden Ihre Angaben nur, um Ihre Nachricht zu beantworten. <a href="/datenschutz">Wie wir mit Ihren Daten umgehen</a>.',
            'note' => 'Keine Rechtsberatung: bitte mit der Datenschutzerklärung der Website abgleichen.',
        ],
    ];
}
