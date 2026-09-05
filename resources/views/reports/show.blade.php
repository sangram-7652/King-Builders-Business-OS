@php
    /** @var string $report */
    /** @var array{title:string,description:string,milestone:string,route:string} $meta */
    /** @var array<string, array<string,string>> $reports */
    /** @var \App\Support\Reports\ReportFilterData $filters */
    /** @var \App\Support\Reports\FilterOptions $options */
    /** @var bool $canExport */
@endphp

<x-layouts.app :title="$meta['title']" :breadcrumbs="[['label' => 'Reports', 'url' => route('reports.overview')], ['label' => $meta['title']]]">

    <x-ui.page-header :title="$meta['title']" :description="$meta['description']" />

    <x-reports.tabs :reports="$reports" :active="$report" :filters="$filters" />

    <x-reports.filters :filters="$filters" :options="$options" :action="route($meta['route'])" :can-export="$canExport" />

    <x-reports.placeholder :meta="$meta" :filters="$filters" />

</x-layouts.app>
