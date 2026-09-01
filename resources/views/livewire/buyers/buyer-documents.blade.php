<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Buyers', 'url' => route('buyers.index')],
        ['label' => $buyer->fullName(), 'url' => route('buyers.show', $buyer)],
        ['label' => 'Documents'],
    ]" />

    <x-ui.page-header :title="'KYC documents — '.$buyer->fullName()"
        description="Buyer identity & address documents. Verification status and version history are kept." />

    <x-ui.card>
        <x-documents.checklist :checklist="$checklist" :documents="$documents" :rejecting-id="$rejectingId" component-id="buyer" />
    </x-ui.card>
</div>
