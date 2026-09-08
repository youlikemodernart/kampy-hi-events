<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.email_logo_link_url')">
            @if($appLogo = config('app.email_logo_url'))
                <img src="{{ $appLogo }}" class="logo" alt="{{ config('app.name') }}"
                     style="max-width: 300px;">
            @else
                {{-- No brand logo asset ships with this repository. A wordmark in the
                     brand face is used rather than falling back to an upstream mark. --}}
                <span class="brand-wordmark">{{ config('app.name') }}</span>
            @endif
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            @if($appEmailFooter = config('app.email_footer_text'))
{{ $appEmailFooter }}
            @else
© {{ date('Y') }} {{ config('app.name') }}
            @endif

{{--
    (c) Hi.Events Ltd 2025

    PLEASE NOTE:

    Hi.Events is licensed under the GNU Affero General Public License (AGPL) version 3.
    You can find the full license text at: https://github.com/HiEventsDev/hi.events/blob/main/LICENSE
    In accordance with Section 7(b) of the AGPL, we ask that you retain the "Powered by Hi.Events" notice.
    If you wish to remove this notice, a commercial license is available at: https://hi.events/licensing

    The notice is retained and rendered below on every email. It is now emitted
    OUTSIDE the app.email_footer_text branch: previously, setting APP_EMAIL_FOOTER_TEXT
    to add brand identity would also have deleted the attribution, which made a
    styling variable into a licensing lever. Subordination here is by order and
    scale only. Do not hide, shrink below the .attribution size, or remove it
    without a licensing decision.
--}}
<span class="attribution">Powered by [Hi.Events](https://hi.events?utm_source=app-email-footer)</span>
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
