<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use App\Services\Events\EventRegistrationService;
use App\Services\Media\MediaService;
use App\Services\Payments\PaymentRouter;

/**
 * Read model for the public site. Only published content is returned; media ids found anywhere
 * in structured content are hydrated into responsive image descriptors.
 */
final class ContentService
{
    public function __construct(
        private Database $db,
        private Clock $clock,
        private MediaService $media,
        private EventRegistrationService $registrations,
        private PaymentRouter $router,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function pages(): array
    {
        return array_map(static fn ($r) => ['slug' => $r['slug'], 'title' => $r['title'], 'type' => $r['type'], 'updated_at' => Clock::iso($r['updated_at'])],
            $this->db->all("SELECT slug, title, type, updated_at FROM pages WHERE status = 'published' ORDER BY type, title"));
    }

    /** @return array<string, mixed> */
    public function page(string $slug): array
    {
        $page = $this->db->first("SELECT * FROM pages WHERE slug = ? AND status = 'published'", [$slug]) ?? throw HttpException::notFound('Page not found.');
        $sections = json_decode((string) ($page['sections'] ?? 'null'), true) ?: [];

        return [
            'slug' => $page['slug'],
            'type' => $page['type'],
            'title' => $page['title'],
            'sections' => $this->hydrateMedia($sections),
            'body' => $page['body'],
            'updated_at' => Clock::iso($page['updated_at']),
            'seo' => $this->seo(match (true) {
                $page['slug'] === 'home' => '/',
                $page['type'] === 'legal' => '/legal/' . $page['slug'],
                default => '/' . $page['slug'],
            }),
        ];
    }

    /** @return array<string, mixed> */
    public function practitioner(): array
    {
        $profile = $this->db->first('SELECT * FROM practitioner_profiles WHERE is_primary = 1 LIMIT 1') ?? throw HttpException::notFound('Profile not found.');
        $media = $this->media->presentMany([(int) $profile['portrait_media_id'], (int) $profile['secondary_media_id']]);
        $qualifications = $this->db->all(
            'SELECT kind, title, institution, year, description, url FROM qualifications WHERE practitioner_id = ? AND is_published = 1 ORDER BY kind, sort_order, year DESC',
            [$profile['id']],
        );
        $grouped = [];
        foreach ($qualifications as $q) {
            $grouped[$q['kind']][] = ['title' => $q['title'], 'institution' => $q['institution'], 'year' => $q['year'] !== null ? (int) $q['year'] : null, 'description' => $q['description'], 'url' => $q['url']];
        }

        return [
            'slug' => $profile['slug'],
            'full_name' => $profile['full_name'],
            'honorific' => $profile['honorific'],
            'title' => $profile['title'],
            'short_bio' => $profile['short_bio'],
            'biography' => $profile['biography'],
            'philosophy' => $profile['philosophy'],
            'approach' => $profile['approach'],
            'expertise' => json_decode((string) ($profile['expertise'] ?? '[]'), true) ?: [],
            'experience' => json_decode((string) ($profile['experience'] ?? '[]'), true) ?: [],
            'same_as' => json_decode((string) ($profile['same_as'] ?? '[]'), true) ?: [],
            'portrait' => $media[(int) $profile['portrait_media_id']] ?? null,
            'secondary_image' => $media[(int) $profile['secondary_media_id']] ?? null,
            'qualifications' => $grouped,
            'gallery' => array_values($this->media->presentMany(array_column($this->db->all("SELECT id FROM media WHERE category = 'gallery' AND disk = 'public' ORDER BY id"), 'id'))),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function services(): array
    {
        $rows = $this->db->all("SELECT * FROM services WHERE is_published = 1 ORDER BY sort_order, id");
        $media = $this->media->presentMany(array_column($rows, 'cover_media_id'));

        return array_map(fn ($r) => $this->presentServiceSummary($r, $media), $rows);
    }

    /** @return array<string, mixed> */
    public function service(string $slug): array
    {
        $row = $this->db->first('SELECT s.*, c.name AS category_name FROM services s LEFT JOIN service_categories c ON c.id = s.category_id WHERE s.slug = ? AND s.is_published = 1', [$slug])
            ?? throw HttpException::notFound('Service not found.');
        $media = $this->media->presentMany([(int) $row['cover_media_id']]);

        return $this->presentServiceSummary($row, $media) + [
            'category' => $row['category_name'],
            'body' => $row['body'],
            'highlights' => json_decode((string) ($row['highlights'] ?? '[]'), true) ?: [],
            'offerings' => json_decode((string) ($row['offerings'] ?? '[]'), true) ?: [],
            'faqs' => $this->faqs((int) $row['id']),
            'seo' => $this->seo('/practice/' . $row['slug']),
        ];
    }

    /** @return list<array{question: string, answer: string}> */
    public function faqs(?int $serviceId): array
    {
        $rows = $serviceId === null
            ? $this->db->all('SELECT question, answer FROM faqs WHERE service_id IS NULL AND is_published = 1 ORDER BY sort_order, id')
            : $this->db->all('SELECT question, answer FROM faqs WHERE service_id = ? AND is_published = 1 ORDER BY sort_order, id', [$serviceId]);

        return array_map(static fn ($r) => ['question' => $r['question'], 'answer' => $r['answer']], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function testimonials(): array
    {
        return array_map(static fn ($r) => ['quote' => $r['quote'], 'attribution' => $r['attribution'], 'role' => $r['role']],
            $this->db->all('SELECT quote, attribution, role FROM testimonials WHERE is_published = 1 AND is_authorized = 1 ORDER BY sort_order, id'));
    }

    /** @return list<array<string, mixed>> */
    public function cities(): array
    {
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'country' => $r['country'], 'timezone' => $r['timezone']],
            $this->db->all('SELECT id, name, country, timezone FROM cities WHERE is_active = 1 ORDER BY sort_order, name'));
    }

    /** @return list<array<string, mixed>> */
    public function events(array $filters): array
    {
        $where = ["e.status = 'published'"];
        $params = [];
        if (($filters['when'] ?? 'upcoming') === 'upcoming') {
            $where[] = 'e.ends_at >= ?';
            $params[] = $this->clock->nowString();
        } else {
            $where[] = 'e.ends_at < ?';
            $params[] = $this->clock->nowString();
        }
        if (!empty($filters['category'])) {
            $where[] = 'e.category = ?';
            $params[] = (string) $filters['category'];
        }
        if (!empty($filters['city'])) {
            $where[] = 'c.name = ?';
            $params[] = (string) $filters['city'];
        }
        $rows = $this->db->all(
            'SELECT e.*, c.name AS city_name, c.country AS city_country FROM events e LEFT JOIN cities c ON c.id = e.city_id WHERE '
            . implode(' AND ', $where) . ' ORDER BY e.starts_at ' . (($filters['when'] ?? 'upcoming') === 'upcoming' ? 'ASC' : 'DESC') . ' LIMIT 50',
            $params,
        );
        $media = $this->media->presentMany(array_column($rows, 'cover_media_id'));

        return array_map(fn ($r) => $this->presentEvent($r, $media), $rows);
    }

    /** @return array<string, mixed> */
    public function event(string $slug): array
    {
        $row = $this->db->first(
            "SELECT e.*, c.name AS city_name, c.country AS city_country FROM events e LEFT JOIN cities c ON c.id = e.city_id WHERE e.slug = ? AND e.status IN ('published','cancelled')",
            [$slug],
        ) ?? throw HttpException::notFound('Gathering not found.');
        $media = $this->media->presentMany([(int) $row['cover_media_id']]);

        return $this->presentEvent($row, $media) + [
            'description' => $row['description'],
            'requirements' => $row['requirements'],
            'address' => $row['address'],
            'cancellation_policy' => $row['cancellation_policy'],
            'cancellation_window_hours' => (int) $row['cancellation_window_hours'],
            'refund_on_cancel_percent' => (int) $row['refund_on_cancel_percent'],
            'seo' => $this->seo('/gatherings/' . $row['slug']),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function blogs(int $page, int $perPage, ?string $category): array
    {
        $where = "b.status = 'published' AND b.published_at <= ?";
        $params = [$this->clock->nowString()];
        if ($category) {
            $where .= ' AND bc.slug = ?';
            $params[] = $category;
        }
        $base = " FROM blog_posts b LEFT JOIN blog_categories bc ON bc.id = b.category_id LEFT JOIN practitioner_profiles p ON p.id = b.author_id WHERE {$where}";
        $total = (int) $this->db->value('SELECT COUNT(*)' . $base, $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all("SELECT b.*, bc.slug AS category_slug, bc.name AS category_name, p.full_name AS author_name, p.slug AS author_slug{$base} ORDER BY b.published_at DESC LIMIT {$perPage} OFFSET {$offset}", $params);
        $media = $this->media->presentMany(array_column($rows, 'cover_media_id'));

        return ['items' => array_map(fn ($r) => $this->presentBlogSummary($r, $media), $rows), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function blog(string $slug): array
    {
        $row = $this->db->first(
            "SELECT b.*, bc.slug AS category_slug, bc.name AS category_name, p.full_name AS author_name, p.slug AS author_slug
             FROM blog_posts b LEFT JOIN blog_categories bc ON bc.id = b.category_id LEFT JOIN practitioner_profiles p ON p.id = b.author_id
             WHERE b.slug = ? AND b.status = 'published' AND b.published_at <= ?",
            [$slug, $this->clock->nowString()],
        ) ?? throw HttpException::notFound('Article not found.');
        $media = $this->media->presentMany([(int) $row['cover_media_id']]);

        return $this->presentBlogSummary($row, $media) + ['body' => $row['body'], 'updated_at' => Clock::iso($row['updated_at']), 'seo' => $this->seo('/journal/' . $row['slug'])];
    }

    /** @return array<string, mixed>|null */
    public function seo(string $path): ?array
    {
        $row = $this->db->first('SELECT title, description, canonical, og_image_media_id, robots FROM seo_metadata WHERE path = ?', [$path]);
        if ($row === null) {
            return null;
        }
        $og = $row['og_image_media_id'] ? ($this->media->presentMany([(int) $row['og_image_media_id']])[(int) $row['og_image_media_id']] ?? null) : null;

        return ['title' => $row['title'], 'description' => $row['description'], 'canonical' => $row['canonical'], 'robots' => $row['robots'], 'image' => $og['url'] ?? null];
    }

    /**
     * Recursively replaces `*media_id` keys with hydrated `*media` descriptors.
     *
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    public function hydrateMedia(array $data): array
    {
        $ids = [];
        array_walk_recursive($data, static function ($value, $key) use (&$ids) {
            if (is_string($key) && str_ends_with($key, 'media_id') && is_numeric($value)) {
                $ids[] = (int) $value;
            }
        });
        $media = $this->media->presentMany($ids);

        $walk = static function (array $node) use (&$walk, $media): array {
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $node[$key] = $walk($value);
                } elseif (is_string($key) && str_ends_with($key, 'media_id')) {
                    $node[substr($key, 0, -3)] = is_numeric($value) ? ($media[(int) $value] ?? null) : null;
                }
            }

            return $node;
        };

        return $walk($data);
    }

    private function presentServiceSummary(array $r, array $media): array
    {
        return [
            'slug' => $r['slug'],
            'numeral' => $r['numeral'],
            'title' => $r['title'],
            'subtitle' => $r['subtitle'],
            'summary' => $r['summary'],
            'booking_mode' => $r['booking_mode'],
            'duration_label' => $r['duration_label'],
            'price_display' => $r['price_display'],
            'is_featured' => (bool) $r['is_featured'],
            'cover' => $media[(int) $r['cover_media_id']] ?? null,
        ];
    }

    private function presentEvent(array $r, array $media): array
    {
        $taken = $this->registrations->seatsTaken((int) $r['id']);
        $seatsLeft = $r['seat_quota'] !== null ? max(0, (int) $r['seat_quota'] - $taken) : null;
        $prices = array_map(
            fn (array $p) => $p + ['payable' => $this->router->route($p['currency']) !== []],
            $this->registrations->prices((int) $r['id']),
        );

        return [
            'slug' => $r['slug'],
            'title' => $r['title'],
            'category' => $r['category'],
            'summary' => $r['summary'],
            'city' => $r['city_name'] ? ['name' => $r['city_name'], 'country' => $r['city_country']] : null,
            'venue' => $r['venue'],
            'starts_at' => Clock::iso($r['starts_at']),
            'ends_at' => Clock::iso($r['ends_at']),
            'timezone' => $r['timezone'],
            'registration_mode' => $r['registration_mode'],
            'registration_state' => $this->registrations->registrationState($r, $taken),
            'registration_opens_at' => Clock::iso($r['registration_opens_at']),
            'registration_closes_at' => Clock::iso($r['registration_closes_at']),
            'waitlist_enabled' => (bool) $r['waitlist_enabled'],
            'seats_left' => $seatsLeft,
            'price' => $prices[0] ?? null,
            'prices' => $prices,
            'tax' => (int) $r['tax_rate_bp'] > 0 ? ['label' => $r['tax_label'], 'rate_bp' => (int) $r['tax_rate_bp'], 'inclusive' => (bool) $r['tax_inclusive']] : null,
            'status' => $r['status'],
            'is_featured' => (bool) $r['is_featured'],
            'cover' => $media[(int) $r['cover_media_id']] ?? null,
        ];
    }

    private function presentBlogSummary(array $r, array $media): array
    {
        return [
            'slug' => $r['slug'],
            'title' => $r['title'],
            'excerpt' => $r['excerpt'],
            'category' => $r['category_slug'] ? ['slug' => $r['category_slug'], 'name' => $r['category_name']] : null,
            'author' => $r['author_name'] ? ['name' => $r['author_name'], 'slug' => $r['author_slug']] : null,
            'published_at' => Clock::iso($r['published_at']),
            'reading_minutes' => $r['reading_minutes'] !== null ? (int) $r['reading_minutes'] : null,
            'tags' => json_decode((string) ($r['tags'] ?? '[]'), true) ?: [],
            'cover' => $media[(int) $r['cover_media_id']] ?? null,
        ];
    }
}
