<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OperatorReport;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(OperatorReport $report): Response
    {
        return Inertia::render('admin/Report', $report->lastThirtyDays());
    }
}
