<?php

declare(strict_types=1);

namespace App\Integrations\Google;

/**
 * A Google failure with a stable category. The message is written for administrators and never
 * contains tokens, secrets or request bodies; public users only ever see a generic error.
 */
final class GoogleApiError extends \RuntimeException
{
    public const MESSAGES = [
        'not_configured' => 'Google OAuth credentials are not configured for this environment.',
        'not_connected' => 'Google is not connected. Connect it under Integrations → Google Workspace.',
        'invalid_client' => 'Google rejected the OAuth client. Check GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET for this environment.',
        'redirect_uri_mismatch' => 'The redirect URI does not match one registered on the OAuth client in Google Cloud Console.',
        'invalid_code' => 'The Google sign-in code was invalid or already used. Please connect again.',
        'no_refresh_token' => 'Google did not return offline access. Remove the app under myaccount.google.com → Security → Third-party access, then connect again.',
        'wrong_account' => 'A different Google account was selected than the one configured for this environment.',
        'revoked' => 'Google access was revoked or has expired. Reconnect Google to resume calendar events, Meet links and Gmail sending.',
        'insufficient_scope' => 'Google did not grant every permission this feature needs. Reconnect and allow all requested access.',
        'api_disabled' => 'The required Google API is not enabled in the Google Cloud project.',
        'calendar_not_found' => 'The configured calendar was not found or is not accessible to the connected account. Check GOOGLE_CALENDAR_ID.',
        'quota' => 'Google rate or quota limits were reached. The task will retry automatically.',
        'meet_failed' => 'Google Calendar did not create a Meet conference for this event.',
        'unauthorized' => 'Google rejected the access token.',
        'invalid_request' => 'Google rejected the request as invalid.',
        'server_error' => 'Google is temporarily unavailable. The task will retry automatically.',
        'network' => 'Google could not be reached from the server. The task will retry automatically.',
        'invalid_recipient' => 'The recipient email address is not valid.',
        'unknown' => 'Google returned an unexpected error.',
    ];

    public function __construct(
        public readonly string $category,
        ?string $message = null,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?? (self::MESSAGES[$category] ?? self::MESSAGES['unknown']), 0, $previous);
    }

    /**
     * Maps a Google API error response to a category. Google reports reasons both in the legacy
     * `errors[].reason` list and in `details[].reason` (google.rpc.ErrorInfo).
     *
     * @param array<string, mixed> $body
     */
    public static function categorize(int $status, array $body): string
    {
        $reasons = [];
        foreach ((array) ($body['error']['errors'] ?? []) as $error) {
            $reasons[] = (string) ($error['reason'] ?? '');
        }
        foreach ((array) ($body['error']['details'] ?? []) as $detail) {
            $reasons[] = (string) ($detail['reason'] ?? '');
        }
        $has = static fn (string ...$any) => array_intersect($any, $reasons) !== [];

        return match (true) {
            $status === 429, $has('rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded', 'quotaExceeded', 'RATE_LIMIT_EXCEEDED') => 'quota',
            $has('insufficientPermissions', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT') => 'insufficient_scope',
            $has('accessNotConfigured', 'SERVICE_DISABLED') => 'api_disabled',
            $status === 401 => 'unauthorized',
            $status === 404 => 'calendar_not_found',
            $status === 400 => 'invalid_request',
            $status >= 500 => 'server_error',
            default => 'unknown',
        };
    }

    /** Category for an OAuth token-endpoint error code (RFC 6749 §5.2). */
    public static function categorizeOAuth(string $error): string
    {
        return match ($error) {
            'invalid_client', 'unauthorized_client' => 'invalid_client',
            'redirect_uri_mismatch' => 'redirect_uri_mismatch',
            'invalid_grant' => 'revoked',
            'invalid_scope' => 'insufficient_scope',
            default => 'unknown',
        };
    }
}
