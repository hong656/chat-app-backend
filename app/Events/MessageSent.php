<?php
namespace App\Events;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\Message;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class MessageSent implements ShouldBroadcastNow
{
   use Dispatchable, InteractsWithSockets, SerializesModels;
   
   public $message;
   
   // ✅ Force Laravel to use Reverb, not Pusher
   public $broadcastConnection = 'reverb';
   
   public function __construct(Message $message)
   {
       $this->message = $message;
       
       // Log when message is being broadcast
       Log::info('Broadcasting message', [
           'message_id' => $message->message_id,
           'chat_id' => $message->chat_id,
           'sender_id' => $message->sender_id,
           'text' => $message->text,
           'channel' => 'chat.' . $message->chat_id
       ]);
   }
   
   public function broadcastOn()
   {       
       return new PrivateChannel('chat.' . $this->message->sender->user_id);
   }
   
   public function broadcastWith()
   {
       $data = [
           'message' => [
               'message_id' => $this->message->message_id,
               'sender_id' => $this->message->sender_id,
               'text' => $this->message->text,
               'message_type' => $this->message->message_type,
               'replied_to_message_id' => $this->message->replied_to_message_id,
               'created_at' => $this->message->created_at,
               'sender' => [
                   'user_id' => $this->message->sender->user_id,
                   'name' => $this->message->sender->name,
               ],
           ]
       ];
       
       // Log the actual data being broadcast
       Log::info('Message broadcast data', [
           'channel' => 'chat.' . $this->message->chat_id,
           'data' => $data
       ]);
       
       return $data;
   }
}
