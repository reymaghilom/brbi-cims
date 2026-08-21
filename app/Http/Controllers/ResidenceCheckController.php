<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\DeleteResidenceCheck;
use App\Actions\ClientFolders\SaveResidenceCheck;
use App\Enums\AddressType;
use App\Http\Requests\ClientFolders\SaveResidenceCheckRequest;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\ResidenceCheck;
use App\Models\ResidenceCheckPhoto;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResidenceCheckController extends Controller
{
    public function create(ClientFolder $clientFolder): View
    {
        Gate::authorize('update', $clientFolder);

        return $this->form($clientFolder, null);
    }

    public function edit(ClientFolder $clientFolder, ResidenceCheck $residenceCheck): View
    {
        Gate::authorize('update', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($residenceCheck, $activePerson);
        $residenceCheck->load('photos');

        return $this->form($clientFolder, $residenceCheck);
    }

    public function store(SaveResidenceCheckRequest $request, ClientFolder $clientFolder, SaveResidenceCheck $save): RedirectResponse
    {
        $save->execute($request->user(), $clientFolder, $request->validated());
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id')));

        return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + $personParams)->with('status', 'Residence Check saved successfully.');
    }

    public function destroy(ClientFolder $clientFolder, ResidenceCheck $residenceCheck, DeleteResidenceCheck $delete): RedirectResponse
    {
        Gate::authorize('update', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($residenceCheck, $activePerson);
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $delete->execute(request()->user(), $clientFolder, $residenceCheck);

        return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + $personParams)->with('status', 'Residence Check deleted successfully.');
    }

    public function photo(ClientFolder $clientFolder, ResidenceCheck $residenceCheck, ResidenceCheckPhoto $photo): StreamedResponse
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

    private function form(ClientFolder $clientFolder, ?ResidenceCheck $residenceCheck): View
    {
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $personName = $activePerson?->full_name ?? $clientFolder->display_name;
        $defaultLocation = $residenceCheck?->location ?? $this->defaultLocation($clientFolder, $activePerson);
        $defaultMapsLink = $residenceCheck?->google_maps_link ?? $this->defaultMapsLink($clientFolder, $activePerson);
        $mapQuery = $defaultMapsLink ?: $defaultLocation;

        $existingPhotos = ($residenceCheck?->photos ?? collect())->map(fn (ResidenceCheckPhoto $photo) => [
            'id' => $photo->id,
            'url' => route('client-folders.residence-checks.photo', [$clientFolder, $residenceCheck, $photo, 'thumbnail' => 1]),
            'caption' => $photo->caption,
        ])->all();

        return view('client-folders.residence-checks.form', [
            'clientFolder' => $clientFolder,
            'activePerson' => $activePerson,
            'personName' => $personName,
            'residenceCheck' => $residenceCheck,
            'defaultLocation' => $defaultLocation,
            'defaultMapsLink' => $defaultMapsLink,
            'existingPhotos' => $existingPhotos,
            'mapEmbedSrc' => $mapQuery ? 'https://www.google.com/maps?q='.urlencode($mapQuery).'&output=embed' : null,
            'mapOpenLink' => $mapQuery ? 'https://www.google.com/maps?q='.urlencode($mapQuery) : null,
        ]);
    }

    private function defaultLocation(ClientFolder $clientFolder, ?CoMaker $activePerson): ?string
    {
        if ($activePerson) {
            return $activePerson->address;
        }

        $address = $clientFolder->addresses()->where('address_type', AddressType::Present->value)->first();

        return $address ? collect([$address->address_line_1, $address->address_line_2, $address->barangay, $address->city_municipality, $address->province, $address->postal_code])->filter()->implode(', ') : null;
    }

    private function defaultMapsLink(ClientFolder $clientFolder, ?CoMaker $activePerson): ?string
    {
        if ($activePerson) {
            return null;
        }

        return $clientFolder->addresses()->where('address_type', AddressType::Present->value)->first()?->google_maps_link;
    }
}
