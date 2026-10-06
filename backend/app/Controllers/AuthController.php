<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Auth\AuthService;
use App\Services\Auth\SessionService;

final class AuthController extends Controller
{
    public function __construct(private AuthService $auth, private SessionService $sessions)
    {
    }

    public function login(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['email' => 'required|email', 'password' => 'required|string|max:256']);
        $result = $this->auth->login((string) $data['email'], (string) $data['password'], $request);

        return $this->sessionResponse($result);
    }

    public function twoFactor(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['challenge' => 'required|string|max:128', 'code' => 'required|string|max:10']);

        return $this->sessionResponse($this->auth->verifyTwoFactor((string) $data['challenge'], (string) $data['code'], $request));
    }

    public function logout(Request $request): Response
    {
        $session = $request->attribute('session');
        $this->sessions->revoke((int) $session['id']);

        return $this->sessions->clearCookie(Response::noContent());
    }

    public function refresh(Request $request): Response
    {
        $session = $this->sessions->rotate($request->attribute('session'), $request);
        $response = $this->ok(['csrf_token' => $session['csrf'], 'expires_at' => $session['expires_at']]);

        return $this->sessions->attachCookie($response, $session['token']);
    }

    public function me(Request $request): Response
    {
        $session = $request->attribute('session');

        return $this->ok($this->auth->profile($this->userId($request)) + ['csrf_token' => $this->sessions->issueCsrf((int) $session['id'])]);
    }

    public function forgotPassword(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['email' => 'required|email']);
        $this->auth->requestPasswordReset((string) $data['email'], $request);

        return $this->ok(['message' => 'If an account exists for that address, a reset link has been sent.'], 202);
    }

    public function resetPassword(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['token' => 'required|string|max:128', 'password' => 'required|string|max:256']);
        $this->auth->resetPassword((string) $data['token'], (string) $data['password'], $request);

        return Response::noContent();
    }

    public function changePassword(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['current_password' => 'required|string|max:256', 'password' => 'required|string|max:256']);
        $session = $request->attribute('session');
        $this->auth->changePassword($this->userId($request), (int) $session['id'], (string) $data['current_password'], (string) $data['password'], $request);

        return Response::noContent();
    }

    public function setupTwoFactor(Request $request): Response
    {
        return $this->ok($this->auth->beginTwoFactorSetup($this->userId($request)));
    }

    public function enableTwoFactor(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['code' => 'required|string|max:10']);
        $this->auth->enableTwoFactor($this->userId($request), (string) $data['code'], $request);

        return Response::noContent();
    }

    public function disableTwoFactor(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['password' => 'required|string|max:256']);
        $this->auth->disableTwoFactor($this->userId($request), (string) $data['password'], $request);

        return Response::noContent();
    }

    /** @param array<string, mixed> $result */
    private function sessionResponse(array $result): Response
    {
        if ($result['status'] === 'two_factor_required') {
            return $this->ok(['two_factor_required' => true, 'challenge' => $result['challenge']]);
        }
        $response = $this->ok(['two_factor_required' => false, 'user' => $this->auth->profile($result['user_id']), 'csrf_token' => $result['session']['csrf']]);

        return $this->sessions->attachCookie($response, $result['session']['token']);
    }
}
