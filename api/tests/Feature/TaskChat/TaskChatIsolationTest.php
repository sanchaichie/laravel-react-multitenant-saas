<?php

use App\Enums\ChatMessageRole;
use App\Enums\DefaultRole;
use App\Enums\TaskSummaryStatus;
use App\Models\AiUsage;
use App\Models\Task;
use App\Models\TaskChatMessage;
use App\Support\CurrentOrganization;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->ownerA, $this->orgA] = userInOrganization(DefaultRole::Owner, 'Org A');
    [$this->ownerB, $this->orgB] = userInOrganization(DefaultRole::Owner, 'Org B');

    $this->taskA = inOrganization($this->orgA, fn () => Task::factory()->create(['title' => 'A plan', 'description' => 'A only']));
    $this->taskB = inOrganization($this->orgB, fn () => Task::factory()->create(['title' => 'B secret', 'description' => 'B confidential roadmap']));
    createSummary($this->orgB, $this->taskB, TaskSummaryStatus::Approved, ['summary' => 'B approved summary']);

    createChatMessage($this->orgB, $this->taskB, $this->ownerB, ChatMessageRole::User, 'B private question');
    createChatMessage($this->orgB, $this->taskB, $this->ownerB, ChatMessageRole::Assistant, 'B private answer');

    // Org A's owner is the attacker in every test.
    Sanctum::actingAs($this->ownerA);
});

it('cannot read, ask about or clear the chat of another organization\'s task', function () {
    $this->getJson("/api/tasks/{$this->taskB->id}/chat")->assertNotFound();
    $this->postJson("/api/tasks/{$this->taskB->id}/chat", ['message' => 'Hi'])->assertNotFound();
    $this->deleteJson("/api/tasks/{$this->taskB->id}/chat")->assertNotFound();

    $this->ai->assertNothingSent();
    expect(TaskChatMessage::withoutGlobalScopes()->where('organization_id', $this->orgB->id)->count())->toBe(2)
        ->and(AiUsage::withoutGlobalScopes()->count())->toBe(0);
});

it('never puts another organization\'s data in the prompt', function () {
    $this->postJson("/api/tasks/{$this->taskA->id}/chat", ['message' => 'Tell me about B secret'])->assertOk()->streamedContent();

    $request = collect($this->ai->sent())->sole();
    expect($request->system)->toContain('A only')
        ->and(json_encode([$request->system, $request->messages]))
        ->not->toContain('B confidential')
        ->not->toContain('B approved summary')
        ->not->toContain('B private');
});

it('saves its messages and usage in its own organization', function () {
    $this->postJson("/api/tasks/{$this->taskA->id}/chat", ['message' => 'Hi'])->assertOk()->streamedContent();

    expect(TaskChatMessage::withoutGlobalScopes()->where('task_id', $this->taskA->id)->pluck('organization_id')->unique()->all())->toBe([$this->orgA->id])
        ->and(AiUsage::withoutGlobalScopes()->sole()->organization_id)->toBe($this->orgA->id);
});

it('does not clear another organization\'s messages when clearing its own', function () {
    createChatMessage($this->orgA, $this->taskA, $this->ownerA, ChatMessageRole::User, 'A question');

    $this->deleteJson("/api/tasks/{$this->taskA->id}/chat")->assertNoContent();

    expect(TaskChatMessage::withoutGlobalScopes()->pluck('content')->all())->toBe(['B private question', 'B private answer']);
});

it('saves the answer in the organization that authorized it, even if the user switches while it streams', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => $this->orgB->role(DefaultRole::Owner)->id]);

    $response = $this->postJson("/api/tasks/{$this->taskA->id}/chat", ['message' => 'Hi'])->assertOk();

    $this->ownerA->forceFill(['current_organization_id' => $this->orgB->id])->save();
    app(CurrentOrganization::class)->forget();
    $response->streamedContent();

    expect(TaskChatMessage::withoutGlobalScopes()->where('task_id', $this->taskA->id)->pluck('organization_id')->all())
        ->toBe([$this->orgA->id, $this->orgA->id]);
});

it('gives 404 for the other organization\'s task to a member of both until they switch', function () {
    $this->orgB->members()->attach($this->ownerA, ['role_id' => $this->orgB->role(DefaultRole::Owner)->id]);

    $this->getJson("/api/tasks/{$this->taskB->id}/chat")->assertNotFound();
    $this->postJson("/api/tasks/{$this->taskB->id}/chat", ['message' => 'Hi'])->assertNotFound();
    $this->deleteJson("/api/tasks/{$this->taskB->id}/chat")->assertNotFound();

    $this->ai->assertNothingSent();
    expect(TaskChatMessage::withoutGlobalScopes()->where('organization_id', $this->orgB->id)->count())->toBe(2)
        ->and(AiUsage::withoutGlobalScopes()->count())->toBe(0);
});
