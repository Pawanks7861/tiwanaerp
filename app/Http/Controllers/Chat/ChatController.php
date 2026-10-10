<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\Chat\MessageAttachment;
use App\Services\Chat\ChatFileStore;
use App\Services\Chat\ChatPresenter;
use App\Services\Chat\ChatService;
use App\Services\Uploads\LargeFileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company chat. Participation is checked here, not through a policy: Gate::before would
 * otherwise let a platform super admin open every conversation in the company they entered.
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly ChatPresenter $presenter,
        private readonly ChatFileStore $files,
        private readonly LargeFileUploadService $uploads,
    ) {}

    public function index(Request $request, ?Conversation $conversation = null): Response
    {
        $active = null;
        if ($conversation !== null) {
            $this->chat->participant($conversation, $request->user());
            $this->chat->markRead($conversation, $request->user());
            $peer = $conversation->participants()->where('user_id', '!=', $request->user()->id)->with('user:id,name,last_seen_at,is_active')->first()?->user;
            $active = [
                'id' => $conversation->id,
                'peer' => $peer ? $this->presenter->person($peer, null) : null,
                'page' => $this->presenter->page($conversation, null, null),
            ];
        }

        return Inertia::render('Chat/Index', [
            'conversations' => $this->presenter->inbox($request->user()),
            'active' => $active,
        ]);
    }

    public function directory(Request $request): JsonResponse
    {
        return response()->json([
            'users' => $this->presenter->directory($request->user(), (string) $request->query('q', '')),
        ]);
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->presenter->unreadTotal($request->user())]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $this->chat->touchPresence($request->user());

        return response()->json(['ok' => true]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $conversation = $this->chat->direct($request->user(), (int) $data['user_id']);

        return response()->json([
            'id' => $conversation->id,
            'url' => route('chat.show', $conversation),
        ]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->chat->participant($conversation, $request->user());
        if ($request->boolean('reading')) {
            $this->chat->markRead($conversation, $request->user());
        }

        $before = $request->filled('before') ? (int) $request->query('before') : null;
        $after = $request->filled('after') ? (int) $request->query('after') : null;

        return response()->json($this->presenter->page($conversation, $before, $after) + [
            'unread' => $this->presenter->unreadTotal($request->user()),
        ]);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->chat->participant($conversation, $request->user());
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:8000'],
            'upload_ids' => ['nullable', 'array', 'max:'.ChatFileStore::MAX_FILES],
            'upload_ids.*' => ['uuid'],
            'reply_to_message_id' => ['nullable', 'integer'],
        ]);
        $files = array_values($request->file('files', []) ?? []);
        foreach ($data['upload_ids'] ?? [] as $id) {
            $files[] = $this->uploads->claim($request->user(), $id);
        }
        $message = $this->chat->send(
            $conversation,
            $request->user(),
            $data['body'] ?? $request->input('body'),
            $files,
            isset($data['reply_to_message_id']) ? (int) $data['reply_to_message_id'] : null,
        );
        foreach ($data['upload_ids'] ?? [] as $id) {
            $this->uploads->release($request->user(), $id);
        }

        return response()->json(['message' => $this->presenter->message($message)]);
    }

    public function update(Request $request, Message $message): JsonResponse
    {
        $message = $this->chat->edit($message, $request->user(), $request->input('body'));

        return response()->json(['message' => $this->presenter->message($message->load(['attachments', 'replyTo', 'sender:id,name']))]);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        $this->chat->delete($message, $request->user());

        return response()->json(['message' => $this->presenter->message($message->fresh()->load(['attachments', 'replyTo', 'sender:id,name']))]);
    }

    public function attachment(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $message = $attachment->message()->firstOrFail();
        $this->chat->participant($message->conversation, $request->user());
        abort_if($message->isDeleted(), 404);

        return $this->files->respond($attachment, $request->boolean('inline', true));
    }
}
