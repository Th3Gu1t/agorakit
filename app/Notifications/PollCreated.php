<?php

namespace App\Notifications;

use App\Poll;
use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Request;

/**
 * Tells a member that a vote was started in one of their groups.
 * Sent at once to members who asked to be notified instantly.
 */
class PollCreated extends Notification
{
    use Queueable;

    public Poll $poll;
    public User $user;

    public function __construct(Poll $poll, User $user)
    {
        $this->poll = $poll;
        $this->user = $user;
    }

    /**
     * @param mixed $notifiable
     */
    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * @param mixed $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $discussion = $this->poll->discussion;
        $group = $discussion->group;

        $message = (new MailMessage())
            ->greeting(' ')
            ->salutation(' ')
            ->subject('[' . $group->name . '] ' . __('Vote: :question', ['question' => $this->poll->question]))
            ->line(__(':name started a vote in the discussion ":discussion".', [
                'name' => $this->user->name,
                'discussion' => $discussion->name,
            ]))
            ->line($this->poll->question);

        if ($this->poll->body) {
            $message->line($this->poll->body);
        }

        $message->line($this->poll->closes_at
            ? __('Closes on :date', ['date' => $this->poll->closes_at->isoFormat('LLL')])
            : __('No closing date'));

        $message->action(__('Vote'), route('groups.discussions.show', [$group, $discussion]) . '#poll-' . $this->poll->id)
            ->line(trans('messages.dont_reply_to_this_email'))
            ->from(config('mail.noreply'), config('mail.from.name'));

        $message->withSymfonyMessage(function ($message) use ($discussion) {
            $message->getHeaders()->addTextHeader('References', 'discussion-' . $discussion->id . '@' . Request::getHost());
        });

        return $message;
    }

    /**
     * @param mixed $notifiable
     */
    public function toArray($notifiable): array
    {
        return [
            'poll' => $this->poll->toArray(),
            'user' => $this->user->toArray(),
        ];
    }
}
