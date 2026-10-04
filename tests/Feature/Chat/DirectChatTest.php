<?php

use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\Core\NotificationPreference;
use App\Notifications\GeneralNotification;
use App\Services\Chat\ChatService;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('uploads.disk'));
    $this->company = $this->createCompany();
    $this->other = $this->createCompany();
    $this->a = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER, ['name' => 'Asha']);
    $this->b = $this->createMember($this->company, DefaultRoles::PROJECT_MANAGER, ['name' => 'Bala']);
    $this->c = $this->createMember($this->company, DefaultRoles::ACCOUNTANT, ['name' => 'Chitra']);
    $this->outsider = $this->createMember($this->other, DefaultRoles::SITE_ENGINEER);
});

function openDirect($test, $actor, $with): int
{
    return $test->actingInCompany($actor, $test->company)
        ->postJson(route('chat.direct'), ['user_id' => $with->id])
        ->assertOk()
        ->json('id');
}

test('a direct conversation is unique for a pair in either order', function () {
    $first = openDirect($this, $this->a, $this->b);
    $second = openDirect($this, $this->b, $this->a);

    expect($second)->toBe($first)
        ->and($this->a->id)->not->toBe($this->b->id);

    $this->actingInCompany($this->a, $this->company)
        ->postJson(route('chat.direct'), ['user_id' => $this->a->id])
        ->assertJsonValidationErrors('user_id');
});

test('inactive and other-company users cannot be started', function () {
    $this->c->forceFill(['is_active' => false])->save();

    $this->actingInCompany($this->a, $this->company)
        ->postJson(route('chat.direct'), ['user_id' => $this->c->id])
        ->assertJsonValidationErrors('user_id');

    $this->actingInCompany($this->a, $this->company)
        ->postJson(route('chat.direct'), ['user_id' => $this->outsider->id])
        ->assertJsonValidationErrors('user_id');
});

test('messages keep order, reject empties, accept a reply and page older history', function () {
    $id = openDirect($this, $this->a, $this->b);

    $this->actingInCompany($this->a, $this->company)
        ->postJson(route('chat.messages.store', $id), ['body' => '   ', 'sender_id' => $this->c->id])
        ->assertJsonValidationErrors('body');

    $first = $this->actingInCompany($this->a, $this->company)
        ->postJson(route('chat.messages.store', $id), ['body' => 'First', 'sender_id' => $this->b->id])
        ->assertOk()
        ->json('message');

    expect($first['sender_id'])->toBe($this->a->id);

    $this->actingInCompany($this->b, $this->company)
        ->postJson(route('chat.messages.store', $id), [
            'body' => 'Reply',
            'reply_to_message_id' => $first['id'],
        ])->assertOk()->assertJsonPath('message.reply.id', $first['id']);

    $this->actingInCompany($this->a, $this->company)
        ->postJson(route('chat.messages.store', $id), ['reply_to_message_id' => 999999, 'body' => 'No'])
        ->assertJsonValidationErrors('reply_to_message_id');

    $this->inCompany($this->company, function () use ($id) {
        $conversation = Conversation::query()->findOrFail($id);
        foreach (range(1, 45) as $n) {
            app(ChatService::class)->send($conversation, $this->a, "n{$n}", [], null);
        }
    });

    $page = $this->actingInCompany($this->b, $this->company)
        ->getJson(route('chat.messages.index', $id))
        ->assertOk()
        ->json();

    expect($page['messages'])->toHaveCount(40)
        ->and($page['has_more'])->toBeTrue()
        ->and($page['messages'][0]['id'])->toBeLessThan($page['messages'][39]['id']);

    $older = $this->actingInCompany($this->b, $this->company)
        ->getJson(route('chat.messages.index', ['conversation' => $id, 'before' => $page['messages'][0]['id']]))
        ->json('messages');

    expect($older[0]['body'])->toBe('First')
        ->and(collect($older)->pluck('id')->max())->toBeLessThan($page['messages'][0]['id']);
});

test('unread counts ignore the sender and clear when the conversation is opened', function () {
    $id = openDirect($this, $this->a, $this->b);

    $this->actingInCompany($this->a, $this->company)
        ->postJson(route('chat.messages.store', $id), ['body' => 'Hello Bala']);

    $this->actingInCompany($this->a, $this->company)
        ->getJson(route('chat.unread'))
        ->assertJsonPath('count', 0);

    $this->actingInCompany($this->b, $this->company)
        ->getJson(route('chat.unread'))
        ->assertJsonPath('count', 1);

    $this->actingInCompany($this->b, $this->company)
        ->get(route('chat.show', $id))
        ->assertOk();

    $this->actingInCompany($this->b, $this->company)
        ->getJson(route('chat.unread'))
        ->assertJsonPath('count', 0);
});

test('a non participant cannot read messages or attachments and another company cannot either', function () {
    $id = openDirect($this, $this->a, $this->b);
    $message = $this->actingInCompany($this->a, $this->company)
        ->post(route('chat.messages.store', $id), [
            'body' => 'Plan',
            'files' => [UploadedFile::fake()->image('site.jpg')],
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('message');

    $attachment = $message['attachments'][0]['id'];

    $this->actingInCompany($this->a, $this->company)
        ->get(route('chat.attachments.show', $attachment))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingInCompany($this->c, $this->company)
        ->get(route('chat.show', $id))
        ->assertNotFound();
    $this->actingInCompany($this->c, $this->company)
        ->getJson(route('chat.messages.index', $id))
        ->assertNotFound();
    $this->actingInCompany($this->c, $this->company)
        ->get(route('chat.attachments.show', $attachment))
        ->assertNotFound();

    $this->actingInCompany($this->outsider, $this->other)
        ->get(route('chat.show', $id))
        ->assertNotFound();
    $this->actingInCompany($this->outsider, $this->other)
        ->get(route('chat.attachments.show', $attachment))
        ->assertNotFound();

    expect($this->actingInCompany($this->outsider, $this->other)->getJson(route('chat.directory'))->json('users'))->toBe([]);
});

test('chat files allow an image or pdf and reject an executable or a mismatched type', function () {
    $id = openDirect($this, $this->a, $this->b);
    $pdf = UploadedFile::fake()->createWithContent('note.pdf', "%PDF-1.4\n%note\n1 0 obj << >> endobj\n%%EOF\n");

    $this->actingInCompany($this->b, $this->company)
        ->post(route('chat.messages.store', $id), [
            'files' => [$pdf],
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('message.body', null);

    $this->actingInCompany($this->a, $this->company)
        ->post(route('chat.messages.store', $id), [
            'files' => [UploadedFile::fake()->create('run.exe', 12, 'application/x-msdownload')],
        ], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('files');

    $this->actingInCompany($this->a, $this->company)
        ->post(route('chat.messages.store', $id), [
            'files' => [UploadedFile::fake()->createWithContent('photo.png', 'this is not an image')],
        ], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('files');
});

test('the recipient gets one chat notification unless they turned it off or are reading', function () {
    $id = openDirect($this, $this->a, $this->b);

    Notification::fake();
    $this->actingInCompany($this->a, $this->company)
        ->postJson(route('chat.messages.store', $id), ['body' => 'Ping']);

    Notification::assertSentToTimes($this->b, GeneralNotification::class, 1);
    Notification::assertSentTo($this->b, GeneralNotification::class, fn (GeneralNotification $n) => $n->kind === 'chat.message_received'
        && $n->context['message_id'] > 0);
    Notification::assertNotSentTo($this->a, GeneralNotification::class);

    Notification::fake();
    $this->actingInCompany($this->b, $this->company)->get(route('chat.show', $id));
    $this->actingInCompany($this->a, $this->company)
        ->postJson(route('chat.messages.store', $id), ['body' => 'While you are here']);
    Notification::assertNothingSent();

    NotificationPreference::query()->create([
        'user_id' => $this->b->id,
        'notification_type' => 'chat.message_received',
        'channels' => [],
    ]);
    $this->travel(30)->seconds();
    Notification::fake();
    $this->actingInCompany($this->a, $this->company)
        ->postJson(route('chat.messages.store', $id), ['body' => 'Still stored']);
    Notification::assertNothingSent();
    expect($this->inCompany($this->company, fn () => Message::query()->where('body', 'Still stored')->exists()))->toBeTrue();
});
