<?php

declare(strict_types=1);

namespace App\Livewire\Partners;

use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\ChangePartnerStatusAction;
use App\Actions\Partners\RevokePartnerProjectAuthorizationAction;
use App\Enums\PartnerStatus;
use App\Enums\PromoterLedgerEntryType;
use App\Exceptions\DomainException;
use App\Models\Partner;
use App\Models\PartnerProjectAuthorization;
use App\Models\Project;
use App\Services\Commission\PromoterLedgerService;
use App\Services\Documents\DocumentChecklistService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PartnerShow extends Component
{
    public Partner $partner;

    public string $authorizeProjectId = '';

    public bool $revealSensitive = false;

    public string $advanceAmount = '';

    public string $advanceNote = '';

    public string $refundAmount = '';

    public string $refundReason = '';

    public function mount(Partner $partner): void
    {
        $this->authorize('view', $partner);
        $this->partner = $partner;
    }

    private function refresh(): void
    {
        $this->partner = $this->partner->fresh();
    }

    public function changeStatus(string $status): void
    {
        $this->authorize('changeStatus', $this->partner);

        $target = PartnerStatus::tryFrom($status);
        if ($target === null) {
            return;
        }

        if (in_array($target, [PartnerStatus::Active], true) && ! auth()->user()->can('approve', $this->partner)) {
            $this->dispatch('toast', message: 'You are not authorised to approve partners.', variant: 'danger');

            return;
        }

        try {
            app(ChangePartnerStatusAction::class)->handle($this->partner, $target, auth()->user());
            $this->refresh();
            $this->dispatch('toast', message: "Status set to {$target->label()}.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function authorizeProject(): void
    {
        $this->authorize('authorizeProjects', $this->partner);
        $this->validate(['authorizeProjectId' => ['required', 'integer', 'exists:projects,id']]);

        $project = Project::findOrFail((int) $this->authorizeProjectId);

        try {
            app(AuthorizePartnerForProjectAction::class)->handle($this->partner, $project, auth()->user());
            $this->reset('authorizeProjectId');
            $this->refresh();
            $this->dispatch('toast', message: "Authorised for {$project->name}.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function revokeProject(int $authorizationId): void
    {
        $this->authorize('authorizeProjects', $this->partner);

        $auth = PartnerProjectAuthorization::query()
            ->where('partner_id', $this->partner->id)
            ->findOrFail($authorizationId);

        try {
            app(RevokePartnerProjectAuthorizationAction::class)->handle($auth, auth()->user());
            $this->refresh();
            $this->dispatch('toast', message: 'Authorisation revoked.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function toggleSensitive(): void
    {
        $this->authorize('viewSensitive', $this->partner);
        $this->revealSensitive = ! $this->revealSensitive;
    }

    /** "Add Advance" (§11) — a fresh ledger transaction; the original advance row is never edited. */
    public function giveAdvance(): void
    {
        $this->authorize('update', $this->partner);
        $this->validate([
            'advanceAmount' => ['required', 'numeric', 'gt:0'],
            'advanceNote' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            app(PromoterLedgerService::class)->giveAdvance(
                $this->partner,
                Money::of($this->advanceAmount),
                $this->advanceNote !== '' ? $this->advanceNote : 'Advance given.',
                auth()->user(),
            );
            $this->reset('advanceAmount', 'advanceNote');
            $this->refresh();
            $this->dispatch('toast', message: 'Advance recorded.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    /** A simple, controlled advance refund / adjustment (§12) — never exceeds the current balance. */
    public function refundAdvance(): void
    {
        $this->authorize('update', $this->partner);
        $this->validate([
            'refundAmount' => ['required', 'numeric', 'gt:0'],
            'refundReason' => ['required', 'string', 'max:500'],
        ]);

        try {
            app(PromoterLedgerService::class)->refundAdvance(
                $this->partner,
                Money::of($this->refundAmount),
                $this->refundReason,
                auth()->user(),
            );
            $this->reset('refundAmount', 'refundReason');
            $this->refresh();
            $this->dispatch('toast', message: 'Advance adjustment recorded.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    /**
     * The Promoter detail dashboard figures (§6) — everything except the
     * advance balance is a straightforward sum; the balance itself is always
     * derived from the ledger (see {@see PromoterLedgerService::advanceBalance()}).
     *
     * @return array<string, Money>
     */
    private function promoterStats(Partner $partner): array
    {
        $openCases = $partner->commissionCases()->with('booking:id,final_amount')->get()
            ->filter(fn ($c) => $c->status->isOpen());

        $totalBookingValue = $openCases->reduce(
            fn (Money $sum, $c) => $sum->plus(Money::of((string) ($c->booking->final_amount ?? '0'))),
            Money::zero(),
        );
        $totalCommissionEarned = $openCases->reduce(
            fn (Money $sum, $c) => $sum->plus(Money::of((string) $c->commission_amount)),
            Money::zero(),
        );
        $totalCommissionPayable = $openCases->reduce(
            fn (Money $sum, $c) => $sum->plus(Money::of($c->outstandingAmount())),
            Money::zero(),
        );
        $totalCommissionPaid = Money::of((string) $partner->commissionCases()->sum('paid_amount'));

        $advanceGiven = Money::zero();
        $advanceRefunded = Money::zero();
        $advanceAdjusted = Money::zero();
        $advanceAdjustedReversed = Money::zero();

        foreach ($partner->ledgerEntries as $entry) {
            match ($entry->type) {
                PromoterLedgerEntryType::AdvanceGiven
                    => $advanceGiven = $advanceGiven->plus(Money::of((string) $entry->advance_amount)),
                PromoterLedgerEntryType::AdvanceRefunded
                    => $advanceRefunded = $advanceRefunded->plus(Money::of((string) $entry->advance_amount)),
                PromoterLedgerEntryType::Commission
                    => $advanceAdjusted = $advanceAdjusted->plus(Money::of((string) ($entry->adjustment_amount ?? '0'))),
                PromoterLedgerEntryType::AdvanceAdjustmentReversed
                    => $advanceAdjustedReversed = $advanceAdjustedReversed->plus(Money::of((string) $entry->advance_amount)),
                default => null,
            };
        }

        return [
            'totalBookingValue' => $totalBookingValue,
            'totalCommissionEarned' => $totalCommissionEarned,
            'totalAdvanceGiven' => $advanceGiven,
            'totalAdvanceRefunded' => $advanceRefunded,
            'totalAdvanceAdjusted' => $advanceAdjusted->minus($advanceAdjustedReversed),
            'advanceBalance' => app(PromoterLedgerService::class)->advanceBalance($partner),
            'totalCommissionPayable' => $totalCommissionPayable,
            'totalCommissionPaid' => $totalCommissionPaid,
        ];
    }

    public function render(): View
    {
        $partner = $this->partner->load([
            'state', 'city', 'createdBy', 'approvedBy',
            'projectAuthorizations.project', 'projectAuthorizations.authorizedBy', 'projectAuthorizations.revokedBy',
            'activities.causer',
            'ledgerEntries.createdBy', 'ledgerEntries.booking:id,booking_number',
        ])->loadCount(['bookingAttributions']);

        $recentBookings = $partner->bookingAttributions()
            ->with('booking:id,booking_number,status,final_amount,project_id')
            ->take(5)->get();

        $authorizedProjectIds = $partner->projectAuthorizations
            ->where('status', 'active')->pluck('project_id')->all();

        return view('livewire.partners.partner-show', [
            'partner' => $partner,
            'stats' => $this->promoterStats($partner),
            'recentBookings' => $recentBookings,
            'kyc' => app(DocumentChecklistService::class)->forPartner($partner),
            'allowedTransitions' => $partner->status->allowedTransitions(),
            'assignableProjects' => auth()->user()->can('authorizeProjects', $partner)
                ? Project::query()->whereNotIn('id', $authorizedProjectIds)->orderBy('name')->pluck('name', 'id')
                : collect(),
            'canRevealSensitive' => auth()->user()->can('viewSensitive', $partner),
        ])->title($partner->displayName());
    }
}
