<?php

declare(strict_types=1);

namespace App\Livewire\Partners;

use App\Actions\Commission\AssignCommissionSchemeToPartner;
use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\ChangePartnerStatusAction;
use App\Actions\Partners\RevokePartnerProjectAuthorizationAction;
use App\Enums\PartnerStatus;
use App\Exceptions\DomainException;
use App\Models\CommissionScheme;
use App\Models\Partner;
use App\Models\PartnerProjectAuthorization;
use App\Models\Project;
use App\Services\Documents\DocumentChecklistService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PartnerShow extends Component
{
    public Partner $partner;

    public string $authorizeProjectId = '';

    public string $schemeCode = '';

    public bool $revealSensitive = false;

    public function mount(Partner $partner): void
    {
        $this->authorize('view', $partner);
        $this->partner = $partner;
        $this->schemeCode = (string) ($partner->commission_scheme_code ?? '');
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

    public function assignScheme(): void
    {
        $this->authorize('assignToPartner', CommissionScheme::class);

        try {
            app(AssignCommissionSchemeToPartner::class)->handle($this->partner, $this->schemeCode ?: null, auth()->user());
            $this->refresh();
            $this->dispatch('toast', message: 'Commission scheme updated.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $partner = $this->partner->load([
            'state', 'city', 'createdBy', 'approvedBy',
            'projectAuthorizations.project', 'projectAuthorizations.authorizedBy', 'projectAuthorizations.revokedBy',
            'activities.causer',
        ])->loadCount(['leads', 'bookingAttributions']);

        $recentLeads = $partner->leads()->take(5)->get(['id', 'name', 'phone', 'status']);
        $recentBookings = $partner->bookingAttributions()
            ->with('booking:id,booking_number,status,final_amount,project_id')
            ->take(5)->get();

        $authorizedProjectIds = $partner->projectAuthorizations
            ->where('status', 'active')->pluck('project_id')->all();

        return view('livewire.partners.partner-show', [
            'partner' => $partner,
            'recentLeads' => $recentLeads,
            'recentBookings' => $recentBookings,
            'kyc' => app(DocumentChecklistService::class)->forPartner($partner),
            'allowedTransitions' => $partner->status->allowedTransitions(),
            'assignableProjects' => auth()->user()->can('authorizeProjects', $partner)
                ? Project::query()->whereNotIn('id', $authorizedProjectIds)->orderBy('name')->pluck('name', 'id')
                : collect(),
            'canRevealSensitive' => auth()->user()->can('viewSensitive', $partner),
            'canAssignScheme' => auth()->user()->can('assignToPartner', CommissionScheme::class),
            'publishedSchemes' => auth()->user()->can('assignToPartner', CommissionScheme::class)
                ? CommissionScheme::query()->published()->orderBy('name')->get(['code', 'name'])->unique('code')
                : collect(),
        ])->title($partner->displayName());
    }
}
