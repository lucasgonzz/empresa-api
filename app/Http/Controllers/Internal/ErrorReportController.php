<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\GitHubErrorReporterService;
use Illuminate\Http\Request;

/**
 * Endpoint interno para recibir reportes de error desde empresa-spa.
 */
class ErrorReportController extends Controller
{
    /**
     * Recibe un error del frontend y lo delega al reporter de GitHub.
     *
     * @param Request $request Payload con message, file, line, url, stack.
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:500',
            'file' => 'nullable|string|max:300',
            'line' => 'nullable|integer',
            'url' => 'nullable|string|max:500',
            'stack' => 'nullable|string|max:8000',
        ]);

        (new GitHubErrorReporterService())->reportFront($data);

        return response()->json(['ok' => true]);
    }
}
