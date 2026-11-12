@extends('app')

@section('content')
    <div style="max-width: 50em">
        <h1>{{ __('Start a vote') }}</h1>

        <p class="text-meta">
            {{ __('In the discussion') }}
            <a href="{{ route('groups.discussions.show', [$group, $discussion]) }}">{{ $discussion->name }}</a>
        </p>

        <form action="{{ route('groups.discussions.polls.store', [$group, $discussion]) }}" method="POST">
            @csrf

            <fieldset class="mb-4">
                <legend class="form-label">{{ __('Kind of vote') }}</legend>

                <div class="form-check">
                    <input @checked(old('type', 'proposal') == 'proposal') class="form-check-input" id="type-proposal"
                        name="type" type="radio" up-switch=".poll-type-field" value="proposal">
                    <label class="form-check-label" for="type-proposal">
                        {{ __('Proposal: members answer in favour, against or abstain') }}
                    </label>
                </div>

                <div class="form-check">
                    <input @checked(old('type') == 'choice') class="form-check-input" id="type-choice" name="type"
                        type="radio" up-switch=".poll-type-field" value="choice">
                    <label class="form-check-label" for="type-choice">
                        {{ __('Choice: members pick one of the options you write') }}
                    </label>
                </div>
            </fieldset>

            <div class="mb-3">
                <label class="form-label" for="question">{{ __('Question') }}</label>
                <input class="form-control" id="question" maxlength="255" name="question" required type="text"
                    value="{{ old('question') }}">
                <div class="form-text">
                    {{ __('For a proposal, write what is proposed, for example: we buy a new printer.') }}
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="body">{{ __('Details (optional)') }}</label>
                <textarea class="form-control" id="body" name="body" rows="4">{{ old('body') }}</textarea>
            </div>

            <div class="mb-3 poll-type-field" up-show-for="proposal">
                <div class="form-check">
                    <input @checked(old('allow_block')) class="form-check-input" id="allow_block" name="allow_block"
                        type="checkbox" value="1">
                    <label class="form-check-label" for="allow_block">
                        {{ __('Proposal: let members block it') }}
                    </label>
                </div>
                <div class="form-text">
                    {{ __('A block is a major objection: the member cannot accept the proposal as it stands.') }}
                </div>
            </div>

            <div class="mb-3 poll-type-field" up-show-for="choice">
                <label class="form-label" for="options">{{ __('Choice: options, one per line') }}</label>
                <textarea class="form-control" id="options" name="options" rows="5">{{ old('options') }}</textarea>
                <div class="form-text">{{ __('At least two options.') }}</div>
            </div>

            <div class="row mb-1">
                <div class="col-sm-6 mb-2">
                    <label class="form-label" for="closes_at_date">{{ __('Closing date (optional)') }}</label>
                    <input class="form-control" id="closes_at_date" name="closes_at_date" type="date"
                        value="{{ old('closes_at_date', $default_closing_date->format('Y-m-d')) }}">
                </div>
                <div class="col-sm-6 mb-2">
                    <label class="form-label" for="closes_at_time">{{ __('Closing time') }}</label>
                    <input class="form-control" id="closes_at_time" name="closes_at_time" type="time"
                        value="{{ old('closes_at_time', '20:00') }}">
                </div>
            </div>
            <div class="form-text mb-4">
                {{ __('Without a closing date, the vote stays open until it is closed by hand.') }}
            </div>

            <button class="btn btn-primary" type="submit">{{ __('Start the vote') }}</button>
        </form>
    </div>
@endsection
