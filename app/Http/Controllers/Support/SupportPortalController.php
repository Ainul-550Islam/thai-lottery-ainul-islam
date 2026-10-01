<?php

// TYPE: HTTP controller
// PURPOSE: Authenticated owner-scoped support-case portal; anonymous ContactMessage rows remain outside this private surface.

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\CreateSupportCaseRequest;
use App\Http\Requests\Support\ReplySupportCaseRequest;
use App\Models\User;
use App\Services\Support\SupportCaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

final class SupportPortalController extends Controller
{
    public function index(Request $request, SupportCaseService $supportCases): View
    {
        $owner = $this->owner($request);

        return view('support.portal', [
            'surface' => 'index',
            'state' => 'AVAILABLE',
            'reference' => null,
            'records' => $supportCases->listFor($owner),
            'case' => null,
        ]);
    }

    public function show(Request $request, string $reference, SupportCaseService $supportCases): View
    {
        $owner = $this->owner($request);

        try {
            $case = $supportCases->findForOwner($owner, $reference);
        } catch (RuntimeException) {
            abort(404);
        }

        return view('support.portal', [
            'surface' => 'detail',
            'state' => 'AVAILABLE',
            'reference' => $case->public_reference,
            'records' => [],
            'case' => $case,
        ]);
    }

    public function store(CreateSupportCaseRequest $request, SupportCaseService $supportCases): RedirectResponse
    {
        $owner = $this->owner($request);
        $supportCases->create($owner, $request->validated());

        return redirect()->route('support.index')->with('status', trans('support.case_created'));
    }

    public function reply(
        ReplySupportCaseRequest $request,
        string $reference,
        SupportCaseService $supportCases,
    ): RedirectResponse {
        $owner = $this->owner($request);

        try {
            $supportCases->reply($owner, $reference, (string) $request->validated('body'));
        } catch (RuntimeException) {
            abort(404);
        }

        return redirect()->route('support.show', ['reference' => $reference])
            ->with('status', trans('support.reply_created'));
    }

    private function owner(Request $request): User
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 401);

        return $owner;
    }
}
