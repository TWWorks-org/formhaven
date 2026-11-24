<?php

namespace Tests\Feature;

use App\Actions\CreateForm;
use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\CreateFormTool;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Form::DEFAULT_RETENTION_DAYS is the one source for the default retention: the forms table's
 * column default must equal it, every create path must land on it, and every page that states
 * the default must state it and no other number.
 */
class DefaultRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_column_default_equals_the_constant(): void
    {
        // A raw insert skips the model, so only the migration's column default fills retention_days.
        $id = DB::table('forms')->insertGetId([
            'user_id' => User::factory()->create()->id,
            'public_id' => 'rawinsertcheck01',
            'name' => 'Raw insert',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(Form::DEFAULT_RETENTION_DAYS, (int) DB::table('forms')->where('id', $id)->value('retention_days'),
            'Form::DEFAULT_RETENTION_DAYS differs from the retention_days column default in the forms migration');
    }

    public function test_every_create_path_without_a_retention_lands_on_the_constant(): void
    {
        $default = Form::DEFAULT_RETENTION_DAYS;
        $this->assertSame($default, (new Form)->retention_days, 'a form made in memory');

        $user = User::factory()->create();
        $this->assertSame($default, app(CreateForm::class)->handle($user, ['name' => 'Action'])->retention_days, 'CreateForm');

        $this->actingAs($user);
        Volt::test('forms.index')->set('name', 'Dashboard')->call('createForm')->assertHasNoErrors();
        $this->assertSame($default, $user->forms()->where('name', 'Dashboard')->sole()->retention_days, 'the dashboard');

        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        $this->postJson('/api/v1/forms', ['name' => 'REST'], $headers)->assertCreated()->assertJsonPath('data.retention_days', $default);
        $this->assertSame($default, $user->forms()->where('name', 'REST')->sole()->retention_days, 'REST');

        FormHavenServer::actingAs($user)->tool(CreateFormTool::class, ['name' => 'MCP'])->assertOk();
        $this->assertSame($default, $user->forms()->where('name', 'MCP')->sole()->retention_days, 'MCP');

        $this->app['auth']->forgetGuards();
        $started = $this->postJson('/api/v1/start', ['name' => 'Agent start'])->assertCreated()->json();
        $this->actingAs($user)->post($started['data']['claim_url'])->assertRedirect();
        $this->assertSame($default, $user->forms()->where('name', 'Agent start')->sole()->retention_days, 'the agent start flow');
    }

    public function test_the_pages_that_state_the_default_state_the_constant_and_no_other_number(): void
    {
        $d = Form::DEFAULT_RETENTION_DAYS;

        $home = html_entity_decode(strip_tags($this->get('/')->assertOk()->getContent()));
        foreach ([
            "{$d} by default, anything from 1 to 365 per form.",
            "{$d} days by default, 1 to 365 per form.",
            "Generated for an example form that keeps messages {$d} days.",
        ] as $sentence) {
            $this->assertStringContainsString($sentence, $home);
        }

        $demo = collect($this->postJson('/demo', ['message' => 'Hello'])->assertOk()->json('steps'))->firstWhere('tag', 'kept')['text'];
        $this->assertSame("Deleted automatically after the days the owner sets, {$d} by default", $demo);

        $llms = $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString("each form keeps submissions for 1 to 365 days (default {$d})", $llms);

        // What an agent reads in the MCP create_form schema.
        $key = ['Authorization' => 'Bearer '.User::factory()->create()->createToken('test')->plainTextToken];
        $tools = collect($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $key)->assertOk()->json('result.tools'));
        $mcp = $tools->firstWhere('name', 'create_form')['inputSchema']['properties']['retention_days']['description'];
        $this->assertSame("Days to keep submissions, 1 to 365. Default {$d}.", $mcp);

        // Any other sentence that states a default retention must carry the same number.
        foreach (['home' => $home, 'demo' => $demo, 'llms.txt' => $llms, 'mcp create_form' => $mcp] as $page => $text) {
            preg_match_all('/\b(\d+)(?: days)? by default\b|\(default (\d+)\)|Default (\d+)\./', $text, $m, PREG_SET_ORDER);
            $this->assertNotEmpty($m, "{$page} states no default");
            foreach ($m as $match) {
                $this->assertSame((string) $d, collect(array_slice($match, 1))->first(fn ($g) => $g !== ''), "{$page}: \"{$match[0]}\"");
            }
        }
    }
}
