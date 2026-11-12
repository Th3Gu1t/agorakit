{{-- Votes of a discussion, and the button to start one. Needs $group and $discussion. --}}
<div class="polls mb-4">
    @foreach ($discussion->polls->load(['user', 'options', 'votes.user', 'votes.option']) as $poll)
        @include('polls.poll')
    @endforeach

    @auth
        @can('create', [App\Poll::class, $discussion])
            <a class="btn btn-outline-primary btn-sm"
                href="{{ route('groups.discussions.polls.create', [$group, $discussion]) }}">
                <i aria-hidden="true" class="fas fa-vote-yea me-1"></i> {{ __('Start a vote') }}
            </a>
        @endcan
    @endauth
</div>
