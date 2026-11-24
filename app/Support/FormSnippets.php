<?php

namespace App\Support;

use App\Models\Form;

/**
 * Copy-paste code for one form, per stack. The dashboard's Setup tab, the MCP tools and the
 * Lovable guide all read from here, so the code a person sees and the code an agent gets match.
 */
class FormSnippets
{
    public const HONEYPOT = '<input type="text" name="_gotcha" style="display:none" tabindex="-1" autocomplete="off">';

    /** @return array<string, array{0: string, 1: string, 2: string}> [tab label, code, one-line hint] per stack */
    public static function for(Form $form, bool $withPrompt = true): array
    {
        $endpoint = $form->endpoint();
        $botCheck = $form->bot_check;
        // With the bot check on, the page needs its script once; it fills in the form's _botcheck field.
        $script = $botCheck ? [Form::botCheckScript()] : [];

        $snippets = [
            'html' => ['HTML', implode("\n", [...$script, ...self::html($endpoint)]), 'Every input needs a name. Keep the hidden _gotcha field: bots fill it in, people never see it, and those submissions go to spam.'.($botCheck ? ' The script line runs the bot check; it goes once on each page with a form.' : '')],
            'js' => ['JavaScript', implode("\n", [
                "const response = await fetch('{$endpoint}', {",
                "  method: 'POST',",
                "  headers: { Accept: 'application/json' },",
                '  body: new FormData(form),',
                '});',
                'const { ok, message } = await response.json();',
            ]), 'The visitor stays on the page. Show your own thank-you message when ok is true, and message when it is false.'.($botCheck ? ' new FormData(form) carries the bot check\'s _botcheck field; add the script line to the page too.' : '')],
            'react' => ['React', implode("\n", self::react($endpoint)), 'Sends with fetch and shows the answer under the button; without JavaScript it still posts the normal way.'.($botCheck ? ' Add the bot-check script line once to the page\'s head: it finds forms React draws after load.' : '')],
            'astro' => ['Astro', implode("\n", [
                '---',
                '// src/components/Contact.astro',
                '---',
                ...$script,
                '<form action="'.$endpoint.'" method="POST">',
                '  <input type="email" name="email" required />',
                '  <textarea name="message" required></textarea>',
                '  '.str_replace('>', ' />', self::HONEYPOT),
                '  <button type="submit">Send</button>',
                '</form>',
            ]), 'The same markup works in Hugo, Eleventy, Jekyll and any static site generator.'],
        ];

        if ($withPrompt) {
            $snippets['ai'] = ['Prompt for your AI', implode("\n", [
                'Make the contact form on this site work. Set its action to',
                $endpoint.' with method POST, give every input a',
                'name attribute, and add a hidden input named _gotcha that',
                'people cannot see.',
                ...($botCheck ? ['Add this line once to every page that has the form:', Form::botCheckScript()] : []),
                'Then tell me to press "Send a test submission" in FormHaven.',
            ]), 'Paste this into Claude, Cursor, Lovable or Bolt. Your real form address is already in it.'];
        }

        return $snippets;
    }

    /** @return list<string> */
    public static function html(string $endpoint): array
    {
        return [
            '<form action="'.$endpoint.'" method="POST">',
            '  <input type="email" name="email" required>',
            '  <textarea name="message" required></textarea>',
            '  '.self::HONEYPOT,
            '  <button type="submit">Send</button>',
            '</form>',
        ];
    }

    /** @return list<string> */
    public static function react(string $endpoint): array
    {
        return [
            'import { useState, type FormEvent } from "react";',
            '',
            'export function ContactForm() {',
            '  const [status, setStatus] = useState("");',
            '',
            '  async function send(event: FormEvent<HTMLFormElement>) {',
            '    event.preventDefault();',
            '    const form = event.currentTarget;',
            '    const response = await fetch(form.action, {',
            '      method: "POST",',
            '      headers: { Accept: "application/json" },',
            '      body: new FormData(form),',
            '    });',
            '    const { ok, message } = await response.json();',
            '    setStatus(ok ? "Thanks, your message is on its way." : message);',
            '    if (ok) form.reset();',
            '  }',
            '',
            '  return (',
            '    <form action="'.$endpoint.'" method="POST" onSubmit={send}>',
            '      <input type="email" name="email" required />',
            '      <textarea name="message" required />',
            '      <input type="text" name="_gotcha" style={{ display: "none" }} tabIndex={-1} autoComplete="off" />',
            '      <button type="submit">Send</button>',
            '      <p role="status">{status}</p>',
            '    </form>',
            '  );',
            '}',
        ];
    }
}
