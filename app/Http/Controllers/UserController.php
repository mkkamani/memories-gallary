<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\NewUserAccountCreatedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

class UserController extends Controller
{
    use AuthorizesRequests;
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $perPage = (string) ($request->input('per_page', '5'));
        $allowedPerPage = ['5', '10', '25', '50', 'all'];
        $sortBy = (string) ($request->input('sort_by', 'created_at'));
        $sortDirection = (string) ($request->input('sort_direction', 'desc'));

        $allowedSortBy = ['created_at', 'name', 'email'];
        $allowedSortDirection = ['asc', 'desc'];

        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = '5';
        }

        if (!in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'created_at';
        }

        if (!in_array($sortDirection, $allowedSortDirection, true)) {
            $sortDirection = 'desc';
        }

        $query = User::query()
            ->when($request->role && $request->role !== 'all', function ($query) use ($request) {
                $query->where('role', $request->role);
            })
            ->when($request->location && $request->location !== 'all', function ($query) use ($request) {
                $query->where('location', $request->location);
            })
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });

        if ($sortBy === 'created_at') {
            $query->latest();
        } else {
            $query->orderBy($sortBy, $sortDirection)->orderBy('created_at', 'desc');
        }

        $resolvedPerPage = $perPage === 'all'
            ? max($query->count(), 1)
            : (int) $perPage;

        $users = $query
            ->paginate($resolvedPerPage)
            ->withQueryString();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'role', 'location', 'per_page', 'sort_by', 'sort_direction']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'role' => 'required|string|in:admin,manager,member',
            'location' => 'required|string|in:Rajkot,Ahmedabad',
            'password_mode' => 'required|string|in:auto,manual',
        ]);

        if ($request->password_mode === 'manual') {
            $request->validate([
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ]);
        }

        $plainPassword = $request->password_mode !== 'manual'
            ? $this->generateSecurePassword(14)
            : (string) $request->password;

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($plainPassword),
            'role' => $request->role,
            'location' => $request->location,
        ]);

        try {
            $user->notify(
                new NewUserAccountCreatedNotification(
                    plainPassword: $plainPassword,
                ),
            );

            return redirect()
                ->route('users.index')
                ->with('success', 'User created successfully. Login credentials were sent by email.');
        } catch (\Throwable $e) {
            Log::error('Failed to send new user credentials email.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('users.index')
                ->with('warning', 'User created successfully, but the credentials email could not be sent.');
        }
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'required|string|in:admin,manager,member',
            'location' => 'required|string|in:Rajkot,Ahmedabad',
        ]);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
            'location' => $request->location,
        ]);

        if ($request->filled('password')) {
            $request->validate([
                'password' => ['confirmed', Rules\Password::defaults()],
            ]);
            $user->update([
                'password' => Hash::make($request->password),
            ]);
        }

        return redirect()->back()->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        if ($user->id === auth()->id()) {
            return redirect()->back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully.');
    }

    private function generateSecurePassword(int $length = 14): string
    {
        $length = max($length, 8);

        $sets = [
            'abcdefghijklmnopqrstuvwxyz',
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            '0123456789',
            '!@#$%&*()[]{}',
        ];

        $all = implode('', $sets);
        $password = [];

        // Ensure at least one character from each set
        foreach ($sets as $set) {
            $password[] = $set[random_int(0, strlen($set) - 1)];
        }

        // Fill remaining length
        $allLength = strlen($all);
        for ($i = count($password); $i < $length; $i++) {
            $password[] = $all[random_int(0, $allLength - 1)];
        }

        // Shuffle securely
        for ($i = $length - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }

        return implode('', $password);
    }

    private function randomCharFromSet(string $set): string
    {
        $index = random_int(0, strlen($set) - 1);

        return $set[$index];
    }
}
