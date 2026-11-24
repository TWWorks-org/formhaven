<?php

namespace Tests\Feature;

use App\Jobs\SyncFormQuantity;
use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\DeleteFormTool;
use App\Models\Form;
use App\Models\FormClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Billing is per form, so a customer must be able to delete one: from the dashboard, REST and MCP,
 * through the same action, with the form's name repeated so nobody wipes the wrong client's messages.
 */
class DeleteFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SyncFormQuantity::class]);
    }

    private function formWithEverything(User $user, string $name = 'Bakery contact'): Form
    {
        $form = Form::factory()->for($user)->create(['name' => $name]);
        $form->submissions()->create(['data' => ['message' => 'Hello']]);
        $form->submissions()->create(['data' => ['message' => 'Second']]);
        $form->recipients()->create(['email' => 'client@example.net', 'token' => 'tok_'.$form->id]);

        return $form;
    }

    public function test_the_dashboard_deletes_a_form_after_its_name_is_typed(): void
    {
        $user = User::factory()->create();
        $form = $this->formWithEverything($user);
        $other = Form::factory()->for($user)->create(['name' => 'Keep me']);
        $this->actingAs($user);

        Volt::test('forms.show', ['form' => $form])
            ->set('deleteConfirm', 'bakery contact')
            ->call('deleteForm')
            ->assertHasErrors('deleteConfirm');
        $this->assertModelExists($form);

        Volt::test('forms.show', ['form' => $form])
            ->set('deleteConfirm', 'Bakery contact')
            ->call('deleteForm')
            ->assertRedirect(route('forms.index'));

        $this->assertModelMissing($form);
        $this->assertDatabaseMissing('submissions', ['form_id' => $form->id]);
        $this->assertDatabaseMissing('form_recipients', ['form_id' => $form->id]);
        $this->assertModelExists($other);
        Bus::assertDispatched(SyncFormQuantity::class, fn ($job) => $job->userId === $user->id);

        $this->get(route('forms.index'))->assertSee('Deleted Bakery contact and its 2 stored submissions.');
        $this->postJson("/f/{$form->public_id}", ['message' => 'Hi'])->assertNotFound();
    }

    public function test_rest_deletes_with_the_name_and_refuses_without_it(): void
    {
        $user = User::factory()->create();
        $form = $this->formWithEverything($user);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        $this->deleteJson("/api/v1/forms/{$form->public_id}", [], $headers)->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->deleteJson("/api/v1/forms/{$form->public_id}", ['confirm' => 'Wrong'], $headers)
            ->assertStatus(422)
            ->assertJsonFragment(['Type the form\'s name exactly ("Bakery contact") to delete it.']);
        $this->assertModelExists($form);

        $this->deleteJson("/api/v1/forms/{$form->public_id}", ['confirm' => 'Bakery contact'], $headers)
            ->assertOk()
            ->assertJsonPath('next', 'Deleted Bakery contact and its 2 stored submissions. Its address no longer accepts messages.');
        $this->assertModelMissing($form);
    }

    public function test_nobody_deletes_another_accounts_form(): void
    {
        $form = $this->formWithEverything(User::factory()->create());
        $stranger = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$stranger->createToken('test')->plainTextToken];

        $this->deleteJson("/api/v1/forms/{$form->public_id}", ['confirm' => 'Bakery contact'], $headers)->assertNotFound();
        FormHavenServer::actingAs($stranger)->tool(DeleteFormTool::class, ['form_id' => $form->public_id, 'confirm' => 'Bakery contact'])
            ->assertHasErrors(['No form with this id on the account. Call list_forms to see the ids.']);

        $this->actingAs($stranger)->get(route('forms.show', $form))->assertNotFound();
        $this->assertModelExists($form);
    }

    public function test_mcp_deletes_with_the_name(): void
    {
        $user = User::factory()->create();
        $form = $this->formWithEverything($user);

        FormHavenServer::actingAs($user)->tool(DeleteFormTool::class, ['form_id' => $form->public_id, 'confirm' => 'Bakery'])
            ->assertHasErrors(['Type the form\'s name exactly ("Bakery contact") to delete it.']);
        $this->assertModelExists($form);

        FormHavenServer::actingAs($user)->tool(DeleteFormTool::class, ['form_id' => $form->public_id, 'confirm' => 'Bakery contact'])
            ->assertOk()
            ->assertSee('Deleted Bakery contact and its 2 stored submissions.');
        $this->assertModelMissing($form);
    }

    public function test_a_setup_key_cannot_delete_even_its_own_form_and_a_deleted_form_frees_its_reservation(): void
    {
        $customer = User::factory()->create();
        $started = $this->postJson('/api/v1/start', ['name' => 'Contact form on example.nl'])->assertCreated()->json();
        $this->actingAs($customer)->post($started['data']['claim_url'])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $key = ['Authorization' => 'Bearer '.$started['data']['setup_key']];

        $this->deleteJson("/api/v1/forms/{$started['data']['id']}", ['confirm' => 'Contact form on example.nl'], $key)
            ->assertForbidden()
            ->assertJsonPath('message', 'This key belongs to one form and cannot delete it. Use an API key from the dashboard.');

        $form = Form::where('public_id', $started['data']['id'])->sole();
        app(\App\Actions\DeleteForm::class)->handle($form, 'Contact form on example.nl');
        $this->assertSame(0, FormClaim::where('public_id', $started['data']['id'])->count());
    }
}
