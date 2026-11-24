<?php

namespace App\Http\Controllers;

/** The programmatic page groups: one template each, content from resources/content. */
class ContentController extends Controller
{
    public static function templates(): array
    {
        return require resource_path('content/templates.php');
    }

    public static function alternatives(): array
    {
        return require resource_path('content/alternatives.php');
    }

    public static function guides(): array
    {
        return require resource_path('content/guides.php');
    }

    /** The hub's sections: every guide sits in exactly one (GuidesHubTest). */
    public const GUIDE_GROUPS = [
        'Static sites and hosts' => ['static-site-contact-form', 'html-form-to-email', 'javascript-contact-form', 'github-pages-contact-form', 'cloudflare-pages-contact-form', 'netlify-contact-form', 'vercel-contact-form'],
        'Frameworks' => ['astro-contact-form', 'hugo-contact-form', 'eleventy-contact-form', 'react-contact-form', 'nextjs-contact-form', 'sveltekit-contact-form', 'nuxt-contact-form', 'gatsby-contact-form'],
        'AI coding tools and agents' => ['form-backend-for-ai-agents', 'claude-code-contact-form', 'cursor-contact-form', 'lovable-contact-form'],
        'Privacy, clients and webhooks' => ['gdpr-compliant-contact-form', 'kontaktformular-dsgvo', 'contact-form-privacy-policy', 'send-form-submissions-to-multiple-emails', 'html-form-webhook'],
        'Switching from another service' => ['formspree-not-working', 'export-from-formspree', 'export-from-formspark', 'formsubmit-not-working', 'web3forms-not-working', 'emailjs-not-working'],
    ];

    public function guideIndex()
    {
        $guides = self::guides();

        return view('content.guide-index', [
            'groups' => array_map(fn (array $slugs) => array_intersect_key($guides, array_flip($slugs)), self::GUIDE_GROUPS),
        ]);
    }

    public function guide(string $slug)
    {
        $guides = self::guides();
        abort_unless(isset($guides[$slug]), 404);

        return view('content.guide', [
            'slug' => $slug,
            'page' => $guides[$slug],
            'others' => array_diff_key($guides, [$slug => true]),
        ]);
    }

    public function templateIndex()
    {
        return view('content.template-index', ['templates' => self::templates()]);
    }

    public function template(string $slug)
    {
        $templates = self::templates();
        abort_unless(isset($templates[$slug]), 404);

        return view('content.template', [
            'slug' => $slug,
            'page' => $templates[$slug],
            'others' => array_diff_key($templates, [$slug => true]),
        ]);
    }

    public function alternative(string $slug)
    {
        $alternatives = self::alternatives();
        abort_unless(isset($alternatives[$slug]), 404);

        return view('content.alternative', ['slug' => $slug, 'page' => $alternatives[$slug]]);
    }
}
