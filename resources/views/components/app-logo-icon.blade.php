@props([
    'alt' => 'Logotipo de la empresa',
])

<img
    src="{{ asset('images/logo-empresa.jpeg') }}"
    alt="{{ $alt }}"
    {{ $attributes->merge([
        'class' => 'object-contain',
    ]) }}
>
