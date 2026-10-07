<?php

declare(strict_types=1);

use App\Controllers\Admin;
use App\Controllers\Admin\Resources;
use App\Controllers\ApplicationController;
use App\Controllers\AppointmentController;
use App\Controllers\AuthController;
use App\Controllers\PaymentController;
use App\Controllers\PrivacyController;
use App\Controllers\PublicContentController as Content;
use App\Controllers\SeoController;
use App\Core\Router;

return static function (Router $router): void {
    $router->get('/sitemap.xml', [SeoController::class, 'sitemap']);
    $router->get('/robots.txt', [SeoController::class, 'robots']);

    $router->group('/api/v1', [], static function (Router $r): void {
        // ------------------------------------------------------------ public content
        $r->get('/health', [Content::class, 'health']);
        $r->get('/settings/public', [Content::class, 'settings']);
        $r->get('/pages', [Content::class, 'pages']);
        $r->get('/pages/{slug}', [Content::class, 'page']);
        $r->get('/practitioner', [Content::class, 'practitioner']);
        $r->get('/services', [Content::class, 'services']);
        $r->get('/services/{slug}', [Content::class, 'service']);
        $r->get('/faqs', [Content::class, 'faqs']);
        $r->get('/testimonials', [Content::class, 'testimonials']);
        $r->get('/cities', [Content::class, 'cities']);
        $r->get('/events', [Content::class, 'events']);
        $r->get('/events/registrations/{reference}', [Content::class, 'registration'], ['throttle:client_lookup,60,600']);
        $r->post('/events/registrations/{reference}/cancel', [Content::class, 'cancelRegistration'], ['throttle:booking_change,10,3600']);
        $r->get('/events/{slug}', [Content::class, 'event']);
        $r->post('/events/{slug}/registrations', [Content::class, 'registerForEvent'], ['throttle:event_register,10,3600']);
        $r->post('/events/{slug}/reserve', [Content::class, 'registerForEvent'], ['throttle:event_register,10,3600']);
        $r->get('/event-registrations/{reference}', [Content::class, 'registration'], ['throttle:client_lookup,60,600']);
        $r->post('/event-registrations/{reference}/cancel', [Content::class, 'cancelRegistration'], ['throttle:booking_change,10,3600']);
        $r->post('/event-registrations/{reference}/payment', [PaymentController::class, 'createForRegistration'], ['throttle:payment_order,20,3600']);
        $r->get('/blogs', [Content::class, 'blogs']);
        $r->get('/blogs/{slug}', [Content::class, 'blog']);
        $r->get('/audio', [Content::class, 'audio']);
        $r->post('/audio/{slug}/stream', [Content::class, 'audioStreamUrl'], ['throttle:audio_url,60,600']);
        $r->get('/audio/stream/{token}', [Content::class, 'audioStream']);

        // ------------------------------------------------------------ privacy
        $r->post('/privacy/requests', [PrivacyController::class, 'store'], ['throttle:privacy,5,3600']);
        $r->get('/privacy/requests/verify/{token}', [PrivacyController::class, 'verify'], ['throttle:privacy_verify,20,3600']);

        // ------------------------------------------------------------ applications
        $r->post('/applications/drafts', [ApplicationController::class, 'createDraft'], ['throttle:app_draft,10,3600']);
        $r->get('/applications/drafts/{reference}', [ApplicationController::class, 'showDraft'], ['throttle:client_lookup,60,600']);
        $r->put('/applications/drafts/{reference}', [ApplicationController::class, 'updateDraft'], ['throttle:app_save,120,3600']);
        $r->post('/applications/drafts/{reference}/submit', [ApplicationController::class, 'submitDraft'], ['throttle:app_submit,10,3600']);
        $r->post('/applications', [ApplicationController::class, 'submit'], ['throttle:app_submit,10,3600']);
        $r->get('/applications/{reference}', [ApplicationController::class, 'status'], ['throttle:client_lookup,60,600']);
        $r->post('/private-access/applications', [ApplicationController::class, 'submit'], ['throttle:app_submit,10,3600']);
        $r->get('/private-access/applications/{reference}', [ApplicationController::class, 'status'], ['throttle:client_lookup,60,600']);
        $r->post('/private-access/applications/{reference}/additional-info', [ApplicationController::class, 'submitDraft'], ['throttle:app_submit,10,3600']);

        // ------------------------------------------------------------ appointments
        $r->get('/appointments/types', [AppointmentController::class, 'types']);
        $r->get('/appointments/availability', [AppointmentController::class, 'availability'], ['throttle:availability,120,600']);
        $r->post('/appointments', [AppointmentController::class, 'store'], ['throttle:booking,10,3600']);
        $r->post('/appointments/reserve', [AppointmentController::class, 'store'], ['throttle:booking,10,3600']);
        $r->get('/appointments/{reference}', [AppointmentController::class, 'show'], ['throttle:client_lookup,60,600']);
        $r->post('/appointments/{reference}/payment', [PaymentController::class, 'createForAppointment'], ['throttle:payment_order,20,3600']);
        $r->post('/appointments/{reference}/reschedule', [AppointmentController::class, 'reschedule'], ['throttle:booking_change,10,3600']);
        $r->post('/appointments/{reference}/cancel', [AppointmentController::class, 'cancel'], ['throttle:booking_change,10,3600']);

        // ------------------------------------------------------------ payments
        $r->get('/payments/gateways', [PaymentController::class, 'gateways']);
        $r->post('/payments/create-order', [PaymentController::class, 'createOrder'], ['throttle:payment_order,20,3600']);
        $r->post('/payments/verify', [PaymentController::class, 'verify'], ['throttle:payment_verify,30,3600']);
        $r->post('/payments/webhook/{gateway}', [PaymentController::class, 'webhook']);
        $r->post('/payments/stripe/webhook', [PaymentController::class, 'stripeWebhook']);
        $r->post('/payments/razorpay/webhook', [PaymentController::class, 'razorpayWebhook']);
        $r->get('/payments/{reference}', [PaymentController::class, 'show'], ['throttle:client_lookup,60,600']);
        $r->post('/payments/{reference}/retry', [PaymentController::class, 'retry'], ['throttle:payment_order,20,3600']);

        // ------------------------------------------------------------ auth
        $r->post('/auth/login', [AuthController::class, 'login']);
        $r->post('/auth/two-factor', [AuthController::class, 'twoFactor'], ['throttle:two_factor,10,900']);
        $r->post('/auth/forgot-password', [AuthController::class, 'forgotPassword'], ['throttle:forgot,10,3600']);
        $r->post('/auth/reset-password', [AuthController::class, 'resetPassword'], ['throttle:reset,10,3600']);
        $r->group('/auth', ['auth'], static function (Router $r): void {
            $r->get('/me', [AuthController::class, 'me']);
            $r->post('/logout', [AuthController::class, 'logout'], ['csrf']);
            $r->post('/refresh', [AuthController::class, 'refresh'], ['csrf']);
            $r->post('/change-password', [AuthController::class, 'changePassword'], ['csrf', 'throttle:change_password,10,3600']);
            $r->post('/two-factor/setup', [AuthController::class, 'setupTwoFactor'], ['csrf']);
            $r->post('/two-factor/enable', [AuthController::class, 'enableTwoFactor'], ['csrf', 'throttle:two_factor_enable,10,900']);
            $r->post('/two-factor/disable', [AuthController::class, 'disableTwoFactor'], ['csrf', 'throttle:two_factor_disable,10,900']);
        });

        // Google redirects here cross-site, so the session cookie is absent; the signed state authenticates.
        $r->get('/admin/integrations/google/callback', [Admin\IntegrationsController::class, 'googleCallback'], ['throttle:oauth_callback,20,600']);

        // ------------------------------------------------------------ admin
        $r->group('/admin', ['auth', 'two_factor', 'csrf'], static function (Router $r): void {
            $r->get('/dashboard', [Admin\DashboardController::class, 'index'], ['can:dashboard.view']);

            $r->get('/applications', [Admin\ApplicationsController::class, 'index'], ['can:applications.view']);
            $r->get('/applications/{id}', [Admin\ApplicationsController::class, 'show'], ['can:applications.view']);
            $r->post('/applications/{id}/approve', [Admin\ApplicationsController::class, 'approve'], ['can:applications.manage']);
            $r->post('/applications/{id}/reject', [Admin\ApplicationsController::class, 'reject'], ['can:applications.manage']);
            $r->post('/applications/{id}/request-info', [Admin\ApplicationsController::class, 'requestInfo'], ['can:applications.manage']);
            $r->post('/applications/{id}/invite', [Admin\ApplicationsController::class, 'invite'], ['can:applications.manage']);
            $r->patch('/applications/{id}/notes', [Admin\ApplicationsController::class, 'notes'], ['can:applications.manage']);
            $r->patch('/applications/{id}/status', [Admin\ApplicationsController::class, 'changeStatus'], ['can:applications.manage']);
            $r->post('/applications/{id}/archive', [Admin\ApplicationsController::class, 'archive'], ['can:applications.manage']);

            $r->get('/appointments', [Admin\AppointmentsController::class, 'index'], ['can:appointments.view']);
            $r->get('/appointments/availability', [Admin\AppointmentsController::class, 'availability'], ['can:appointments.manage']);
            $r->post('/appointments', [Admin\AppointmentsController::class, 'store'], ['can:appointments.manage']);
            $r->get('/appointments/{id}', [Admin\AppointmentsController::class, 'show'], ['can:appointments.view']);
            $r->patch('/appointments/{id}', [Admin\AppointmentsController::class, 'update'], ['can:appointments.manage']);
            $r->post('/appointments/{id}/no-show', [Admin\AppointmentsController::class, 'noShow'], ['can:appointments.manage']);
            $r->post('/appointments/{id}/resend-confirmation', [Admin\AppointmentsController::class, 'resendConfirmation'], ['can:appointments.manage', 'throttle:resend_confirmation,10,3600']);
            $r->post('/appointments/{id}/reschedule', [Admin\AppointmentsController::class, 'reschedule'], ['can:appointments.manage']);
            $r->post('/appointments/{id}/cancel', [Admin\AppointmentsController::class, 'cancel'], ['can:appointments.manage']);
            $r->post('/appointments/{id}/complete', [Admin\AppointmentsController::class, 'complete'], ['can:appointments.manage']);
            $r->post('/appointments/{id}/calendar-sync', [Admin\AppointmentsController::class, 'resyncCalendar'], ['can:appointments.manage']);

            $r->resource('/appointment-types', Resources\AppointmentTypesController::class, 'appointments.configure');
            $r->resource('/availability', Resources\AvailabilityController::class, 'appointments.configure');
            $r->resource('/unavailable-dates', Resources\UnavailableDatesController::class, 'appointments.configure');

            $r->resource('/services', Resources\ServicesController::class, 'content.manage');
            $r->resource('/service-categories', Resources\ServiceCategoriesController::class, 'content.manage');
            $r->resource('/pages', Resources\PagesController::class, 'content.manage');
            $r->resource('/blogs', Resources\BlogPostsController::class, 'content.manage');
            $r->resource('/blog-categories', Resources\BlogCategoriesController::class, 'content.manage');
            $r->resource('/faqs', Resources\FaqsController::class, 'content.manage');
            $r->resource('/testimonials', Resources\TestimonialsController::class, 'content.manage');
            $r->resource('/cities', Resources\CitiesController::class, 'content.manage');
            $r->resource('/qualifications', Resources\QualificationsController::class, 'content.manage');
            $r->resource('/audio', Resources\AudioTracksController::class, 'content.manage');
            $r->post('/audio/{id}/file', [Resources\AudioTracksController::class, 'upload'], ['can:content.manage']);
            $r->get('/practitioner', [Admin\PractitionerController::class, 'show'], ['can:content.manage']);
            $r->put('/practitioner', [Admin\PractitionerController::class, 'update'], ['can:content.manage']);

            $r->resource('/events', Resources\EventsController::class, 'events.manage');
            $r->get('/events/{id}/registrations', [Admin\EventRegistrationsController::class, 'index'], ['can:events.manage']);
            $r->put('/events/{id}/registrations/{registration}', [Admin\EventRegistrationsController::class, 'updateStatus'], ['can:events.manage']);
            $r->get('/events/{id}/registrations/{registration}/history', [Admin\EventRegistrationsController::class, 'history'], ['can:events.manage']);

            $r->get('/media', [Admin\MediaController::class, 'index'], ['can:media.manage']);
            $r->post('/media', [Admin\MediaController::class, 'store'], ['can:media.manage']);
            $r->get('/media/{id}', [Admin\MediaController::class, 'show'], ['can:media.manage']);
            $r->put('/media/{id}', [Admin\MediaController::class, 'update'], ['can:media.manage']);
            $r->post('/media/{id}/replace', [Admin\MediaController::class, 'replace'], ['can:media.manage']);
            $r->delete('/media/{id}', [Admin\MediaController::class, 'destroy'], ['can:media.manage']);

            $r->resource('/seo', Resources\SeoMetadataController::class, 'seo.manage');

            $r->get('/email-templates', [Resources\EmailTemplatesController::class, 'index'], ['can:settings.manage']);
            $r->get('/email-templates/{id}', [Resources\EmailTemplatesController::class, 'show'], ['can:settings.manage']);
            $r->put('/email-templates/{id}', [Resources\EmailTemplatesController::class, 'update'], ['can:settings.manage']);
            $r->post('/email-templates/{id}/preview', [Resources\EmailTemplatesController::class, 'preview'], ['can:settings.manage']);

            $r->get('/settings', [Admin\SettingsController::class, 'index'], ['can:settings.manage']);
            $r->put('/settings', [Admin\SettingsController::class, 'update'], ['can:settings.manage']);

            $r->get('/payments', [Admin\PaymentsController::class, 'index'], ['can:payments.view']);
            $r->get('/payments/export', [Admin\PaymentsController::class, 'exportCsv'], ['can:payments.view']);
            $r->get('/payments/{id}', [Admin\PaymentsController::class, 'show'], ['can:payments.view']);
            $r->post('/payments/{id}/refund', [Admin\PaymentsController::class, 'refund'], ['can:payments.refund']);
            $r->post('/payments/{id}/accept-reconciliation', [Admin\PaymentsController::class, 'acceptReconciliation'], ['can:payments.refund']);

            $r->get('/reports', [Admin\ReportsController::class, 'index'], ['can:reports.view']);

            $r->get('/integrations', [Admin\IntegrationsController::class, 'index'], ['can:integrations.manage']);
            $r->post('/integrations/google/connect', [Admin\IntegrationsController::class, 'googleConnect'], ['can:integrations.manage']);
            $r->post('/integrations/google/disconnect', [Admin\IntegrationsController::class, 'googleDisconnect'], ['can:integrations.manage']);
            $r->post('/integrations/google/test/gmail', [Admin\IntegrationsController::class, 'googleTestGmail'], ['can:integrations.manage', 'throttle:google_test,20,3600']);
            $r->post('/integrations/google/test/calendar', [Admin\IntegrationsController::class, 'googleTestCalendar'], ['can:integrations.manage', 'throttle:google_test,20,3600']);
            $r->post('/integrations/google/test/meet', [Admin\IntegrationsController::class, 'googleTestMeet'], ['can:integrations.manage', 'throttle:google_test,20,3600']);
            $r->post('/integrations/google/test/cleanup', [Admin\IntegrationsController::class, 'googleCleanupTests'], ['can:integrations.manage', 'throttle:google_test,20,3600']);
            $r->post('/integrations/jobs/{id}/retry', [Admin\IntegrationsController::class, 'retryJob'], ['can:integrations.manage']);
            $r->post('/integrations/email/test', [Admin\IntegrationsController::class, 'sendTestEmail'], ['can:integrations.manage', 'throttle:test_email,5,3600']);

            $r->get('/users', [Admin\UsersController::class, 'index'], ['can:users.manage']);
            $r->post('/users', [Admin\UsersController::class, 'store'], ['can:users.manage']);
            $r->put('/users/{id}', [Admin\UsersController::class, 'update'], ['can:users.manage']);
            $r->post('/users/{id}/resend-invitation', [Admin\UsersController::class, 'resendInvitation'], ['can:users.manage']);
            $r->post('/users/{id}/revoke-sessions', [Admin\UsersController::class, 'revokeSessions'], ['can:users.manage']);
            $r->post('/users/{id}/reset-two-factor', [Admin\UsersController::class, 'resetTwoFactor'], ['can:users.manage']);
            $r->get('/roles', [Admin\UsersController::class, 'roles'], ['can:users.manage']);

            $r->get('/audit-logs', [Admin\AuditLogsController::class, 'index'], ['can:audit.view']);
        });
    });
};
