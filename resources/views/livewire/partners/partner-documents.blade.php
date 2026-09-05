<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Channel Partners', 'url' => route('partners.index')],
        ['label' => $partner->displayName(), 'url' => route('partners.show', $partner)],
        ['label' => 'KYC'],
    ]" />

    <x-ui.page-header :title="'KYC documents — '.$partner->displayName()"
        description="Channel-partner identity, bank and agreement documents. Files are private; verification status and version history are kept." />

    <x-ui.card>
        <x-documents.checklist :checklist="$checklist" :documents="$documents" :rejecting-id="$rejectingId" component-id="partner" />
    </x-ui.card>
</div>
