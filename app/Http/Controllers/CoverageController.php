<?php

namespace App\Http\Controllers;

use App\Services\DirectionCoverage;
use Illuminate\View\View;

class CoverageController extends Controller
{
    /**
     * Which view directions each AP's public gallery is missing, fewest covered first: a to-do
     * list for photo trips.
     */
    public function index(DirectionCoverage $coverage): View
    {
        return view('coverage.index', ['rows' => $coverage->overview()]);
    }
}
