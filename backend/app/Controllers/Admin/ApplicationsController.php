<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Security\Rbac;
use App\Services\Applications\ApplicationService;

final class ApplicationsController extends Controller
{
    public function __construct(private ApplicationService $applications, private Rbac $rbac)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->pagination($request, 25);
        $result = $this->applications->adminList(
            ['status' => $request->query('status'), 'consultation_type' => $request->query('consultation_type'), 'q' => $request->query('q')],
            $page,
            $perPage,
            $this->rbac->can($this->userId($request), 'applications.view_confidential'),
        );

        return $this->ok($result['items'], 200, $this->meta($page, $perPage, $result['total']));
    }

    public function show(Request $request): Response
    {
        $userId = $this->userId($request);

        return $this->ok($this->applications->adminShow($request->intParam('id'), $this->rbac->can($userId, 'applications.view_confidential'), $userId, $request));
    }

    public function approve(Request $request): Response
    {
        $this->applications->approve($request->intParam('id'), $this->userId($request), $request);

        return Response::noContent();
    }

    public function changeStatus(Request $request): Response
    {
        $this->applications->changeStatus($request->intParam('id'), $request->json(), $this->userId($request), $request);

        return Response::noContent();
    }

    public function archive(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['note' => 'nullable|string|max:500']);
        $this->applications->archive($request->intParam('id'), $this->userId($request), $request, $data['note'] ?? null);

        return Response::noContent();
    }

    public function reject(Request $request): Response
    {
        $this->applications->reject($request->intParam('id'), $this->userId($request), $request);

        return Response::noContent();
    }

    public function requestInfo(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['message' => 'required|string|min:10|max:3000']);
        $this->applications->requestInfo($request->intParam('id'), (string) $data['message'], $this->userId($request), $request);

        return Response::noContent();
    }

    public function invite(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['appointment_type_id' => 'nullable|integer']);
        $this->applications->invite($request->intParam('id'), isset($data['appointment_type_id']) ? (int) $data['appointment_type_id'] : null, $this->userId($request), $request);

        return Response::noContent();
    }

    public function notes(Request $request): Response
    {
        if (!$this->rbac->can($this->userId($request), 'applications.view_confidential')) {
            throw HttpException::forbidden();
        }
        $data = Validator::validate($request->json(), ['admin_notes' => 'nullable|string|max:10000']);
        $this->applications->updateNotes($request->intParam('id'), $data['admin_notes'] ?? null, $this->userId($request));

        return Response::noContent();
    }
}
