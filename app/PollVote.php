<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The vote of a member on a poll, with the reason they may give.
 */
class PollVote extends Model
{
    protected $table = 'poll_votes';

    protected $fillable = ['poll_id', 'poll_option_id', 'user_id', 'reason'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'poll_option_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
