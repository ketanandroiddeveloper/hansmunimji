<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Applications\ApplicationService;

final class ApplicationController extends Controller
{
    public function __construct(private ApplicationService $applications)
    {
    }

    public function createDraft(Request $request): Response
    {
        return $this->ok($this->applications->createDraft($request->json()), 201);
    }

    public function showDraft(Request $request): Response
    {
        return $this->ok($this->applications->getDraft($request->param('reference'), $this->accessToken($request)));
    }

    public function updateDraft(Request $request): Response
    {
        $this->applications->updateDraft($request->param('reference'), $this->accessToken($request), $request->json());

        return Response::noContent();
    }

    public function submitDraft(Request $request): Response
    {
        return $this->ok($this->applications->submitDraft($request->param('reference'), $this->accessToken($request), $request->json(), $request));
    }

    public function submit(Request $request): Response
    {
        return $this->ok($this->applications->submitDirect($request->json(), $request), 201);
    }

    public function status(Request $request): Response
    {
        return $this->ok($this->applications->status($request->param('reference'), $this->accessToken($request)));
    }
}
