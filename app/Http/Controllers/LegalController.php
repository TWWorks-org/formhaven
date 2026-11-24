<?php

namespace App\Http\Controllers;

use App\Support\Bill;
use Illuminate\Support\Str;
use League\CommonMark\Extension\Table\TableExtension;

class LegalController extends Controller
{
    public const PAGES = [
        'privacy' => 'Privacy policy',
        'terms' => 'Terms of service',
        'dpa' => 'Data processing agreement',
        'subprocessors' => 'Subprocessors',
    ];

    public function __invoke(string $page)
    {
        $markdown = strtr(file_get_contents(resource_path("legal/{$page}.md")), [
            ':entity' => config('legal.entity'),
            ', :address' => filled(config('legal.address')) ? ', '.config('legal.address') : '',
            ':kvk' => config('legal.kvk'),
            ':email' => config('legal.email'),
            ':site' => parse_url((string) config('app.url'), PHP_URL_HOST),
            ':updated' => config('legal.updated'),
            ':trial_days' => config('billing.trial_days'),
            ':email_grace' => (int) round(config('billing.email_grace') * 100),
            ':per_form_monthly' => Bill::format(config('billing.per_form.monthly_eur'), ''),
            ':per_form_yearly' => Bill::format(config('billing.per_form.yearly_eur'), ''),
            ':full_price_forms' => config('billing.per_form.full_price_forms'),
            ':lower_monthly' => Bill::format(config('billing.per_form.lower_monthly_eur'), ''),
            ':lower_yearly' => Bill::format(config('billing.per_form.lower_yearly_eur'), ''),
            ':extra_price' => Bill::format(config('billing.per_form.extra_eur'), ''),
            ':min_forms' => config('billing.per_form.min_forms'),
            ':per_form_submissions' => number_format(config('billing.per_form.submissions')),
            ':trial_forms' => config('billing.trial_forms'),
        ]);

        return view('legal', [
            'title' => self::PAGES[$page].': FormHaven',
            'html' => Str::markdown($markdown, ['html_input' => 'escape'], [new TableExtension]),
        ]);
    }
}
