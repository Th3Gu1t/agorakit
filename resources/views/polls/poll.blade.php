{{-- A vote held in a discussion. Needs $poll, $group and $discussion. --}}
@php
    $votes = $poll->votes;
    $myVote = $poll->voteOf(Auth::user());
    $electorate = $poll->electorate();
    $nonVoters = $poll->nonVoters();
    $canVote = $poll->isOpen() && Auth::check() && Auth::user()->can('vote', $poll);
@endphp

<div class="card mb-3" id="poll-{{ $poll->id }}">
    <div class="card-body">

        <div class="d-flex align-items-start gap-2 mb-1">
            <i aria-hidden="true" class="fas fa-vote-yea mt-1"></i>
            <h3 class="h4 mb-0 flex-grow-1">{{ $poll->question }}</h3>
            @if ($poll->isOpen())
                <span class="badge text-bg-success">{{ __('Vote open') }}</span>
            @else
                <span class="badge text-bg-secondary">{{ __('Vote closed') }}</span>
            @endif
        </div>

        <div class="text-meta mb-2">
            @if ($poll->isProposal())
                {{ __('Proposal') }}
            @else
                {{ __('Choice') }}
            @endif
            · {{ __('Started by :name', ['name' => $poll->user->name]) }}
            · {{ dateForHumans($poll->created_at) }}
            ·
            @if ($poll->closedAt())
                {{ __('Closed on :date', ['date' => $poll->closedAt()->isoFormat('LLL')]) }}
            @elseif ($poll->closes_at)
                {{ __('Closes on :date', ['date' => $poll->closes_at->isoFormat('LLL')]) }}
            @else
                {{ __('No closing date') }}
            @endif
        </div>

        @if ($poll->body)
            <div class="mb-3">{!! nl2br(e($poll->body)) !!}</div>
        @endif

        {{-- Results --}}
        <div class="mb-2">
            @foreach ($poll->options as $option)
                @php
                    $count = $votes->where('poll_option_id', $option->id)->count();
                    $percent = $votes->count() > 0 ? round((100 * $count) / $votes->count()) : 0;
                @endphp
                <div class="mb-2 poll-result">
                    <div class="d-flex justify-content-between gap-2">
                        <span>
                            <span class="badge text-bg-{{ $option->color() }}">{{ $option->name() }}</span>
                            @if ($myVote && $myVote->poll_option_id == $option->id)
                                <small class="text-meta ms-1">{{ __('your vote') }}</small>
                            @endif
                        </span>
                        <span class="text-meta text-nowrap">
                            {{ trans_choice(':count vote|:count votes', $count) }} · {{ $percent }} %
                        </span>
                    </div>
                    {{-- decoration only: the count and the percentage are written above --}}
                    <div aria-hidden="true" class="progress" style="height: .5rem">
                        <div class="progress-bar bg-{{ $option->color() }}" style="width: {{ $percent }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="text-meta mb-3">
            {{ __(':voted of :members members voted', [
                'voted' => $electorate->count() - $nonVoters->count(),
                'members' => $electorate->count(),
            ]) }}
        </div>

        {{-- Vote form --}}
        @if ($canVote)
            <form action="{{ route('groups.discussions.polls.vote', [$group, $discussion, $poll]) }}" class="mb-3"
                method="POST" up-target="#poll-{{ $poll->id }}">
                @csrf

                <fieldset>
                    <legend class="form-label fw-bold">
                        {{ $myVote ? __('Change your vote') : __('Your vote') }}
                    </legend>
                    @foreach ($poll->options as $option)
                        <div class="form-check">
                            <input @checked($myVote && $myVote->poll_option_id == $option->id) class="form-check-input"
                                id="poll-{{ $poll->id }}-option-{{ $option->id }}" name="option" required
                                type="radio" value="{{ $option->id }}">
                            <label class="form-check-label" for="poll-{{ $poll->id }}-option-{{ $option->id }}">
                                {{ $option->name() }}
                            </label>
                        </div>
                    @endforeach
                </fieldset>

                <label class="form-label mt-2" for="poll-{{ $poll->id }}-reason">{{ __('Reason (optional)') }}</label>
                <textarea class="form-control" id="poll-{{ $poll->id }}-reason" name="reason" rows="2">{{ $myVote?->reason }}</textarea>

                <button class="btn btn-primary mt-2" type="submit">
                    {{ $myVote ? __('Change my vote') : __('Vote') }}
                </button>
            </form>
        @endif

        {{-- Who voted what, and why --}}
        @if ($votes->count() > 0)
            <details class="mb-2">
                <summary>{{ __('Votes and reasons') }} ({{ $votes->count() }})</summary>
                <ul class="list-unstyled mt-2 mb-0">
                    @foreach ($votes->sortBy([fn($a, $b) => $a->option->position <=> $b->option->position, fn($a, $b) => $a->updated_at <=> $b->updated_at]) as $vote)
                        <li class="d-flex gap-2 mb-2">
                            @include('users.avatar', ['user' => $vote->user])
                            <div>
                                <strong>{{ $vote->user->name }}</strong>
                                <span class="badge text-bg-{{ $vote->option->color() }} ms-1">{{ $vote->option->name() }}</span>
                                @if ($vote->reason)
                                    <div>{!! nl2br(e($vote->reason)) !!}</div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        {{-- Members who did not vote yet, for members only --}}
        @if ($poll->isOpen() && $nonVoters->count() > 0)
            @can('view-members', $group)
                <details>
                    <summary>{{ __('Not voted yet') }} ({{ $nonVoters->count() }})</summary>
                    <div class="mt-2">{{ $nonVoters->pluck('name')->join(', ') }}</div>
                </details>
            @endcan
        @endif

    </div>
</div>
