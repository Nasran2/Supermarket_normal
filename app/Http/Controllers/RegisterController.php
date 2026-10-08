<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\Register;
use App\Services\RegisterService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    public function index(RegisterService $service)
    {
        $register = $service->current(auth()->id());
        $summary = $register ? $service->summary($register, true) : null;
        $registers = Register::with('user')->where('user_id', auth()->id())->latest()->paginate(15);
        if ($register) {
            $register->load('movements.user');
        }

        return view('register.index', compact('register', 'summary', 'registers'));
    }

    public function open(RegisterRequest $request, RegisterService $service)
    {
        $service->open(auth()->id(), $request->validated('opening_cash'));

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Register opened.']);
        }

        return back()->with('success', 'Register opened.');
    }

    public function movement(RegisterRequest $request, RegisterService $service)
    {
        DB::transaction(function () use ($request, $service) {
            $r = $service->current(auth()->id(), true);
            if (! $r) {
                throw ValidationException::withMessages(['register' => 'Open your register first.']);
            }
            $movement = $r->movements()->create($request->validated() + ['user_id' => auth()->id()]);
            Audit::record('register.movement', $movement);
        });

        return back()->with('success', 'Cash movement recorded.');
    }

    public function close(RegisterRequest $request, RegisterService $service)
    {
        $register = $service->current(auth()->id());
        if (! $register) {
            throw ValidationException::withMessages(['register' => 'No open register.']);
        }
        if ($request->filled('register_id') && (int) $request->validated('register_id') !== $register->id) {
            throw ValidationException::withMessages(['register' => 'This register changed. Refresh the summary before closing.']);
        }
        $service->close($register, $request->validated('actual_cash'), $request->validated('notes'));

        if ($request->expectsJson()) {
            return response()->json($this->summaryResponse($register->fresh(), $service));
        }

        return back()->with('success', 'Register closed.');
    }

    public function show(Register $register, RegisterService $service)
    {
        abort_unless($register->user_id === auth()->id() || auth()->user()->hasPermission('reports.register'), 403);
        $register->load(['user', 'movements.user']);
        $summary = $service->summary($register, true);
        $sales = $register->sales()->with('payments')->latest('sold_at')->get();

        return view('register.show', compact('register', 'summary', 'sales'));
    }

    public function currentSummary(RegisterService $service)
    {
        $register = $service->current(auth()->id());
        if (! $register) {
            throw ValidationException::withMessages(['register' => 'No open register. Open your register first.']);
        }

        return response()->json($this->summaryResponse($register, $service));
    }

    private function summaryResponse(Register $register, RegisterService $service): array
    {
        $summary = $service->summary($register, true);
        $sales = $register->sales()->with('payments')->latest('sold_at')->get();

        return ['register_id' => $register->id, 'expected_cash' => $summary['expected'], 'summary' => $summary,
            'html' => view('register.partials.summary', compact('register', 'summary', 'sales'))->render()];
    }
}
