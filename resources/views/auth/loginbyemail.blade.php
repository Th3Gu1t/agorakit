@extends('dialog')

@section('content')
    <h1 class="d-flex mb-4">
        <img alt="" class="rounded" height="40" src="{{ route('icon', 40) }}" width="40" />
        <div class="ms-2">
            {{ setting('name') }} : {{ strtolower(trans('messages.login')) }}
        </div>

    </h1>

    @include('partials.tabs.login', ['tab' => 'link'])

    <form action="{{ route('sendloginbyemail') }}" class="mb-5" method="POST">

        @csrf
        @honeypot

        <div class="form-group mb-3">
            <label class="form-label">{{ __('Just enter your email :') }}</label>
            <input class="form-control" name="email" required="required" type="email"
                @if (isset($email)) value="{{ $email }}" @endif>
            <div class="form-text">
                @lang('No password needed: we send you a link that signs you in. It is valid for 30 minutes.')
            </div>
        </div>

        <div class="my-4">
            <button class="btn btn-primary" type="submit">@lang('Send me the link')</button>
        </div>

    </form>

    <div class="alert alert-primary" role="alert">
        <i aria-hidden="true" class="fa fa-info-circle"></i>
        <a href="{{ route('register') }}">@lang('Click here to register a new account')</a>
    </div>
@endsection
