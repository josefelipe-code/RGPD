<?php

namespace App\Services\Expedientes;

use App\Enums\CaseStatus;
use App\Enums\MilestoneAction;
use App\Models\Expedient;
use App\Models\ExpedientState;
use App\Models\MailAccount;
use App\Models\SharedIncident;
use App\Models\User;
use App\Services\Bandeja\ImapMailboxService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpedientStateService
{
    public function __construct(private readonly ImapMailboxService $mailbox) {}

    /** @return array<string, ExpedientState> */
    public function ensureDefaults(MailAccount $account): array
    {
        return collect([
            'pending_client' => ['name' => 'Pending client', 'is_final' => false],
            'pending_provider' => ['name' => 'Pending provider', 'is_final' => false],
            'concluded' => ['name' => 'Concluded', 'is_final' => true],
        ])->mapWithKeys(fn (array $attributes, string $key): array => [$key => $account->expedientStates()->firstOrCreate(['key' => $key], $attributes)])->all();
    }

    public function save(MailAccount $account, User $actor, ?ExpedientState $state, array $attributes, ?string $newFolder = null): ExpedientState
    {
        $this->authorizeConfiguration($actor);

        if ($state !== null && $state->mail_account_id !== $account->id) {
            $this->authorizeConfiguration($actor);

            if ($state->is_final || $state->expedients()->exists()) {
                throw ValidationException::withMessages(['selectedMailAccountId' => 'A final or used state cannot be moved to another mail account.']);
            }
        }

        $folder = $attributes['imap_folder'] ?? null;

        if (filled($newFolder)) {
            $folder = $this->mailbox->createFolder($account, $newFolder);
        }

        if (filled($folder) && ! $this->mailbox->listFolders($account)->contains('path', $folder)) {
            throw ValidationException::withMessages(['imap_folder' => 'The selected IMAP folder no longer exists.']);
        }

        if (($attributes['is_final'] ?? false) && $account->expedientStates()->where('is_final', true)->when($state, fn ($query) => $query->where('id', '!=', $state->id))->exists()) {
            throw ValidationException::withMessages(['is_final' => 'Each mail account can have only one final state.']);
        }

        return DB::transaction(function () use ($account, $state, $attributes, $folder): ExpedientState {
            $payload = [...$attributes, 'mail_account_id' => $account->id, 'imap_folder' => $folder];

            return $state === null
                ? $account->expedientStates()->create($payload)
                : tap($state)->update($payload);
        });
    }

    public function delete(MailAccount $account, User $actor, ExpedientState $state): void
    {
        $this->authorizeConfiguration($actor);

        if ($state->mail_account_id !== $account->id) {
            throw new \LogicException('The state does not belong to the mail account.');
        }

        if ($state->is_final) {
            throw ValidationException::withMessages(['state' => 'The final state cannot be deleted.']);
        }

        if ($state->expedients()->exists()) {
            throw ValidationException::withMessages(['state' => 'A state in use cannot be deleted.']);
        }

        $state->delete();
    }

    public function transition(Expedient $expedient, ExpedientState $state, User $actor, ?CarbonInterface $deadline = null): void
    {
        if ($expedient->mail_account_id !== $state->mail_account_id) {
            throw new \LogicException('The state does not belong to the expedient mail account.');
        }

        $this->authorizeOwnedAccount($expedient->mailAccount, $actor);
        $expedient->loadMissing('imapMessageReferences');

        if (! $state->is_final && filled($state->imap_folder)) {
            foreach ($expedient->imapMessageReferences as $reference) {
                $reference->update([
                    'reconciliation_status' => 'moving',
                    'reconciliation_target_folder' => $state->imap_folder,
                    'reconciliation_error' => null,
                ]);

                try {
                    $moved = $this->mailbox->moveMessage($expedient->mailAccount, $reference->folder, (int) $reference->imap_uid, $state->imap_folder);
                    $reference->update([
                        'folder' => $moved['folder'],
                        'imap_uid' => (string) $moved['uid'],
                        'reconciliation_status' => null,
                        'reconciliation_target_folder' => null,
                    ]);
                } catch (\Throwable $exception) {
                    $reference->update(['reconciliation_status' => 'failed', 'reconciliation_error' => $exception->getMessage()]);

                    try {
                        SharedIncident::reportImapReconciliationFailure($expedient, $reference->id, $state->id);
                    } catch (\Throwable $incidentException) {
                        report($incidentException);
                    }

                    throw $exception;
                }
            }
        }

        DB::transaction(function () use ($expedient, $state, $actor, $deadline): void {
            $expedient->forceFill([
                'expedient_state_id' => $state->id,
                // Legacy status remains a compatibility shadow for deployed integrations.
                'status' => $state->is_final ? CaseStatus::Concluded : CaseStatus::PendingClient,
                'closed_at' => $state->is_final ? now() : null,
                'state_deadline' => $state->is_final ? null : $deadline,
            ])->save();
            $expedient->milestones()->create(['user_id' => $actor->id, 'action' => MilestoneAction::StateChanged, 'notes' => $state->name]);
        });
    }

    private function authorizeConfiguration(User $actor): void
    {
        if (! $actor->can('expedientes.ver')) {
            throw new AuthorizationException('You do not have access to expedient configuration.');
        }
    }

    private function authorizeOwnedAccount(MailAccount $account, User $actor): void
    {
        if ($account->user_id !== $actor->id) {
            throw new AuthorizationException('You do not own this mail account.');
        }
    }
}
