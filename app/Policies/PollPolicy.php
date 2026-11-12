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
}
