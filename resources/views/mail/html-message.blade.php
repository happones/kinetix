{{--
    A body that is already HTML, inside the app's own mail layout: its header,
    footer and theme. MailLayout::htmlBlock() made the body a single HTML
    block, which the layout's markdown pass leaves as it is.
--}}
<x-mail::message>
{!! $body !!}
</x-mail::message>
