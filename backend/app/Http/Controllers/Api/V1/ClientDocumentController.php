<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessScope;
use App\Domain\Audit\AuditService;
use App\Domain\Evidence\EvidenceStorage;
use App\Models\Client;
use App\Models\ClientDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Internal client documents (contracts, briefs). Private disk; downloads are authorised and audited. */
class ClientDocumentController extends ApiController
{
    public function __construct(private EvidenceStorage $storage) {}

    public function index(Request $request, string $client): JsonResponse
    {
        $c = $this->client($request, $client);
        $this->authorize('view', $c);

        return $this->ok(ClientDocument::query()->where('client_id', $c->id)->orderByDesc('created_at')->get());
    }

    public function store(Request $request, string $client): JsonResponse
    {
        $c = $this->client($request, $client);
        $this->authorize('update', $c);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:'.config('field.uploads.max_kb')],
        ]);
        $stored = $this->storage->storeDocument($request->file('file'), 'clients/'.$c->id);
        $doc = ClientDocument::query()->create([
            'client_id' => $c->id, 'title' => $data['title'], 'disk' => config('field.uploads.disk'), 'path' => $stored['path'],
            'mime' => $stored['mime'], 'size_bytes' => $stored['size'], 'uploaded_by' => $request->user()->getKey(),
        ]);

        return $this->created($doc);
    }

    public function download(Request $request, string $client, string $id): StreamedResponse
    {
        $c = $this->client($request, $client);
        $this->authorize('view', $c);
        $this->assertUuid($id);
        $doc = ClientDocument::query()->where('client_id', $c->id)->findOrFail($id);
        abort_unless(Storage::disk($doc->disk)->exists($doc->path), 404);
        AuditService::record('client_document.downloaded', 'clients', $doc);

        $ext = pathinfo($doc->path, PATHINFO_EXTENSION);

        return Storage::disk($doc->disk)->download($doc->path, preg_replace('/[^\w.\- ]/u', '_', $doc->title).'.'.$ext, [
            'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $doc->mime,
        ]);
    }

    public function destroy(Request $request, string $client, string $id): JsonResponse
    {
        $c = $this->client($request, $client);
        $this->authorize('update', $c);
        $this->assertUuid($id);
        $doc = ClientDocument::query()->where('client_id', $c->id)->findOrFail($id);
        Storage::disk($doc->disk)->delete($doc->path);
        $doc->delete();

        return $this->ok(null, 'Deleted successfully.');
    }

    private function client(Request $request, string $id): Client
    {
        $this->assertUuid($id);

        return AccessScope::scope(Client::query(), $request->user())->findOrFail($id);
    }
}
