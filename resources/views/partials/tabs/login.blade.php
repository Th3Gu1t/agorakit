<ul class="nav nav-underline mb-4">
    <li class="nav-item">
        <a class="nav-link @if ($tab == 'password') active @endif" href="{{ route('login') }}">
            <i aria-hidden="true" class="fa fa-key"></i> <span class="ms-2">{{ trans('messages.password') }}</span>
        </a>
    </li>

    <li class="nav-item">
        <a class="nav-link @if ($tab == 'link') active @endif" href="{{ route('loginbyemail') }}">
            <i aria-hidden="true" class="fa fa-envelope"></i> <span class="ms-2">{{ __('Link by email') }}</span>
        </a>
    </li>
</ul>
