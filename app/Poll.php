<?php

namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vote held in a discussion.
 *
 * Two types exist: a proposal, answered with fixed options (agree, abstain,
 * disagree and, if allowed, block), and a choice between options written by
 * its author. Each member of the group has one vote, which they can change
 * while the poll is open.
 */
class Poll extends Model
{
    public const PROPOSAL = 'proposal';
    public const CHOICE = 'choice';

    public const TYPES = [self::PROPOSAL, self::CHOICE];

    /**
     * Fixed options of a proposal, in display order.
     */
    public const PROPOSAL_OPTIONS = ['agree', 'abstain', 'disagree'];

    /**
     * Optional fourth option of a proposal: a major objection, which stops it.
     */
    public const BLOCK = 'block';

    protected $table = 'polls';

    protected $fillable = ['type', 'question', 'body', 'secret', 'closes_at'];

    protected $casts = [
        'secret' => 'boolean',
        'closes_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('position');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    /**
     * Polls still open, as isOpen() tells for one poll.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at')
            ->where(function (Builder $query) {
                $query->whereNull('closes_at')->orWhere('closes_at', '>', Carbon::now());
            });
    }

    /**
     * Polls held in the discussions of a group, deleted discussions left out.
     */
    public function scopeInGroup(Builder $query, Group $group): Builder
    {
        return $query->whereHas('discussion', function (Builder $query) use ($group) {
            $query->where('group_id', $group->id)->whereNull('deleted_at');
        });
    }

    /**
     * Polls this user has not answered yet.
     */
    public function scopeWithoutVoteOf(Builder $query, User $user): Builder
    {
        return $query->whereDoesntHave('votes', function (Builder $query) use ($user) {
            $query->where('user_id', $user->id);
        });
    }

    public function isProposal(): bool
    {
        return $this->type === self::PROPOSAL;
    }

    /**
     * A poll is open until it is closed by hand or until its closing date has passed.
     */
    public function isOpen(): bool
    {
        if ($this->closed_at) {
            return false;
        }

        return !$this->closes_at || $this->closes_at->isFuture();
    }

    /**
     * When the poll stopped accepting votes, or null if it is still open.
     */
    public function closedAt(): ?Carbon
    {
        if ($this->closed_at) {
            return $this->closed_at;
        }

        return $this->isOpen() ? null : $this->closes_at;
    }

    /**
     * Stops the poll now.
     */
    public function close(): void
    {
        if ($this->isOpen()) {
            $this->closed_at = Carbon::now();
            $this->save();
        }
    }

    /**
     * The vote of this user, or null if they did not vote.
     */
    public function voteOf(?User $user): ?PollVote
    {
        if (!$user) {
            return null;
        }

        return $this->votes->firstWhere('user_id', $user->id);
    }

    /**
     * Members of the group, who are the ones expected to vote.
     */
    public function electorate(): Collection
    {
        return $this->discussion->group->users->reject(function (User $user) {
            return $user->isBanned();
        })->values();
    }

    /**
     * Members of the group who did not vote yet.
     */
    public function nonVoters(): Collection
    {
        $voters = $this->votes->pluck('user_id');

        return $this->electorate()->reject(function (User $user) use ($voters) {
            return $voters->contains($user->id);
        })->values();
    }
}
