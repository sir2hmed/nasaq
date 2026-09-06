<?php

namespace App\Http\Controllers;

use App\Models\AgentOutput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class AgentOutputController extends Controller
{
    public function stream(Request $request, AgentOutput $output): BinaryFileResponse
    {
        $output->loadMissing('run');
        Gate::authorize('view', $output->run);
        abort_if(
            ! str_starts_with((string) $output->mime_type, 'video/'),
            404,
        );

        $path = $this->safeArtifactPath($output);
        $fileName = $this->safeFileName($output);
        $response = response()->file(
            $path,
            ['Content-Type' => $output->mime_type],
        );
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $fileName);

        return $response;
    }

    public function download(Request $request, AgentOutput $output): BinaryFileResponse
    {
        $output->loadMissing('run');
        Gate::authorize('view', $output->run);
        $path = $this->safeArtifactPath($output);
        $fileName = $this->safeFileName($output);

        return response()->download(
            $path,
            $fileName,
            ['Content-Type' => $output->mime_type],
        );
    }

    private function safeArtifactPath(AgentOutput $output): string
    {
        $filePath = str_replace('\\', '/', (string) $output->file_path);
        abort_if(
            $filePath === '' ||
            ! str_starts_with($filePath, 'artifacts/') ||
            str_contains($filePath, '..') ||
            preg_match('/^(?:[A-Za-z]:|\/)/', $filePath) === 1 ||
            ! Storage::disk('local')->exists($filePath),
            404,
        );

        $resolved = realpath(Storage::disk('local')->path($filePath));
        $artifactRoot = realpath(Storage::disk('local')->path('artifacts'));
        abort_if($resolved === false || $artifactRoot === false, 404);

        $rootPrefix = rtrim($artifactRoot, '\\/').DIRECTORY_SEPARATOR;
        abort_unless(
            str_starts_with(strtolower($resolved), strtolower($rootPrefix)),
            404,
        );

        return $resolved;
    }

    private function safeFileName(AgentOutput $output): string
    {
        $stored = $output->metadata_json['file_name'] ?? basename((string) $output->file_path);
        $fileName = basename(str_replace('\\', '/', is_string($stored) ? $stored : ''));
        $fileName = preg_replace('/[\x00-\x1F\x7F]/u', '', $fileName) ?? '';

        return $fileName !== '' ? $fileName : 'nasaq-output';
    }
}
