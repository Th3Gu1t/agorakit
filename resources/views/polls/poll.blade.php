{{-- A vote held in a discussion. Needs $poll, $group and $discussion. --}}
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

        <ul class="list-unstyled mb-0">
            @foreach ($poll->options as $option)
                <li class="mb-1">
                    <span class="badge text-bg-{{ $option->color() }}">{{ $option->name() }}</span>
                </li>
            @endforeach
        </ul>

    </div>
</div>
