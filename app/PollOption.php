<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the answers of a poll.
 */
class PollOption extends Model
{
    protected $table = 'poll_options';

    protected $fillable = ['key', 'label', 'position'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    /**
     * The text of the option: translated for the fixed options of a proposal,
     * as written by the author for a choice.
     */
    public function name(): string
    {
        return match ($this->key) {
            'agree' => __('In favour'),
            'abstain' => __('Abstain'),
            'disagree' => __('Against'),
            'block' => __('Block'),
            default => (string) $this->label,
        };
    }

    /**
     * Bootstrap color of the option, so that results read at a glance.
     */
    public function color(): string
    {
        return match ($this->key) {
            'agree' => 'success',
            'abstain' => 'secondary',
            'disagree' => 'warning',
            'block' => 'danger',
            default => 'primary',
        };
    }
}
