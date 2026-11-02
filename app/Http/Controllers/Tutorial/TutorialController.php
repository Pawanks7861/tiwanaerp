<?php

namespace App\Http\Controllers\Tutorial;

use App\Http\Controllers\Controller;
use App\Services\Tutorial\TutorialProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TutorialController extends Controller
{
    public function __construct(private readonly TutorialProgressService $tutorials) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        return response()->json($this->tutorials->inertiaPayload($user));
    }

    public function acknowledge(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            'step' => ['required', 'string', 'max:80'],
        ]);

        return response()->json($this->tutorials->acknowledge($user, $data['step']));
    }

    public function goTo(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            'step' => ['required', 'string', 'max:80'],
        ]);

        return response()->json($this->tutorials->goTo($user, $data['step']));
    }

    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        return response()->json($this->tutorials->complete($user));
    }

    public function pause(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->tutorials->touch($user);

        return response()->json(['ok' => true, 'completed' => false]);
    }
}
