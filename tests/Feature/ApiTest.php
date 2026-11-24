<?php

namespace Tests\Feature;

use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\CreateFormTool;
use App\Mcp\Tools\ListFormsTool;
use App\Mcp\Tools\ListSubmissionsTool;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private function key(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_an_agent_creates_a_form_and_reads_its_submissions_over_rest(): void
    {
        $user = User::factory()->create();
        $headers = $this->key($user);

        $id = $this->postJson('/api/v1/forms', ['name' => 'Contact form on example.nl'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Contact form on example.nl')
            ->assertJsonPath('data.retention_days', Form::DEFAULT_RETENTION_DAYS)
            ->json('data.id');

        $form = Form::where('public_id', $id)->sole();
        $form->submissions()->create(['data' => ['message' => 'Hello']]);
        $form->submissions()->create(['data' => ['message' => 'Spam'], 'spam' => true]);

        $this->getJson('/api/v1/forms', $headers)->assertOk()->assertJsonPath('data.0.submissions', 1);
        $this->getJson("/api/v1/forms/{$id}/submissions", $headers)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.fields.message', 'Hello');
        $this->getJson("/api/v1/forms/{$id}/submissions?spam=1", $headers)->assertJsonPath('data.0.fields.message', 'Spam');
    }

    public function test_without_a_key_the_api_answers_json_401(): void
    {
        $this->get('/api/v1/forms')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_an_unknown_api_path_answers_json_404_with_the_endpoint_list(): void
    {
        $this->get('/api/v2/nothing')->assertNotFound()->assertJsonStructure(['message', 'endpoints', 'docs']);
    }

    public function test_one_account_cannot_read_another_accounts_form(): void
    {
        $theirs = Form::factory()->create();
        $headers = $this->key(User::factory()->create());

        $this->getJson("/api/v1/forms/{$theirs->public_id}/submissions", $headers)->assertNotFound();
    }

    public function test_the_api_enforces_validation_and_plan_limits_like_the_dashboard(): void
    {
        $expired = User::factory()->create(['trial_ends_at' => now()->subDay()]);
        $headers = $this->key($expired);

        $this->postJson('/api/v1/forms', [], $headers)->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/forms', ['name' => 'X'], $headers)
            ->assertStatus(422)
            ->assertJsonFragment(['Your trial has ended. Subscribe on the Billing page to create forms.']);
    }

    public function test_mcp_tools_create_list_and_read(): void
    {
        $user = User::factory()->create();

        FormHavenServer::actingAs($user)->tool(CreateFormTool::class, ['name' => 'Shop contact'])
            ->assertOk()->assertSee('Shop contact')->assertSee('name=\"_gotcha\"');

        $form = $user->forms()->sole();
        $form->submissions()->create(['data' => ['message' => 'Do you ship to Belgium?']]);

        FormHavenServer::actingAs($user)->tool(ListFormsTool::class)->assertOk()->assertSee($form->public_id);
        FormHavenServer::actingAs($user)->tool(ListSubmissionsTool::class, ['form_id' => $form->public_id])
            ->assertOk()->assertSee('Do you ship to Belgium?');
        FormHavenServer::actingAs($user)->tool(ListSubmissionsTool::class, ['form_id' => 'nope'])
            ->assertHasErrors(['No form with this id on the account. Call list_forms to see the ids.']);
    }

    public function test_mcp_create_form_reports_the_plan_limit_as_an_error(): void
    {
        $expired = User::factory()->create(['trial_ends_at' => now()->subDay()]);

        FormHavenServer::actingAs($expired)->tool(CreateFormTool::class, ['name' => 'X'])
            ->assertHasErrors(['Your trial has ended. Subscribe on the Billing page to create forms.']);
    }

    public function test_the_mcp_endpoint_requires_a_key(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_the_api_root_answers_json_with_the_endpoints(): void
    {
        foreach (['/api', '/api/'] as $path) {
            $this->get($path)->assertOk()->assertJsonPath('docs', url('/llms.txt'))
                ->assertJsonFragment(['GET /api/v1/forms']);
        }
        $this->get('/api/nope')->assertNotFound()->assertJsonPath('message', 'No API endpoint at this path.');
    }

    public function test_opening_the_mcp_url_explains_how_to_connect_in_json(): void
    {
        $this->get('/mcp')->assertStatus(405)->assertHeader('Allow', 'POST')
            ->assertJsonPath('connect.url', url('/mcp'))
            ->assertJsonPath('connect.header', 'Authorization: Bearer YOUR_API_KEY');
    }

    public function test_a_user_creates_and_revokes_a_key_in_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Volt::test('api-keys')->set('name', 'Claude')->call('createKey');
        $this->assertNotNull($component->get('newKey'));
        $this->assertSame(1, $user->tokens()->count());

        $component->call('revoke', $user->tokens()->sole()->id);
        $this->assertSame(0, $user->tokens()->count());
    }
}
