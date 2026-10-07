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
        $summary = $register ? $service->summary($register) : null;
        $registers = Register::with('user')->where('user_id', auth()->id())->latest()->paginate(15);
        if ($register) {
            $register->load('movements.user');
        }

        return view('register.index', compact('register', 'summary', 'registers'));
    }

    public function open(RegisterRequest $request, RegisterService $service)
    {
        $service->open(auth()->id(), $request->validated('opening_cash'));

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
        $service->close($register, $request->validated('actual_cash'), $request->validated('notes'));

        return back()->with('success', 'Register closed.');
    }

    public function show(Register $register, RegisterService $service)
    {
        abort_unless($register->user_id === auth()->id() || auth()->user()->hasPermission('reports.register'), 403);
        $register->load(['user', 'movements.user']);
        $summary = $service->summary($register);

        return view('register.show', compact('register', 'summary'));
    }
}
