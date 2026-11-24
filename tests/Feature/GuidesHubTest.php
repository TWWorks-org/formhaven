<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentController;
use Tests\TestCase;

class GuidesHubTest extends TestCase
{
    public function test_every_guide_sits_in_exactly_one_hub_section(): void
    {
        $grouped = array_merge(...array_values(ContentController::GUIDE_GROUPS));

        $this->assertSame([], array_diff(array_keys(ContentController::guides()), $grouped), 'A guide is missing from ContentController::GUIDE_GROUPS, so the hub would not list it');
        $this->assertSame([], array_diff($grouped, array_keys(ContentController::guides())), 'GUIDE_GROUPS names a guide that does not exist');
        $this->assertSame(count($grouped), count(array_unique($grouped)), 'A guide is listed in two hub sections');
    }

    public function test_the_hub_links_every_guide_and_is_linked_from_every_page(): void
    {
        $html = $this->get('/guides')->assertOk()->getContent();

        foreach (array_keys(ContentController::guides()) as $slug) {
            $this->assertStringContainsString('href="'.route('guides.show', $slug).'"', $html, "The hub does not link {$slug}");
        }

        foreach (['/', '/pricing', '/guides/astro-contact-form'] as $path) {
            $this->get($path)->assertSee('href="'.route('guides.index').'"', false);
        }

        $this->get('/llms.txt')->assertSee(route('guides.index'), false);
    }
}
