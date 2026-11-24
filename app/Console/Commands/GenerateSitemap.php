<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Generate sitemap.xml at public/sitemap.xml';

    /**
     * Static marketing pages. Add every public page that is not generated
     * from a model; remove paths you do not ship.
     *
     * @var array<int, string>
     */
    private array $staticPaths = [
        '/',
        '/pricing',
        '/legal/privacy',
        '/legal/terms',
        '/legal/dpa',
        '/legal/subprocessors',
    ];

    public function handle(): int
    {
        // A self-hosted install has no public pages (its robots.txt disallows everything), so it gets
        // a valid, empty sitemap: the deploy script runs this command on every deploy and must not fail.
        if (\App\Support\Mode::selfHosted()) {
            Sitemap::create()->writeToFile(public_path('sitemap.xml'));
            $this->info('Self-hosted install: wrote an empty sitemap to '.public_path('sitemap.xml'));

            return self::SUCCESS;
        }

        $sitemap = Sitemap::create();

        foreach ($this->staticPaths as $path) {
            $sitemap->add(
                Url::create(url($path))
                    ->setLastModificationDate(now())
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                    ->setPriority($path === '/' ? 1.0 : 0.8)
            );
        }

        $content = [route('templates.index')];
        foreach (array_keys(\App\Http\Controllers\ContentController::templates()) as $slug) {
            $content[] = route('templates.show', $slug);
        }
        foreach (array_keys(\App\Http\Controllers\ContentController::alternatives()) as $slug) {
            $content[] = route('alternatives.show', $slug);
        }
        $content[] = route('guides.index');
        foreach (array_keys(\App\Http\Controllers\ContentController::guides()) as $slug) {
            $content[] = route('guides.show', $slug);
        }
        foreach ($content as $url) {
            $sitemap->add(Url::create($url)->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)->setPriority(0.7));
        }

        // Content models: uncomment + adapt once you have them.
        //
        // \App\Models\BlogPost::published()->get()->each(function ($post) use ($sitemap) {
        //     $sitemap->add(
        //         Url::create(route('blog.show', $post))
        //             ->setLastModificationDate($post->updated_at)
        //             ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
        //             ->setPriority(0.6)
        //     );
        // });

        $sitemap->writeToFile(public_path('sitemap.xml'));

        $this->info('Sitemap generated: '.public_path('sitemap.xml'));

        return self::SUCCESS;
    }
}
