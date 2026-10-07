<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use App\Core\Container;
use App\Core\Database;

/**
 * Default email copy. Inserted only when a slug is missing, so admin edits are never overwritten.
 * Placeholders are HTML-escaped at render time; `{{#if x}}…{{/if}}` renders only when x is non-empty.
 */
final class EmailTemplateSeeder
{
    public function __construct(private Container $container)
    {
    }

    /** @return iterable<string> */
    public function run(): iterable
    {
        $db = $this->container->get(Database::class);
        $now = $this->container->get(Clock::class)->nowString();
        $added = 0;
        foreach (self::templates() as $slug => [$name, $subject, $html, $variables]) {
            if ($db->value('SELECT 1 FROM email_templates WHERE slug = ?', [$slug])) {
                continue;
            }
            $db->insert('email_templates', [
                'slug' => $slug,
                'name' => $name,
                'subject' => $subject,
                'body_html' => $html,
                'body_text' => null,
                'variables' => array_values(array_unique([...$variables, 'site_name', 'contact_email'])),
                'updated_at' => $now,
            ]);
            $added++;
        }
        yield "Email templates: {$added} added.";
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: list<string>}> */
    public static function templates(): array
    {
        $booking = ['name', 'reference', 'type_title', 'starts_at_local', 'timezone', 'format', 'city', 'meet_url', 'amount', 'payment_status', 'manage_url', 'cancellation_policy'];

        return [
            'application_received' => ['Application received', 'Your private access application · {{reference}}',
                self::p('Dear {{name}},')
                . self::p('Thank you for your application. It has been received in confidence and will be reviewed personally. You can expect to hear from the private office within a few working days.')
                . self::p('Your reference is <strong>{{reference}}</strong>. Please keep it for any correspondence.')
                . '{{#if status_url}}' . self::button('status_url', 'View application status')
                . self::p('<small>This link is personal to you. Please do not forward it.</small>') . '{{/if}}',
                ['name', 'reference', 'status_url']],

            'application_draft_resume' => ['Application draft — resume link', 'Continue your application at your convenience',
                self::p('Dear {{name}},')
                . self::p('Your application has been saved. You may return to it at any time within the next 30 days using the private link below.')
                . self::button('resume_url', 'Continue application')
                . self::p('<small>This link is personal to you. Please do not forward it.</small>'),
                ['name', 'resume_url']],

            'application_approved' => ['Application approved', 'Your application has been approved',
                self::p('Dear {{name}},')
                . self::p('We are pleased to let you know that your application ({{reference}}) has been approved. The private office will be in touch shortly with an invitation to arrange your first consultation.'),
                ['name', 'reference']],

            'application_rejected' => ['Application outcome', 'Regarding your application',
                self::p('Dear {{name}},')
                . self::p('Thank you for your interest and for the care you took with your application ({{reference}}). After consideration, we are unable to offer an engagement at this time.')
                . self::p('We wish you every clarity in the path ahead.'),
                ['name', 'reference']],

            'application_info_requested' => ['Application — information requested', 'A short follow-up on your application',
                self::p('Dear {{name}},')
                . self::p('To continue reviewing your application ({{reference}}), we would be grateful for a little more information. The question is available securely through the link below.')
                . self::button('resume_url', 'View and respond'),
                ['name', 'reference', 'resume_url']],

            'application_invited' => ['Invitation to book', 'Your invitation to arrange a consultation',
                self::p('Dear {{name}},')
                . self::p('You are warmly invited to arrange your consultation. Please choose a time that suits you using your private booking link.')
                . self::button('booking_url', 'Choose a time')
                . self::p('<small>This invitation is valid until {{expires_on}} and is personal to you.</small>'),
                ['name', 'reference', 'booking_url', 'expires_on']],

            'appointment_confirmation' => ['Appointment confirmed', 'Confirmed · {{type_title}} · {{starts_at_local}}',
                self::p('Dear {{name}},')
                . self::p('Your consultation is confirmed.')
                . self::details()
                . '{{#if meet_url}}' . self::button('meet_url', 'Join Google Meet') . '{{/if}}'
                . self::button('manage_url', 'View or manage booking')
                . '{{#if cancellation_policy}}' . self::p('<small>{{cancellation_policy}}</small>') . '{{/if}}',
                $booking],

            'appointment_reserved' => ['Appointment reserved', 'Your time is reserved · {{type_title}}',
                self::p('Dear {{name}},')
                . self::p('We are holding the time below for you while you complete payment. If payment is already done, a confirmation will follow shortly and no further action is needed.')
                . self::details()
                . self::button('manage_url', 'View booking or complete payment')
                . self::p('<small>This link is personal to you; please keep this email. Unpaid reservations are released automatically.</small>'),
                $booking],

            'appointment_payment_request' => ['Appointment — payment request', 'Complete your booking · {{type_title}}',
                self::p('Dear {{name}},')
                . self::p('A consultation has been arranged for you. To confirm it, please complete payment using your secure link.')
                . self::details()
                . self::button('manage_url', 'Review and pay')
                . self::p('<small>The time is held for you once you begin payment, subject to availability.</small>'),
                $booking],

            'appointment_meeting_details' => ['Meeting details', 'Your Google Meet link · {{starts_at_local}}',
                self::p('Dear {{name}},')
                . self::p('Your private video link for {{type_title}} on {{starts_at_local}} is ready.')
                . self::button('meet_url', 'Join Google Meet'),
                ['name', 'reference', 'type_title', 'starts_at_local', 'meet_url']],

            'appointment_reminder' => ['Appointment reminder', 'Reminder · {{type_title}} in {{lead_time}}',
                self::p('Dear {{name}},')
                . self::p('A gentle reminder that your consultation begins in {{lead_time}}, on {{starts_at_local}}.')
                . self::p('A few quiet minutes beforehand, somewhere you will not be interrupted, helps the session begin well.')
                . '{{#if meet_url}}' . self::button('meet_url', 'Join Google Meet') . '{{/if}}',
                ['name', 'reference', 'type_title', 'starts_at_local', 'meet_url', 'lead_time']],

            'appointment_rescheduled' => ['Appointment rescheduled', 'Rescheduled · {{type_title}} · {{starts_at_local}}',
                self::p('Dear {{name}},')
                . self::p('Your consultation has been moved. The updated details are below.')
                . self::details()
                . self::button('manage_url', 'View booking'),
                $booking],

            'appointment_cancelled' => ['Appointment cancelled', 'Cancelled · {{reference}}',
                self::p('Dear {{name}},')
                . self::p('Your consultation ({{reference}}) scheduled for {{starts_at_local}} has been cancelled.')
                . '{{#if refund_amount}}' . self::p('A refund of <strong>{{refund_amount}}</strong> has been initiated to your original payment method. Banks typically take 5–10 working days to reflect it.') . '{{/if}}'
                . self::p('If you would like to arrange another time, simply reply to this message.'),
                [...$booking, 'refund_amount']],

            'payment_confirmation' => ['Payment received', 'Payment received · {{reference}}',
                self::p('Dear {{name}},')
                . self::p('Thank you. We have received your payment of <strong>{{amount}}</strong> for {{type_title}} ({{reference}}).'),
                $booking],

            'payment_failed' => ['Payment not completed', 'Your payment was not completed · {{reference}}',
                self::p('Dear {{name}},')
                . self::p('Your payment for {{type_title}} ({{reference}}) did not complete, and no charge has been confirmed. You may try again using your booking link, while the time remains available.')
                . self::button('manage_url', 'Return to booking'),
                $booking],

            'refund_initiated' => ['Refund initiated', 'Refund initiated · {{reference}}',
                self::p('Dear {{name}},')
                . self::p('A refund of <strong>{{refund_amount}}</strong> for {{reference}} has been requested from our payment provider. We will write again once the provider confirms it has been processed.'),
                ['name', 'reference', 'refund_amount']],

            'refund_confirmation' => ['Refund processed', 'Refund processed · {{reference}}',
                self::p('Dear {{name}},')
                . self::p('A refund of <strong>{{refund_amount}}</strong> for {{reference}} has been processed by our payment provider. Depending on your bank it may take several working days to appear.'),
                ['name', 'reference', 'refund_amount']],

            'event_registration_confirmation' => ['Gathering registration confirmed', 'Confirmed · {{event_title}}',
                self::p('Dear {{name}},')
                . self::p('Your place at <strong>{{event_title}}</strong> is confirmed.')
                . self::p('When: {{starts_at_local}}{{#if venue}}<br>Where: {{venue}}{{/if}}<br>Seats: {{seats}}<br>Reference: {{reference}}'),
                ['name', 'reference', 'event_title', 'starts_at_local', 'venue', 'seats']],

            'event_payment_request' => ['Gathering — payment request', 'Complete your registration · {{event_title}}',
                self::p('Dear {{name}},')
                . self::p('A place has been offered to you at <strong>{{event_title}}</strong> ({{starts_at_local}}). To confirm it, please complete payment of {{amount}} using your secure link{{#if hold_expires_local}} by {{hold_expires_local}}{{/if}}.')
                . self::button('payment_url', 'Complete registration'),
                ['name', 'reference', 'event_title', 'starts_at_local', 'amount', 'payment_url', 'hold_expires_local']],

            'event_waitlisted' => ['Gathering — waitlist', 'On the waitlist · {{event_title}}',
                self::p('Dear {{name}},')
                . self::p('<strong>{{event_title}}</strong> ({{starts_at_local}}) is currently full, and you have been placed on the waitlist. No payment has been taken.')
                . self::p('If a place becomes available, we will email you a personal link to confirm it. You can check your position or withdraw at any time.')
                . self::button('manage_url', 'View registration'),
                ['name', 'reference', 'event_title', 'starts_at_local', 'manage_url']],

            'event_waitlist_offer' => ['Gathering — place available', 'A place is available · {{event_title}}',
                self::p('Dear {{name}},')
                . self::p('A place has become available at <strong>{{event_title}}</strong> ({{starts_at_local}}). It is held for you{{#if hold_expires_local}} until {{hold_expires_local}}{{/if}}.')
                . '{{#if amount}}' . self::p('To confirm it, please complete payment of {{amount}} using your secure link.') . '{{/if}}'
                . self::button('payment_url', 'Confirm my place'),
                ['name', 'reference', 'event_title', 'starts_at_local', 'amount', 'payment_url', 'hold_expires_local']],

            'event_registration_cancelled' => ['Gathering registration cancelled', 'Cancelled · {{event_title}}',
                self::p('Dear {{name}},')
                . self::p('Your registration ({{reference}}) for <strong>{{event_title}}</strong> on {{starts_at_local}} has been cancelled.')
                . '{{#if reason}}' . self::p('{{reason}}') . '{{/if}}'
                . '{{#if refund_amount}}' . self::p('A refund of <strong>{{refund_amount}}</strong> has been initiated to your original payment method. Banks typically take 5–10 working days to reflect it.') . '{{/if}}',
                ['name', 'reference', 'event_title', 'starts_at_local', 'refund_amount', 'reason']],

            'event_reminder' => ['Gathering reminder', 'Reminder · {{event_title}} · {{starts_at_local}}',
                self::p('Dear {{name}},')
                . self::p('A gentle reminder that <strong>{{event_title}}</strong> begins on {{starts_at_local}}.')
                . self::p('{{#if venue}}Where: {{venue}}<br>{{/if}}Seats: {{seats}}<br>Reference: {{reference}}'),
                ['name', 'reference', 'event_title', 'starts_at_local', 'venue', 'seats']],

            'admin_new_application' => ['Admin — new application', '{{kind}} · {{reference}}',
                self::p('{{kind}}: <strong>{{reference}}</strong> ({{consultation_type}}).')
                . self::p('Sign in to the administration panel to review it. Details are intentionally not included in email.'),
                ['reference', 'consultation_type', 'kind']],

            'admin_new_booking' => ['Admin — new booking', 'New booking · {{reference}}',
                self::p('New booking <strong>{{reference}}</strong>: {{type_title}} on {{starts_at_local}}.')
                . self::p('Client details are available in the administration panel.'),
                ['reference', 'type_title', 'starts_at_local']],

            'admin_new_event_registration' => ['Admin — new event registration', 'New registration · {{event_title}}',
                self::p('New registration <strong>{{reference}}</strong> for {{event_title}} on {{starts_at_local}}.')
                . self::p('Status: {{status}}<br>Seats: {{seats}}')
                . self::p('Guest details are available in the administration panel.'),
                ['reference', 'event_title', 'starts_at_local', 'status', 'seats']],

            'admin_payment_succeeded' => ['Admin — payment received', 'Payment received · {{reference}}',
                self::p('A payment of <strong>{{amount}}</strong> was received via {{gateway}} for {{kind}} <strong>{{reference}}</strong>.')
                . self::p('Payment reference: {{payment_reference}}')
                . self::p('Details are available in the administration panel.'),
                ['reference', 'payment_reference', 'amount', 'gateway', 'kind']],

            'admin_payment_failed' => ['Admin — payment failed', 'Payment failed · {{reference}}',
                self::p('A payment attempt of <strong>{{amount}}</strong> via {{gateway}} for {{kind}} <strong>{{reference}}</strong> failed.')
                . self::p('Payment reference: {{payment_reference}}<br>The guest can retry from their secure booking link until the hold expires.')
                . self::p('Details are available in the administration panel.'),
                ['reference', 'payment_reference', 'amount', 'gateway', 'kind']],

            'admin_integration_failure' => ['Admin — integration failure', 'Action needed · {{integration}}',
                self::p('An automated task needs attention.')
                . self::p('Integration: <strong>{{integration}}</strong>{{#if reference}}<br>Reference: {{reference}}{{/if}}<br>Detail: {{error}}')
                . self::p('Open Integrations in the administration panel to retry.'),
                ['integration', 'reference', 'error']],

            'password_reset' => ['Password reset', 'Reset your password',
                self::p('Dear {{name}},')
                . self::p('A password reset was requested for your administrator account. The link below is valid for 30 minutes.')
                . self::button('reset_url', 'Choose a new password')
                . self::p('<small>If you did not request this, you can ignore this message; your password will not change.</small>'),
                ['name', 'reset_url']],

            'admin_invitation' => ['Administrator invitation', 'You have been invited to the administration panel',
                self::p('Dear {{name}},')
                . self::p('An administrator account has been created for you. Please set your password within 72 hours using the link below, then enable two-factor authentication.')
                . self::button('setup_url', 'Set your password'),
                ['name', 'setup_url']],

            'privacy_request_verification' => ['Privacy request verification', 'Please confirm your privacy request',
                self::p('We received a request for {{request_type}} associated with this email address.')
                . self::p('To protect your information, please confirm the request was made by you.')
                . self::button('verify_url', 'Confirm request')
                . self::p('<small>If you did not make this request, no action is needed.</small>'),
                ['request_type', 'verify_url']],
        ];
    }

    private static function p(string $html): string
    {
        return '<p style="margin:0 0 18px;">' . $html . '</p>';
    }

    private static function button(string $urlVar, string $label): string
    {
        return '<p style="margin:28px 0;"><a href="{{' . $urlVar . '}}" style="display:inline-block;padding:14px 28px;border:1px solid #C9B07A;color:#E6D7B0;text-decoration:none;font-family:Helvetica,Arial,sans-serif;font-size:12px;letter-spacing:0.18em;text-transform:uppercase;">' . $label . '</a></p>';
    }

    private static function details(): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;font-size:15px;line-height:1.8;color:#E9E2D3;">'
            . '<tr><td style="padding-right:24px;color:#8A94A6;">Consultation</td><td>{{type_title}}</td></tr>'
            . '<tr><td style="padding-right:24px;color:#8A94A6;">When</td><td>{{starts_at_local}}</td></tr>'
            . '<tr><td style="padding-right:24px;color:#8A94A6;">Format</td><td>{{format}}{{#if city}} · {{city}}{{/if}}</td></tr>'
            . '<tr><td style="padding-right:24px;color:#8A94A6;">Reference</td><td>{{reference}}</td></tr>'
            . '{{#if amount}}<tr><td style="padding-right:24px;color:#8A94A6;">Amount</td><td>{{amount}}</td></tr>{{/if}}'
            . '</table>';
    }
}
