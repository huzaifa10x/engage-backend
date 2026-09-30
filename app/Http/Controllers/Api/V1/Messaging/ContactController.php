<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\Messaging\ManageContacts;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Services\ConsentService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class ContactController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'consent' => ['nullable', Rule::enum(ConsentState::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $contacts = Contact::query()
            ->when($data['q'] ?? null, function ($q, string $term) {
                $digits = preg_replace('/\D+/', '', $term);
                $q->where(fn ($w) => $w
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('profile_name', 'ilike', "%{$term}%")
                    ->orWhere('username', 'ilike', "%{$term}%")
                    ->orWhere('email', 'ilike', "%{$term}%")
                    ->when($digits !== '', fn ($w) => $w->orWhere('wa_id', 'like', "%{$digits}%")));
            })
            ->when($data['consent'] ?? null, fn ($q, string $c) => $q->where('consent_state', $c))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 50));

        return ContactResource::collection($contacts);
    }

    public function store(Request $request, ManageContacts $contacts): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'attributes' => ['nullable', 'array', 'max:50'],
            'opted_in' => ['sometimes', 'boolean'],
        ]);

        // 201 also when a previously deleted contact is restored: the client created it again.
        return ContactResource::make($contacts->create($data))->response()->setStatusCode(201);
    }

    public function show(Contact $contact): ContactResource
    {
        return ContactResource::make($contact);
    }

    public function update(Request $request, Contact $contact, ManageContacts $contacts): ContactResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'attributes' => ['sometimes', 'nullable', 'array', 'max:50'],
        ]);

        return ContactResource::make($contacts->update($contact, $data));
    }

    public function destroy(Contact $contact, ManageContacts $contacts): Response
    {
        $contacts->delete($contact);

        return response()->noContent();
    }

    /** Record consent given or withdrawn outside WhatsApp (form, call, in person). */
    public function consent(Request $request, Contact $contact, ConsentService $consent, TenantContext $context): ContactResource
    {
        $data = $request->validate([
            'state' => ['required', Rule::in([ConsentState::OptedIn->value, ConsentState::OptedOut->value])],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        $data['state'] === ConsentState::OptedIn->value
            ? $consent->optIn($contact, 'agent', $data['note'] ?? null, membershipId: $context->membership()?->id)
            : $consent->optOut($contact, 'agent', $data['note'] ?? null, membershipId: $context->membership()?->id);

        return ContactResource::make($contact->refresh());
    }
}
