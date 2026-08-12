<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\SaveClientInformation;
use App\Enums\AddressType;
use App\Http\Requests\ClientFolders\UpdateClientInformationRequest;
use App\Models\ClientFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClientInformationController extends Controller
{
    public function edit(ClientFolder $clientFolder): View
    {
        Gate::authorize('update', $clientFolder);
        $clientFolder->load(['information', 'addresses']);

        return view('client-folders.client-information.edit', [
            'clientFolder' => $clientFolder,
            'information' => $clientFolder->information,
            'addresses' => $clientFolder->addresses->keyBy(fn ($address): string => $address->address_type->value),
            'addressTypes' => AddressType::cases(),
        ]);
    }

    public function update(UpdateClientInformationRequest $request, ClientFolder $clientFolder, SaveClientInformation $save): RedirectResponse
    {
        $save->execute($request->user(), $clientFolder, $request->validated());

        $destination = $request->string('intent')->toString() === 'return'
            ? route('client-folders.show', $clientFolder)
            : route('client-folders.client-information.edit', $clientFolder);

        return redirect($destination)->with('status', 'Client information saved successfully.');
    }
}
