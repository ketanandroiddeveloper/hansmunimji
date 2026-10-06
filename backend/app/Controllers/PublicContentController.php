<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Content\ContentService;
use App\Services\Events\EventRegistrationService;
use App\Services\Media\AudioService;
use App\Services\Payments\PaymentService;
use App\Services\SettingsService;

final class PublicContentController extends Controller
{
    public function __construct(
        private ContentService $content,
        private SettingsService $settings,
        private AudioService $audio,
        private EventRegistrationService $registrations,
        private Config $config,
        private Database $db,
        private PaymentService $payments,
    ) {
    }

    public function health(Request $request): Response
    {
        $dbOk = true;
        try {
            $this->db->value('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        return $this->ok(['status' => $dbOk ? 'ok' : 'degraded', 'database' => $dbOk, 'environment' => $this->config->environment()], $dbOk ? 200 : 503);
    }

    public function settings(Request $request): Response
    {
        return $this->cached($this->settings->public() + ['environment' => $this->config->environment()], 120);
    }

    public function pages(Request $request): Response
    {
        return $this->cached($this->content->pages());
    }

    public function page(Request $request): Response
    {
        return $this->cached($this->content->page($request->param('slug')));
    }

    public function practitioner(Request $request): Response
    {
        return $this->cached($this->content->practitioner());
    }

    public function services(Request $request): Response
    {
        return $this->cached($this->content->services());
    }

    public function service(Request $request): Response
    {
        return $this->cached($this->content->service($request->param('slug')));
    }

    public function faqs(Request $request): Response
    {
        $slug = (string) $request->query('service', '');
        $serviceId = $slug !== '' ? (int) $this->db->value('SELECT id FROM services WHERE slug = ? AND is_published = 1', [$slug]) : null;

        return $this->cached($this->content->faqs($serviceId ?: null));
    }

    public function testimonials(Request $request): Response
    {
        return $this->cached($this->content->testimonials());
    }

    public function cities(Request $request): Response
    {
        return $this->cached($this->content->cities());
    }

    public function events(Request $request): Response
    {
        return $this->cached($this->content->events($request->allQuery()), 60);
    }

    public function event(Request $request): Response
    {
        return $this->cached($this->content->event($request->param('slug')), 60);
    }

    public function registerForEvent(Request $request): Response
    {
        // Never hold seats the client cannot pay for.
        $input = $request->json();
        $event = $this->db->first("SELECT id, registration_mode FROM events WHERE slug = ? AND status = 'published'", [$request->param('slug')]);
        if ($event !== null && $event['registration_mode'] === 'open') {
            $prices = array_column($this->registrations->prices((int) $event['id']), 'currency');
            $currency = is_string($input['currency'] ?? null) ? $input['currency'] : (count($prices) === 1 ? $prices[0] : null);
            $country = is_string($input['country'] ?? null) ? $input['country'] : null;
            if ($currency !== null && in_array($currency, $prices, true) && $this->payments->availableGateways($currency, $country) === []) {
                throw HttpException::conflict('payments_unavailable', 'Online payment in this currency is not available at present. Please choose another currency or contact the private office to reserve a place.');
            }
        }

        return $this->ok($this->registrations->register($request->param('slug'), $input), 201);
    }

    public function registration(Request $request): Response
    {
        $row = $this->registrations->findForClient($request->param('reference'), $this->accessToken($request));

        return $this->ok($this->registrations->presentForClient($row));
    }

    public function cancelRegistration(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['reason' => 'nullable|string|max:500']);

        return $this->ok($this->registrations->cancelByClient($request->param('reference'), $this->accessToken($request), $data['reason'] ?? null));
    }

    public function blogs(Request $request): Response
    {
        [$page, $perPage] = $this->pagination($request, 12);
        $result = $this->content->blogs($page, $perPage, $request->query('category') ? (string) $request->query('category') : null);

        return $this->cached($result['items'], 300, $this->meta($page, $perPage, $result['total']));
    }

    public function blog(Request $request): Response
    {
        return $this->cached($this->content->blog($request->param('slug')));
    }

    public function audio(Request $request): Response
    {
        return $this->cached($this->audio->publicList($request->query('category') ? (string) $request->query('category') : null));
    }

    public function audioStreamUrl(Request $request): Response
    {
        return $this->ok($this->audio->streamUrl(
            $request->param('slug'),
            $request->input('reference') ? (string) $request->input('reference') : null,
            $this->accessToken($request) ?: null,
        ));
    }

    public function audioStream(Request $request): Response
    {
        return $this->audio->stream($request->param('token'), $request);
    }
}
