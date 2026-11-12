<?php

namespace App\Http\Controllers;

use App\Discussion;
use App\Group;
use App\Poll;
use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Votes held in discussions.
 */
class PollController extends Controller
{
    /**
     * Most options a choice can offer.
     */
    private const MAX_OPTIONS = 20;

    public function __construct()
    {
        $this->middleware('verified', ['only' => ['create', 'store']]);
    }

    /**
     * Show the form to start a vote in a discussion.
     */
    public function create(Group $group, Discussion $discussion)
    {
        $this->checkDiscussion($group, $discussion);
        $this->authorize('create', [Poll::class, $discussion]);

        return view('polls.create')
            ->with('title', $group->name . ' - ' . __('Start a vote'))
            ->with('group', $group)
            ->with('discussion', $discussion)
            ->with('default_closing_date', Carbon::now()->addWeek())
            ->with('tab', 'discussions');
    }

    /**
     * Start a vote in a discussion.
     */
    public function store(Request $request, Group $group, Discussion $discussion)
    {
        $this->checkDiscussion($group, $discussion);
        $this->authorize('create', [Poll::class, $discussion]);

        if ($discussion->isArchived()) {
            warning(__('This discussion is archived, no vote can be started in it.'));
            return redirect()->route('groups.discussions.show', [$group, $discussion]);
        }

        $request->validate([
            'type' => ['required', Rule::in(Poll::TYPES)],
            'question' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'options' => ['nullable', 'string', 'max:10000'],
            'closes_at_date' => ['nullable', 'date_format:Y-m-d'],
            'closes_at_time' => ['nullable', 'date_format:H:i'],
        ]);

        $options = $request->input('type') === Poll::PROPOSAL
            ? $this->proposalOptions($request->boolean('allow_block'))
            : $this->choiceOptions((string) $request->input('options'));
        $closesAt = $this->closingDate($request->input('closes_at_date'), $request->input('closes_at_time'));

        $poll = DB::transaction(function () use ($request, $discussion, $options, $closesAt) {
            $poll = new Poll([
                'type' => $request->input('type'),
                'question' => $request->input('question'),
                'body' => $request->input('body'),
                'closes_at' => $closesAt,
            ]);
            $poll->user()->associate(Auth::user());
            $discussion->polls()->save($poll);

            foreach ($options as $position => $attributes) {
                $poll->options()->create($attributes + ['position' => $position]);
            }

            return $poll;
        });

        // the discussion shows up as active again
        $discussion->touch();
        $group->touch();

        flash(__('The vote is open.'));

        return $this->showPoll($group, $discussion, $poll);
    }

    /**
     * Options of a proposal: fixed, translated at display time.
     */
    private function proposalOptions(bool $allowBlock): array
    {
        $keys = Poll::PROPOSAL_OPTIONS;
        if ($allowBlock) {
            $keys[] = Poll::BLOCK;
        }

        return array_map(fn ($key) => ['key' => $key], $keys);
    }

    /**
     * Options of a choice: one per line, blank lines and repeats left out.
     */
    private function choiceOptions(string $text): array
    {
        $labels = Collection::make(preg_split('/\R/', $text))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => $line !== '')
            ->unique(fn ($line) => mb_strtolower($line))
            ->values();

        if ($labels->count() < 2) {
            throw ValidationException::withMessages([
                'options' => __('A choice needs at least two different options, one per line.'),
            ]);
        }
        if ($labels->count() > self::MAX_OPTIONS) {
            throw ValidationException::withMessages([
                'options' => __('A choice can offer at most :count options.', ['count' => self::MAX_OPTIONS]),
            ]);
        }
        if ($labels->contains(fn ($label) => mb_strlen($label) > 255)) {
            throw ValidationException::withMessages([
                'options' => __('Each option must be at most 255 characters long.'),
            ]);
        }

        return $labels->map(fn ($label) => ['label' => $label])->all();
    }

    /**
     * The closing date from the form, in the time zone of the application, or null for none.
     * Without a time, the vote closes at the end of the day.
     */
    private function closingDate(?string $date, ?string $time): ?Carbon
    {
        if (!$date) {
            return null;
        }

        $closesAt = Carbon::createFromFormat('Y-m-d H:i', $date . ' ' . ($time ?: '23:59'), config('app.timezone'));

        if ($closesAt->isPast()) {
            throw ValidationException::withMessages([
                'closes_at_date' => __('The closing date must be in the future.'),
            ]);
        }

        return $closesAt;
    }

    /**
     * Refuses a discussion reached through another group.
     */
    private function checkDiscussion(Group $group, Discussion $discussion): void
    {
        abort_unless((int) $discussion->group_id === (int) $group->id, 404);
    }

    /**
     * Back to the discussion, scrolled to the poll.
     */
    private function showPoll(Group $group, Discussion $discussion, Poll $poll)
    {
        return redirect()->to(route('groups.discussions.show', [$group, $discussion]) . '#poll-' . $poll->id);
    }
}
