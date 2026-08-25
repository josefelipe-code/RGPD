<x-mail::message>
# {{ __('Aviso de vencimiento de expediente') }}

{{ __('Expediente') }}: {{ $caseNumber }}

{{ __('Estado actual') }}: {{ $status }}

{{ __('Vencimiento') }}: {{ $deadline->format('d/m/Y H:i') }}

{{ __('Tipo de aviso') }}: {{ match ($alertType) {
    'five_days' => __('Faltan 5 días'),
    'twenty_four_hours' => __('Faltan 24 horas'),
    'overdue' => __('Vencido'),
} }}

{{ config('app.name') }}
</x-mail::message>
