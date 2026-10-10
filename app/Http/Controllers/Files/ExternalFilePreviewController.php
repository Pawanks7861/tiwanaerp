<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Services\Files\ShareCadPreview;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary fetch for one approved DWG. The token is the authorization. No session is required.
 * This is not a general file-sharing route.
 */
class ExternalFilePreviewController extends Controller
{
    public function __construct(private readonly ShareCadPreview $sharecad) {}

    public function __invoke(string $token): Response
    {
        return $this->sharecad->stream($token);
    }
}
