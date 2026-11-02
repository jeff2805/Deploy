@extends('layouts.public')

@section('title', 'Messages')

@push('styles')
    <link rel="stylesheet" href="@vdasset('css/messages.css')">
@endpush

@section('content')
    <div class="container py-5">
        <x-page-header title="Messages"
                       subtitle="Your conversations with resorts on Dasol.">
            @if ($unread > 0)
                <span class="vd-badge vd-badge--danger">{{ $unread }} unread</span>
            @endif
        </x-page-header>

        {{-- Finding a thread, not finding a sentence inside one. The
             search looks at the other party's name and the last thing
             said; it never reads through private correspondence. --}}
        <form method="GET" action="{{ route('messages.index') }}" class="vd-msg-search" role="search">
            <label class="visually-hidden" for="msg-search">Search your conversations</label>

            <input class="form-control" type="search" id="msg-search" name="q"
                   value="{{ $search }}"
                   placeholder="Search by resort, guest, or the last message">

            <button class="btn btn-primary" type="submit">
                <i class="bi bi-search me-1" aria-hidden="true"></i>Search
            </button>

            @if ($search !== '')
                <a class="btn btn-light border" href="{{ route('messages.index') }}">Clear</a>
            @endif
        </form>

        @forelse ($conversations as $conversation)
            @php
                $side = $conversation->sideFor(auth()->user());
                $mine = $conversation->unreadFor(auth()->user());

                // A guest sees the resort; the resort sees the guest.
                $otherName = $side?->value === 'owner'
                    ? ($conversation->tourist?->full_name ?? 'Guest')
                    : $conversation->resort->name;
            @endphp

            <a class="vd-msg-row @if ($mine > 0) vd-msg-row--unread @endif"
               href="{{ route('messages.show', $conversation) }}">
                <span class="vd-msg-row__who">
                    <span class="vd-msg-row__name">{{ $otherName }}</span>

                    @if ($side?->value !== 'owner')
                        <span class="vd-msg-row__where">Brgy. {{ $conversation->resort->barangay }}</span>
                    @endif
                </span>

                <span class="vd-msg-row__last">
                    {{ $conversation->last_message_preview ?: 'No messages yet.' }}
                </span>

                <span class="vd-msg-row__meta">
                    @if ($conversation->last_message_at)
                        <span class="vd-msg-row__when">
                            {{ $conversation->last_message_at->diffForHumans(short: true) }}
                        </span>
                    @endif

                    @if ($mine > 0)
                        <span class="vd-msg-row__count">{{ $mine }}<span class="visually-hidden"> unread</span></span>
                    @endif
                </span>
            </a>
        @empty
            <x-empty-state icon="chat-dots"
                           title="No conversations yet"
                           message="Open a resort and choose “Message resort” to ask about a stay." />
        @endforelse

        <div class="mt-4">{{ $conversations->links() }}</div>
    </div>
@endsection
