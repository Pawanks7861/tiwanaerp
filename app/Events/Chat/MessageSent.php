<?php

namespace App\Events\Chat;

use App\Models\Chat\Message;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a chat message is stored. Persistence does not depend on a socket server;
 * a broadcaster can listen to this later without changing the tables.
 */
class MessageSent
{
    use Dispatchable, SerializesModels;

    public function __construct(public Message $message) {}
}
