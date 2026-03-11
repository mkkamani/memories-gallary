<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Media;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $query = Media::with('user', 'album');

        if ($request->search) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            })->orWhereHas('album', function ($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%');
            });
        }

        $media = $query->latest()->paginate(20);
            
        return Inertia::render('Dashboard', [
            'media' => $media,
            'filters' => $request->only(['search']),
        ]);
    }
}
