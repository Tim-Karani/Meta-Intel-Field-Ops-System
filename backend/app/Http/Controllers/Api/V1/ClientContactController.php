<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientContactController extends ApiController
{
    public function index(Request $request, string $client): JsonResponse
    {
        $c = $this->client($request, $client);
        $this->authorize('view', $c);

        return $this->ok($c->hasMany(ClientContact::class)->orderByDesc('is_primary')->orderBy('name')->get());
    }

    public function store(Request $request, string $client): JsonResponse
    {
        $c = $this->client($request, $client);
        $this->authorize('update', $c);
        $contact = DB::transaction(function () use ($request, $c) {
            $data = $request->validate($this->rules());
            if (!empty($data['is_primary'])) {
                ClientContact::query()->where('client_id', $c->id)->update(['is_primary' => false]);
            }

            return ClientContact::query()->create($data + ['client_id' => $c->id]);
        });

        return $this->created($contact);
    }

    public function update(Request $request, string $client, string $id): JsonResponse
    {
        $c = $this->client($request, $client);
        $this->authorize('update', $c);
        $this->assertUuid($id);
        $contact = ClientContact::query()->where('client_id', $c->id)->findOrFail($id);
        $data = $request->validate(collect($this->rules())->map(fn ($r) => array_merge(['sometimes'], $r))->all());
        DB::transaction(function () use ($contact, $data, $c) {
            if (!empty($data['is_primary'])) {
                ClientContact::query()->where('client_id', $c->id)->where('id', '!=', $contact->id)->update(['is_primary' => false]);
            }
            $contact->update($data);
        });

        return $this->ok($contact->refresh(), 'Updated successfully.');
    }

    public function destroy(Request $request, string $client, string $id): JsonResponse
    {
        $c = $this->client($request, $client);
        $this->authorize('update', $c);
        $this->assertUuid($id);
        ClientContact::query()->where('client_id', $c->id)->findOrFail($id)->delete();

        return $this->ok(null, 'Deleted successfully.');
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:40'], 'is_primary' => ['boolean'],
        ];
    }

    private function client(Request $request, string $id): Client
    {
        $this->assertUuid($id);

        return AccessScope::scope(Client::query(), $request->user())->findOrFail($id);
    }
}
