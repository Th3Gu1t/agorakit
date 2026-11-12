{{-- Flags a discussion that holds an open vote, in lists of discussions. Needs $discussion. --}}
@if ($discussion->polls->contains(fn($poll) => $poll->isOpen()))
    <span class="badge text-bg-success me-2 poll-open-badge">
        <i aria-hidden="true" class="fas fa-vote-yea me-1"></i>{{ __('Vote open') }}
    </span>
@endif
