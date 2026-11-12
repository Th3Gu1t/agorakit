<?php

namespace App\Policies;

use App\Discussion;
use App\Poll;
use App\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Who may start, answer and close the votes held in discussions.
 *
 * These checks are about people. Whether a poll is still open is checked by
 * the controller as well, since BasePolicy lets site admins through every
 * check.
 */
class PollPolicy extends BasePolicy
{
    use HandlesAuthorization;

    /**
     * Starting a vote is part of discussing: it needs the right to create discussions in the group.
     */
    public function create(User $user, Discussion $discussion)
    {
        return !$discussion->isArchived()
            && $this->getPermissionsFor($user, $discussion->group)->contains('create-discussion');
    }

    /**
     * Every member of the group has a vote.
     */
    public function vote(User $user, Poll $poll)
    {
        return $user->isMemberOf($poll->discussion->group);
    }

    /**
     * The author of a vote and the admins of the group may close it before its closing date.
     */
    public function close(User $user, Poll $poll)
    {
        return (int) $user->id === (int) $poll->user_id || $user->isAdminOf($poll->discussion->group);
    }

    /**
     * Those who may close a vote also write down the decision taken once it is closed.
     */
    public function decide(User $user, Poll $poll)
    {
        return $this->close($user, $poll);
    }

    /**
     * Those who may close a vote may also delete it, for instance when it was started by mistake.
     */
    public function delete(User $user, Poll $poll)
    {
        return $this->close($user, $poll);
    }
}
