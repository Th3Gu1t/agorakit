@extends('dialog')

@section('content')
    <h1>{{ __('Delete this vote?') }}</h1>

    <p>
        <strong>{{ $poll->question }}</strong>
    </p>

    <p>
        {{ trans_choice('Its results and the vote of :count member are deleted for good.|Its results and the votes of :count members are deleted for good.', $poll->votes()->count()) }}
    </p>

    <form action="{{ route('groups.discussions.polls.delete', [$group, $discussion, $poll]) }}" method="POST">
        @csrf
        @method('DELETE')

        <div class="d-flex justify-content-between">
            <button class="btn btn-danger" type="submit">{{ __('Delete the vote') }}</button>
            <a class="btn btn-link" href="{{ route('groups.discussions.show', [$group, $discussion]) }}" up-dismiss>
                {{ trans('messages.cancel') }}
            </a>
        </div>
    </form>
@endsection
