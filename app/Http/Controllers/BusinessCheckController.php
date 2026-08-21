<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\SaveBusinessCheck;
use App\Http\Requests\ClientFolders\SaveBusinessCheckRequest;
use App\Models\BusinessCheck;
use App\Models\BusinessCheckPhoto;
use App\Models\ClientFolder;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BusinessCheckController extends Controller
{
    public function create(ClientFolder $clientFolder): View
    {
        Gate::authorize('update', $clientFolder);

        return $this->form($clientFolder, null);
    }

    public function edit(ClientFolder $clientFolder, BusinessCheck $businessCheck): View
    {
        Gate::authorize('update', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($businessCheck, $activePerson);
        $businessCheck->load(['photos', 'incomeSource']);

        return $this->form($clientFolder, $businessCheck);
    }

    public function store(SaveBusinessCheckRequest $request, ClientFolder $clientFolder, SaveBusinessCheck $save): RedirectResponse
    {
        $save->execute($request->user(), $clientFolder, $request->validated());
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id')));

        return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + $personParams)->with('status', 'Business Check saved successfully.');
    }

    public function destroy(ClientFolder $clientFolder, BusinessCheck $businessCheck, DeleteBusinessCheck $delete): RedirectResponse
    {
        Gate::authorize('update', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($businessCheck, $activePerson);
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $delete->execute(request()->user(), $clientFolder, $businessCheck);

        return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + $personParams)->with('status', 'Business Check deleted successfully.');
    }

    public function photo(ClientFolder $clientFolder, BusinessCheck $businessCheck, BusinessCheckPhoto $photo): StreamedResponse
    {
        Gate::authorize('view', $clientFolder);
        $thumbnail = request()->boolean('thumbnail') && filled($photo->thumbnail_path);
        $path = $thumbnail ? $photo->thumbnail_path : $photo->path;
        $disk = Storage::disk(config('cims.media_disk'));
        abort_unless(filled($path) && $disk->exists($path), 404);

        return $disk->response($path, $photo->file_name, [
            'Content-Type' => $thumbnail ? 'image/jpeg' : $photo->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function form(ClientFolder $clientFolder, ?BusinessCheck $businessCheck): View
    {
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $personName = $activePerson?->full_name ?? $clientFolder->display_name;

        $businesses = $clientFolder->incomeSources()
            ->where('co_maker_id', $activePerson?->id)
            ->with(['businessReport:id,income_source_id,main_business_address', 'template'])
            ->whereHas('template', fn ($query) => $query->where('is_fallback', false)->where('form_handler', 'dedicated-business'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($source) => ['id' => $source->id, 'name' => $source->displayName(), 'location' => $source->businessReport?->main_business_address]);

        $mapQuery = $businessCheck?->google_maps_link ?: $businessCheck?->location;
        $photos = $businessCheck?->photos ?? collect();

        $mapPhotos = fn (string $category) => $photos->filter(fn (BusinessCheckPhoto $photo) => $photo->category?->value === $category)->map(fn (BusinessCheckPhoto $photo) => [
            'id' => $photo->id,
            'url' => route('client-folders.business-checks.photo', [$clientFolder, $businessCheck, $photo, 'thumbnail' => 1]),
            'caption' => $photo->caption,
        ])->values()->all();

        return view('client-folders.business-checks.form', [
            'clientFolder' => $clientFolder,
            'activePerson' => $activePerson,
            'personName' => $personName,
            'businessCheck' => $businessCheck,
            'businesses' => $businesses,
            'existingBusinessPhotos' => $businessCheck ? $mapPhotos('business') : [],
            'existingCompetitorPhotos' => $businessCheck ? $mapPhotos('competitor') : [],
            'mapEmbedSrc' => $mapQuery ? 'https://www.google.com/maps?q='.urlencode($mapQuery).'&output=embed' : null,
            'mapOpenLink' => $mapQuery ? 'https://www.google.com/maps?q='.urlencode($mapQuery) : null,
        ]);
    }
}
