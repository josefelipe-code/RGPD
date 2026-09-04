<?php

use App\Models\Contact;
use App\Models\Expedient;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\Template;
use App\Models\User;
use App\Services\Bandeja\OutboundMailContext;
use App\Services\Bandeja\OutboundMailService;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?int $accountId = null;

    public ?string $folder = null;

    public ?int $imapUid = null;

    public ?string $mode = null;

    public ?int $expedientId = null;

    public ?int $originMessageId = null;

    /** @var array<string, mixed> */
    public array $originData = [];

    public bool $open = true;

    public string $to = '';

    public string $cc = '';

    public string $bcc = '';

    public string $subject = '';

    public string $body = '';

    public ?int $contactId = null;

    public string $contactSearch = '';

    public ?int $templateId = null;

    public ?int $signatureId = null;

    public bool $sending = false;

    public string $stateDeadline = '';

    public ?string $reservationExpiresAt = null;

    public ?string $reservationOperatorName = null;

    public function boot(): void
    {
        $this->withValidator(function (Validator $validator): void {
            $validator->after(function (Validator $validator): void {
                foreach ($this->invalidRecipientFields($this->recipientLists($this->cc, $this->bcc)) as $field) {
                    $validator->errors()->add($field, $this->invalidRecipientMessage());
                }
            });
        });
    }

    /** Prepare the shared composer through the backend service before rendering it. */
    public function mount(OutboundMailService $outbound): void
    {
        abort_unless(Auth::user()->can('bandeja.clasificar') || Auth::user()->can('expedientes.actualizar'), 403);

        try {
            if ($this->hasExpedient()) {
                $this->stateDeadline = $this->expedient->state_deadline?->format('Y-m-d\TH:i') ?? '';
            }

            $this->signatureId = $this->account->signatures()->active()->default()->value('id');
            $prepared = $outbound->prepare($this->context(), applyDefaults: true);
            $context = $prepared['context'];
            $reservation = $prepared['reservation'];

            $this->to = $context->recipient;
            $this->subject = $context->subject;
            $this->body = $context->body;
            $this->reservationExpiresAt = $reservation->expires_at->toIso8601String();
            $this->reservationOperatorName = $reservation->operator()->value('name') ?? Auth::user()->name;

            if ($this->hasExpedient() && $this->mode === 'reply' && $this->expedient->phone_validated_at === null) {
                $missingPhone = Template::forPurpose('missing_phone')->active()->first();

                if ($missingPhone !== null) {
                    $this->templateId = $missingPhone->id;
                    $this->body = $missingPhone->body ?? '';
                }
            }
        } catch (AuthorizationException $exception) {
            $this->closeAfterPreparationFailure($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->closeAfterPreparationFailure(__('No se pudo preparar el compositor de correo.'));
        }
    }

    #[Computed]
    public function account(): MailAccount
    {
        abort_if($this->accountId === null, 404);

        /** @var User $actor */
        $actor = Auth::user();

        return $actor->accessibleMailAccounts()
            ->where('is_active', true)
            ->findOrFail($this->accountId);
    }

    #[Computed]
    public function expedient(): Expedient
    {
        abort_if($this->expedientId === null, 404);

        $expedient = Expedient::query()
            ->with('mailAccount')
            ->findOrFail($this->expedientId);

        abort_unless($expedient->mailAccount?->isAccessibleBy(Auth::user()), 403);

        return $expedient;
    }

    #[Computed]
    public function origin(): MailMessage
    {
        abort_if($this->originMessageId === null || ! $this->hasExpedient(), 404);

        return MailMessage::query()
            ->whereKey($this->originMessageId)
            ->where('case_id', $this->expedientId)
            ->where('mail_account_id', $this->account->id)
            ->firstOrFail();
    }

    #[Computed]
    public function contacts()
    {
        return Contact::query()
            ->whereNotNull('email')
            ->when($this->contactSearch, fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('name', 'like', "%{$this->contactSearch}%")
                    ->orWhere('email', 'like', "%{$this->contactSearch}%")))
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function templates()
    {
        return Template::active()->orderBy('name')->get();
    }

    #[Computed]
    public function signatures()
    {
        return $this->account->signatures()
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function hasExpedient(): bool
    {
        return $this->expedientId !== null;
    }

    /** Completes the recipient field with a selected contact. */
    public function updatedContactId(?int $contactId): void
    {
        if ($contactId === null) {
            return;
        }

        $contact = Contact::query()->whereNotNull('email')->findOrFail($contactId);
        $this->to = $contact->email;
        $this->resetErrorBag('to');
    }

    /** Applies a selected template only after explicit operator confirmation. */
    public function applyTemplate(): void
    {
        if ($this->templateId === null) {
            return;
        }

        $template = Template::active()->findOrFail($this->templateId);
        $this->body = $template->body ?? '';

        if (blank($this->subject) && filled($template->subject)) {
            $this->subject = $template->subject;
        }

        $this->resetErrorBag(['body', 'subject']);
    }

    /** Defines the client-side validation surface; the service repeats every check. */
    protected function rules(): array
    {
        $rules = [
            'to' => ['required', 'email'],
            'cc' => ['nullable', 'string'],
            'bcc' => ['nullable', 'string'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'templateId' => ['nullable', 'integer', 'exists:templates,id'],
            'signatureId' => ['nullable', 'integer'],
        ];

        if ($this->hasExpedient()) {
            $rules['stateDeadline'] = ['nullable', 'date', 'after:now'];
        }

        return $rules;
    }

    /** Validates the form for UX, then delegates all delivery work to the backend service. */
    public function send(OutboundMailService $outbound): void
    {
        abort_unless(Auth::user()->can('bandeja.clasificar') || Auth::user()->can('expedientes.actualizar'), 403);

        $validated = $this->validate();
        $recipientLists = $this->recipientLists($validated['cc'], $validated['bcc']);
        $invalidRecipientFields = $this->invalidRecipientFields($recipientLists);

        foreach ($invalidRecipientFields as $field) {
            $this->addError($field, $this->invalidRecipientMessage());
        }

        if ($invalidRecipientFields !== []) {
            return;
        }

        $cc = $recipientLists['cc'];
        $bcc = $recipientLists['bcc'];

        $this->sending = true;

        try {
            $outbound->send($this->context(
                recipient: $validated['to'],
                cc: $cc,
                bcc: $bcc,
                subject: $validated['subject'],
                body: $validated['body'],
                deadline: $this->deadline(),
            ));
        } catch (AuthorizationException) {
            $this->sending = false;
            $this->expireReservation();
            Flux::toast(variant: 'danger', text: __('La reserva de este mensaje venció o pertenece a otro operador.'));

            return;
        } catch (Throwable $exception) {
            $this->sending = false;
            report($exception);
            Log::withContext([
                'mail_account_id' => $this->accountId,
                'mode' => $this->mode,
                'recipient_domain' => str($validated['to'])->afterLast('@')->toString(),
                'recipient_count' => 1 + count($cc) + count($bcc),
            ]);
            Flux::toast(variant: 'danger', text: __('No se pudo enviar el correo. Verificá la configuración SMTP e intentá nuevamente.'));

            return;
        }

        $this->sending = false;
        $message = $this->hasExpedient()
            ? ($this->mode === 'reply' ? __('Respuesta enviada al cliente.') : __('Reenvío enviado al proveedor.'))
            : ($this->mode === 'reply' ? __('Respuesta enviada.') : __('Reenvío enviado.'));

        $this->open = false;
        $this->dispatch('outbound-mail-sent');
        Flux::toast(variant: 'success', text: $message);
    }

    public function updatedOpen(bool $open): void
    {
        if (! $open) {
            $this->resetForm();
            $this->dispatch('outbound-mail-composer-closed');
        }
    }

    public function closeComposer(): void
    {
        $this->open = false;
    }

    /** Discards the transient form after its fixed reservation expires. */
    public function expireReservation(): void
    {
        $this->open = false;
        $this->resetForm();
    }

    private function context(
        string $recipient = '',
        array $cc = [],
        array $bcc = [],
        string $subject = '',
        string $body = '',
        ?Carbon $deadline = null,
    ): OutboundMailContext {
        abort_if($this->accountId === null || $this->folder === null || $this->imapUid === null || $this->mode === null, 404);

        /** @var User $actor */
        $actor = Auth::user();

        if ($this->hasExpedient()) {
            return OutboundMailContext::fromExpedient(
                account: $this->account,
                actor: $actor,
                mode: $this->mode,
                folder: $this->folder,
                imapUid: $this->imapUid,
                expedient: $this->expedient,
                origin: $this->origin,
                recipient: $recipient,
                cc: $cc,
                bcc: $bcc,
                subject: $subject,
                body: $body,
                signature: null,
                deadline: $deadline,
                signatureId: $this->signatureId,
            );
        }

        return OutboundMailContext::fromInbox(
            account: $this->account,
            actor: $actor,
            mode: $this->mode,
            folder: $this->folder,
            imapUid: $this->imapUid,
            origin: $this->originData,
            recipient: $recipient,
            cc: $cc,
            bcc: $bcc,
            subject: $subject,
            body: $body,
            signature: null,
            deadline: $deadline,
            signatureId: $this->signatureId,
        );
    }

    private function deadline(): ?Carbon
    {
        if (! $this->hasExpedient() || blank($this->stateDeadline)) {
            return null;
        }

        return Carbon::parse($this->stateDeadline);
    }

    /**
     * @return array{cc: array<int, string>, bcc: array<int, string>}
     */
    private function recipientLists(string $cc, string $bcc): array
    {
        return [
            'cc' => $this->recipientList($cc),
            'bcc' => $this->recipientList($bcc),
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $recipientLists
     * @return array<int, string>
     */
    private function invalidRecipientFields(array $recipientLists): array
    {
        return array_keys(array_filter(
            $recipientLists,
            fn (array $recipients): bool => collect($recipients)->contains(
                fn (string $recipient): bool => filter_var($recipient, FILTER_VALIDATE_EMAIL) === false,
            ),
        ));
    }

    private function invalidRecipientMessage(): string
    {
        return __('Ingresá direcciones de correo válidas separadas por comas.');
    }

    /**
     * @return array<int, string>
     */
    private function recipientList(string $recipients): array
    {
        return array_values(array_filter(array_map(
            fn (string $recipient): string => trim($recipient),
            explode(',', $recipients),
        ), fn (string $recipient): bool => $recipient !== ''));
    }

    private function closeAfterPreparationFailure(string $message): void
    {
        $this->open = false;
        $this->resetForm();
        Flux::toast(variant: 'danger', text: __($message));
        $this->dispatch('outbound-mail-composer-closed');
    }

    private function resetForm(): void
    {
        $this->reset([
            'to',
            'cc',
            'bcc',
            'subject',
            'body',
            'contactId',
            'contactSearch',
            'templateId',
            'signatureId',
            'stateDeadline',
            'reservationExpiresAt',
            'reservationOperatorName',
        ]);
        $this->resetErrorBag();
    }
};
?>

<div>
    @if ($open && $mode !== null)
        <flux:modal name="mail-composer" wire:model.self="open" class="h-[min(90vh,56rem)] w-[min(96vw,72rem)] min-w-[20rem] max-w-[96vw] resize overflow-auto">
        <form
            wire:submit="send"
            class="flex h-full min-h-[30rem] flex-col gap-4"
            x-data="{ expiresAt: Date.parse(@js($reservationExpiresAt)), remaining: 0, expired: false, interval: null, update() { this.remaining = Math.max(0, Math.ceil((this.expiresAt - Date.now()) / 1000)); if (this.remaining === 0 && ! this.expired) { this.expired = true; clearInterval(this.interval); $wire.expireReservation(); } }, init() { this.update(); this.interval = setInterval(() => this.update(), 1000); }, destroy() { clearInterval(this.interval); } }"
        >
            <flux:heading size="lg">
                @if ($this->hasExpedient())
                    {{ $mode === 'reply' ? __('Responder al cliente') : __('Reenviar al proveedor') }}
                @else
                    {{ $mode === 'reply' ? __('Responder') : __('Reenviar') }}
                @endif
            </flux:heading>

            @if ($this->hasExpedient())
                <flux:text variant="subtle" size="sm">{{ __('Expediente') }}: {{ $this->expedient->case_number }}</flux:text>
            @endif
            <flux:text variant="subtle" size="sm">
                {{ __('Gestionado por') }} {{ $reservationOperatorName }} · {{ __('Tiempo restante') }}: <span x-text="remaining"></span>s
            </flux:text>

            <flux:field>
                <flux:label>{{ __('Contacto') }}</flux:label>
                <flux:input wire:model.live.debounce.300ms="contactSearch" :placeholder="__('Buscar por nombre o email...')" />
                <flux:select wire:model.live="contactId">
                    <flux:select.option value="">{{ __('Ingresar email manualmente') }}</flux:select.option>
                    @foreach ($this->contacts as $contact)
                        <flux:select.option wire:key="outbound-contact-{{ $contact->id }}" value="{{ $contact->id }}">{{ $contact->name }} ({{ $contact->email }})</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Para') }}</flux:label>
                <flux:input wire:model="to" type="email" :placeholder="__('correo@ejemplo.com')" />
                <flux:error name="to" />
            </flux:field>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('CC') }}</flux:label>
                    <flux:input wire:model="cc" type="text" :placeholder="__('correo@ejemplo.com, otro@ejemplo.com')" />
                    <flux:error name="cc" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('CCO') }}</flux:label>
                    <flux:input wire:model="bcc" type="text" :placeholder="__('correo@ejemplo.com, otro@ejemplo.com')" />
                    <flux:error name="bcc" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Plantilla') }}</flux:label>
                <div class="flex gap-2">
                    <flux:select wire:model="templateId" class="min-w-0 flex-1">
                        <flux:select.option value="">{{ __('Sin plantilla') }}</flux:select.option>
                        @foreach ($this->templates as $template)
                            <flux:select.option wire:key="outbound-template-{{ $template->id }}" value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="button" wire:click="applyTemplate" wire:target="applyTemplate" :disabled="$templateId === null" variant="ghost">
                        {{ __('Aplicar') }}
                    </flux:button>
                </div>
                <flux:text size="sm" variant="subtle">{{ __('Aplicar reemplaza el cuerpo sólo cuando lo confirmás.') }}</flux:text>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Asunto') }}</flux:label>
                <flux:input wire:model="subject" />
                <flux:error name="subject" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Mensaje') }}</flux:label>
                <flux:textarea wire:model="body" rows="12" resize="both" class="min-h-48 flex-1" />
                <flux:error name="body" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Firma') }}</flux:label>
                <flux:select wire:model="signatureId">
                    <flux:select.option value="">{{ __('Sin firma') }}</flux:select.option>
                    @foreach ($this->signatures as $signature)
                        <flux:select.option wire:key="outbound-signature-{{ $signature->id }}" value="{{ $signature->id }}">{{ $signature->name }}{{ $signature->is_default ? ' ('.__('predeterminada').')' : '' }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="signatureId" />
            </flux:field>

            @if ($this->hasExpedient())
                <flux:field>
                    <flux:label>{{ __('Vencimiento del estado') }}</flux:label>
                    <flux:input wire:model="stateDeadline" type="datetime-local" />
                    <flux:error name="stateDeadline" />
                </flux:field>
            @endif

            <div class="mt-auto flex justify-end gap-2">
                <flux:button type="button" wire:click="closeComposer" variant="ghost">{{ __('Cancelar') }}</flux:button>
                <flux:button type="submit" wire:loading.attr="disabled" wire:target="send" x-bind:disabled="expired" variant="primary" icon="paper-airplane">
                    <span wire:loading.remove wire:target="send">{{ __('Enviar') }}</span>
                    <span wire:loading wire:target="send">{{ __('Enviando...') }}</span>
                </flux:button>
            </div>
        </form>
        </flux:modal>
    @endif
</div>
