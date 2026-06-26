<?php

use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\Template;
use App\Services\Bandeja\MailBridgeService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $mode; // 'reply_client' | 'forward_provider'
    public int $expedientId;
    public int $originMessageId;

    public string $to = '';
    public string $cc = '';
    public string $bcc = '';
    public string $subject = '';
    public string $body = '';
    public ?int $templateId = null;
    public bool $sending = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('expedientes.actualizar'), 403);

        $expedient = Expedient::findOrFail($this->expedientId);
        $origin = MailMessage::findOrFail($this->originMessageId);

        // Pre-fill based on mode
        if ($this->mode === 'reply_client') {
            $this->to = $origin->from_email ?? $expedient->sender_email ?? '';
            $this->subject = 'Re: '.($origin->subject ?? '');
        } else {
            $this->subject = 'Fwd: '.($origin->subject ?? '');
        }

        // Auto-select missing-phone template when expedient has no phone
        if (blank($expedient->sender_phone)) {
            $missingPhone = Template::forPurpose('missing_phone')->active()->first();
            if ($missingPhone) {
                $this->templateId = $missingPhone->id;
                $this->body = $missingPhone->body;
            }
        }
    }

    #[Computed]
    public function expedient(): Expedient
    {
        return Expedient::findOrFail($this->expedientId);
    }

    #[Computed]
    public function origin(): MailMessage
    {
        return MailMessage::findOrFail($this->originMessageId);
    }

    #[Computed]
    public function account(): MailAccount
    {
        return $this->expedient->mailAccount;
    }

    #[Computed]
    public function templates()
    {
        return Template::active()->orderBy('name')->get();
    }

    public function updatedTemplateId(?int $value): void
    {
        if ($value === null) {
            return;
        }

        $template = Template::find($value);
        if ($template) {
            $this->body = $template->body;
            if (blank($this->subject) || str_starts_with($this->subject, 'Re:') || str_starts_with($this->subject, 'Fwd:')) {
                // Keep the Re:/Fwd: prefix but add template subject if meaningful
                if ($template->subject && ! str_starts_with($this->subject, 'Re:') && ! str_starts_with($this->subject, 'Fwd:')) {
                    $this->subject = $template->subject;
                }
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $rules = [
            'body' => ['required', 'string'],
            'subject' => ['required', 'string', 'max:255'],
            'templateId' => ['nullable', 'exists:templates,id'],
        ];

        if ($this->mode === 'forward_provider') {
            $rules['to'] = ['required', 'email'];
        }

        if ($this->cc !== '') {
            $rules['cc'] = ['nullable', 'string'];
        }

        if ($this->bcc !== '') {
            $rules['bcc'] = ['nullable', 'string'];
        }

        return $rules;
    }

    public function send(MailBridgeService $bridgeService): void
    {
        abort_unless(Auth::user()->can('expedientes.actualizar'), 403);

        $validated = $this->validate();

        $expedient = $this->expedient;
        $origin = $this->origin;
        $account = $this->account;

        // Parse CC/BCC from comma-separated strings
        $cc = filled($this->cc) ? array_map('trim', explode(',', $this->cc)) : [];
        $bcc = filled($this->bcc) ? array_map('trim', explode(',', $this->bcc)) : [];

        $bridgeService->send(
            account: $account,
            mode: $this->mode,
            origin: $origin,
            expedient: $expedient,
            actor: Auth::user(),
            payload: [
                'to' => $this->to,
                'body' => $validated['body'],
                'subject' => $validated['subject'],
                'cc' => $cc,
                'bcc' => $bcc,
            ],
        );

        Flux::modal('mail-composer')->close();

        Flux::toast(
            variant: 'success',
            text: $this->mode === 'reply_client'
                ? __('Respuesta enviada al cliente.')
                : __('Reenvío enviado al proveedor.'),
        );
    }
};
?>

<flux:modal name="mail-composer" class="max-w-2xl">
    <div class="space-y-4">
        <flux:heading size="lg">
            @if ($mode === 'reply_client')
                {{ __('Responder al cliente') }}
            @else
                {{ __('Reenviar al proveedor') }}
            @endif
        </flux:heading>

        <flux:text variant="subtle" size="sm">
            {{ __('Expediente') }}: {{ $this->expedient->case_number }}
        </flux:text>

        {{-- Template selector --}}
        <flux:field>
            <flux:label>{{ __('Plantilla') }}</flux:label>
            <flux:select wire:model.live="templateId">
                <flux:select.option value="">{{ __('Sin plantilla') }}</flux:select.option>
                @foreach ($this->templates as $template)
                    <flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:field>

        {{-- To field (always visible for reply, required for forward) --}}
        <flux:field>
            <flux:label>
                @if ($mode === 'reply_client')
                    {{ __('Para') }}
                @else
                    {{ __('Para') }} <flux:badge size="sm" color="red">*</flux:badge>
                @endif
            </flux:label>
            <flux:input wire:model="to" type="email" />
            <flux:error name="to" />
        </flux:field>

        {{-- CC --}}
        <flux:field>
            <flux:label>{{ __('CC') }}</flux:label>
            <flux:input wire:model="cc" :placeholder="__('correo@ejemplo.com, otro@ejemplo.com')" />
        </flux:field>

        {{-- BCC (always shown on forward) --}}
        <flux:field>
            <flux:label>
                {{ __('BCC') }}
                @if ($mode === 'forward_provider')
                    <flux:badge size="sm" color="amber">{{ __('soporte') }}</flux:badge>
                @endif
            </flux:label>
            <flux:input wire:model="bcc" :placeholder="__('correo@ejemplo.com')" />
        </flux:field>

        {{-- Subject --}}
        <flux:field>
            <flux:label>{{ __('Asunto') }}</flux:label>
            <flux:input wire:model="subject" />
            <flux:error name="subject" />
        </flux:field>

        {{-- Body --}}
        <flux:field>
            <flux:label>{{ __('Cuerpo') }} <flux:badge size="sm" color="red">*</flux:badge></flux:label>
            <flux:textarea wire:model="body" rows="8" />
            <flux:error name="body" />
        </flux:field>

        {{-- Actions --}}
        <div class="flex justify-end gap-2 pt-2">
            <flux:button variant="ghost" onclick="Flux.modal('mail-composer').close()">
                {{ __('Cancelar') }}
            </flux:button>
            <flux:button
                variant="primary"
                wire:click="send"
                wire:target="send"
                :disabled="$sending"
                icon="paper-airplane"
            >
                {{ __('Enviar') }}
            </flux:button>
        </div>
    </div>
</flux:modal>
