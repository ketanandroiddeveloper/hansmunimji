<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Clock;
use App\Core\Container;
use App\Core\Database;
use App\Services\Media\MediaService;

/**
 * Initial, editable site content. Copy describes the practice's offerings only: no credentials,
 * testimonials, outcomes or medical claims are invented. Biography and qualifications are left
 * for the practice to complete in the administration panel.
 */
final class ContentSeeder
{
    /** filename => [alt, category] — photographs supplied by the practice (Google Drive). */
    private const MEDIA = [
        'IMG_9697.jpg' => ['Hansmuniji seated cross-legged in a pale yellow kurta, palms joined in greeting', 'portrait'],
        'IMG_9650.jpg' => ['Hansmuniji in white, seated in meditation with palms joined at the heart', 'portrait'],
        'IMG_9648.jpg' => ['Hansmuniji in white, seated cross-legged with hands resting on the knees, smiling', 'portrait'],
        'IMG_9652.jpg' => ['Hansmuniji seated in white with arms raised overhead, palms joined', 'meditation'],
        'IMG_9657.jpg' => ['Hansmuniji in white, seated with palms joined and a gentle expression', 'portrait'],
        'IMG_9698.jpg' => ['Hansmuniji in a yellow kurta, seated in meditation with hands in mudra on the knees', 'meditation'],
        'IMG_9701.jpg' => ['Hansmuniji with eyes closed and face lifted, absorbed in meditation', 'meditation'],
        'IMG_9679.jpg' => ['Hansmuniji in meditation, eyes closed and face turned upward', 'meditation'],
        'IMG_9670.jpg' => ['Hansmuniji seated upright in meditation, hands resting in mudra', 'meditation'],
        'IMG_9672.jpg' => ['Hansmuniji seated with one hand resting over the heart', 'catharsis'],
        'IMG_9680.jpg' => ['Hansmuniji seated with arms extended overhead in a stretch', 'catharsis'],
        'IMG_9682.jpg' => ['Hansmuniji seated behind a pair of hand drums', 'sound'],
        'IMG_9685.jpg' => ['Hansmuniji seated in meditation wearing headphones', 'audio'],
        'IMG_9692.jpg' => ['Hansmuniji seated with hands gently clasped, smiling warmly', 'portrait'],
        'IMG_9702.jpg' => ['Hansmuniji holding the hands of a seated participant during a guided session', 'session'],
        'IMG_9709.jpg' => ['Hansmuniji extending a hand towards a participant\'s forehead during energy work', 'session'],
        'IMG_9711.jpg' => ['Hansmuniji placing a hand near a participant\'s shoulder during a session', 'session'],
        'IMG_9716.jpg' => ['Hansmuniji and a participant seated facing one another in conversation', 'session'],
        'IMG_9718.jpg' => ['Hansmuniji and a participant seated side by side on a white rug', 'session'],
        'IMG_9719.jpg' => ['Hansmuniji and a participant seated side by side with palms joined', 'session'],
    ];

    private Database $db;
    private string $now;

    /** @var array<string, int> */
    private array $media = [];

    public function __construct(private Container $container)
    {
        $this->db = $container->get(Database::class);
        $this->now = $container->get(Clock::class)->nowString();
    }

    /** @return iterable<string> */
    public function run(): iterable
    {
        yield $this->importMedia();
        yield $this->cities();
        yield $this->practitioner();
        yield $this->services();
        yield $this->appointmentTypes();
        yield $this->pages();
        yield $this->faqs();
    }

    private function importMedia(): string
    {
        $service = $this->container->get(MediaService::class);
        $dir = dirname(__DIR__) . '/seeds/assets';
        $added = 0;
        foreach (self::MEDIA as $file => [$alt, $category]) {
            $existing = $this->db->value('SELECT id FROM media WHERE original_name = ?', [$file]);
            if ($existing) {
                $this->media[$file] = (int) $existing;
                continue;
            }
            if (!is_file("{$dir}/{$file}")) {
                continue;
            }
            $item = $service->storeFromPath("{$dir}/{$file}", $file, ['alt' => $alt, 'category' => $category]);
            $this->media[$file] = (int) $item['id'];
            $added++;
        }

        return "Media: {$added} imported (" . count($this->media) . ' available).';
    }

    private function m(string $file): ?int
    {
        return $this->media[$file] ?? null;
    }

    private function cities(): string
    {
        $cities = [
            ['Surat', 'IN', 'Asia/Kolkata'], ['Mumbai', 'IN', 'Asia/Kolkata'], ['New Delhi', 'IN', 'Asia/Kolkata'],
            ['Dubai', 'AE', 'Asia/Dubai'], ['London', 'GB', 'Europe/London'],
        ];
        $added = 0;
        foreach ($cities as $i => [$name, $country, $tz]) {
            if (!$this->db->value('SELECT 1 FROM cities WHERE name = ? AND country = ?', [$name, $country])) {
                $this->db->insert('cities', ['name' => $name, 'country' => $country, 'timezone' => $tz, 'is_active' => 1, 'sort_order' => $i + 1]);
                $added++;
            }
        }

        return "Cities: {$added} added.";
    }

    private function practitioner(): string
    {
        if ($this->db->value('SELECT 1 FROM practitioner_profiles WHERE is_primary = 1')) {
            return 'Practitioner: exists.';
        }
        $this->db->insert('practitioner_profiles', [
            'slug' => 'hansmuniji',
            'full_name' => 'Hansmuniji',
            'title' => 'Meditation guide and private advisor',
            'short_bio' => 'Hansmuniji guides individuals and leaders in meditation, breath and contemplative practice drawn from the Vedic tradition, alongside private conversations on clarity, composure and the inner side of decision-making.',
            'biography' => null,
            'philosophy' => '<p>Stillness is not an escape from responsibility; it is the ground from which responsibility is carried well. The practice begins with attention — where it rests, what pulls it, and how it can be returned.</p><p>Every engagement is private, unhurried and shaped around the person rather than a programme.</p>',
            'approach' => '<p>Sessions combine guided meditation, breath, sound and reflective conversation. Nothing is prescribed in advance: the first meeting is an exchange to understand what is being asked for, and whether this work is the right fit.</p>',
            'expertise' => ['Vedic meditation', 'Breath and sound practice', 'Contemplative inquiry', 'Executive reflection'],
            'experience' => [],
            'portrait_media_id' => $this->m('IMG_9648.jpg'),
            'secondary_media_id' => $this->m('IMG_9698.jpg'),
            'same_as' => [],
            'is_primary' => 1,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);

        return 'Practitioner: created (biography and qualifications to be completed by the practice).';
    }

    private function services(): string
    {
        $disclaimer = '<p><em>These practices are contemplative and educational. They are not a substitute for diagnosis or treatment by a qualified medical or mental-health professional.</em></p>';
        $services = [
            [
                'slug' => 'gravity-celestial-meditation', 'numeral' => 'I', 'title' => 'Gravity Celestial Meditation',
                'subtitle' => 'Guided meditation rooted in the Vedic tradition',
                'summary' => 'A guided practice of breath, mantra and sound that settles the mind and returns attention to its centre — offered privately, in small circles, and on full-moon and new-moon evenings.',
                'body' => '<p>Gravity Celestial Meditation is an unhurried, guided practice. Each session moves from the body to the breath to stillness, using mantra and sound as anchors for attention.</p><p>Sessions are held one-to-one or in small, closed circles. Seasonal gatherings follow the lunar calendar, with evenings on Purnima (full moon) and Amavasya (new moon).</p>' . $disclaimer,
                'highlights' => ['Private one-to-one sessions', 'Full-moon and new-moon circles', 'Breath, mantra and sound', 'Online or in person'],
                'offerings' => [
                    ['title' => 'Private session', 'description' => 'A one-to-one guided meditation shaped around your pace and intention.'],
                    ['title' => 'Lunar circle', 'description' => 'A small, closed group practice held on full-moon and new-moon evenings.'],
                    ['title' => 'Guided audio', 'description' => 'Recorded practices for continuing between sessions.'],
                ],
                'cover' => 'IMG_9652.jpg', 'booking_mode' => 'direct', 'duration_label' => '60 minutes', 'featured' => 1,
            ],
            [
                'slug' => 'executive-mind-architecture', 'numeral' => 'II', 'title' => 'Executive Mind Architecture',
                'subtitle' => 'Private advisory on attention, composure and decision-making',
                'summary' => 'Confidential conversations for leaders on the inner side of leadership — how attention is spent, how pressure is held, and how decisions are reached with a clear mind.',
                'body' => '<p>Executive Mind Architecture brings contemplative practice into the realities of leadership. Conversations explore patterns of attention, reactivity and recovery, and build simple daily practices that fit a demanding schedule.</p><p>Engagements are by application, so that each one begins with a genuine understanding of what is being asked for.</p>' . $disclaimer,
                'highlights' => ['By application', 'Strictly confidential', 'Online, by telephone or in person', 'Practices designed around your schedule'],
                'offerings' => [
                    ['title' => 'Initial consultation', 'description' => 'An extended first conversation to understand context, intention and fit.'],
                    ['title' => 'Ongoing advisory', 'description' => 'A rhythm of private sessions agreed together after the first meeting.'],
                ],
                'cover' => 'IMG_9716.jpg', 'booking_mode' => 'application', 'duration_label' => '90 minutes', 'featured' => 1,
            ],
            [
                'slug' => 'catharsis-energetic-detox', 'numeral' => 'III', 'title' => 'Catharsis & Energetic Detox',
                'subtitle' => 'Guided release through breath, movement and sound',
                'summary' => 'A structured, guided process that uses breath, movement and sound to let accumulated tension be expressed and released, followed by stillness and integration.',
                'body' => '<p>Catharsis sessions follow a deliberate arc: preparation, active release, and a long period of quiet integration. The process is guided throughout and adapted to the individual.</p><p>Because the work can be physically and emotionally intense, it is offered by application and after an initial conversation.</p>' . $disclaimer,
                'highlights' => ['By application', 'Preparation and integration included', 'Private sessions'],
                'offerings' => [
                    ['title' => 'Private catharsis session', 'description' => 'A guided session with preparation, release and integration.'],
                ],
                'cover' => 'IMG_9672.jpg', 'booking_mode' => 'application', 'duration_label' => '90 minutes', 'featured' => 1,
            ],
            [
                'slug' => 'meet-on-demand', 'numeral' => 'IV', 'title' => 'Meet-On-Demand VIP Concierge',
                'subtitle' => 'Private, in-person meetings in selected cities',
                'summary' => 'Discreet in-person sessions arranged around your schedule in Surat, Mumbai, New Delhi, Dubai and London, with further cities by arrangement.',
                'body' => '<p>For those who prefer to meet in person, sessions can be arranged at a private location of your choosing in selected cities. Timing, venue and format are agreed in advance with the private office.</p><p>Requests are made through the private access application.</p>',
                'highlights' => ['Surat · Mumbai · New Delhi · Dubai · London', 'Venue of your choosing', 'Arranged through the private office'],
                'offerings' => [
                    ['title' => 'In-person session', 'description' => 'A private session at a location agreed in advance.'],
                    ['title' => 'Extended engagement', 'description' => 'Multi-day arrangements for retreats or family sessions, by discussion.'],
                ],
                'cover' => 'IMG_9718.jpg', 'booking_mode' => 'application', 'duration_label' => 'By arrangement', 'featured' => 1,
            ],
            [
                'slug' => 'keynote-and-events', 'numeral' => 'V', 'title' => 'Keynote Speaker & Divine Unique Events',
                'subtitle' => 'Talks, guided experiences and private gatherings',
                'summary' => 'Keynotes, guided meditations for leadership gatherings, and bespoke contemplative events for organisations, communities and private occasions.',
                'body' => '<p>Talks and guided experiences can be designed for leadership offsites, conferences, private celebrations and community gatherings — from a short guided meditation to a full evening of sound and stillness.</p><p>Please share the occasion, audience and dates through the inquiry form and the private office will respond.</p>',
                'highlights' => ['Keynotes and guided sessions', 'Leadership offsites and summits', 'Private and community gatherings'],
                'offerings' => [
                    ['title' => 'Keynote', 'description' => 'A talk on attention, stillness and leadership, with an optional guided practice.'],
                    ['title' => 'Guided experience', 'description' => 'A meditation or sound session for a group or event.'],
                    ['title' => 'Bespoke gathering', 'description' => 'A contemplative event designed around a particular occasion.'],
                ],
                'cover' => 'IMG_9682.jpg', 'booking_mode' => 'inquiry', 'duration_label' => 'By arrangement', 'featured' => 1,
            ],
        ];

        $added = 0;
        foreach ($services as $i => $s) {
            if ($this->db->value('SELECT 1 FROM services WHERE slug = ?', [$s['slug']])) {
                continue;
            }
            $this->db->insert('services', [
                'slug' => $s['slug'], 'numeral' => $s['numeral'], 'title' => $s['title'], 'subtitle' => $s['subtitle'],
                'summary' => $s['summary'], 'body' => $s['body'], 'highlights' => $s['highlights'], 'offerings' => $s['offerings'],
                'cover_media_id' => $this->m($s['cover']), 'booking_mode' => $s['booking_mode'], 'duration_label' => $s['duration_label'],
                'price_display' => null, 'is_published' => 1, 'is_featured' => $s['featured'], 'sort_order' => $i + 1,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $added++;
        }

        return "Services: {$added} added.";
    }

    /** Types are created inactive and unpriced: pricing and availability are business decisions for the admin. */
    private function appointmentTypes(): string
    {
        $types = [
            ['celestial-meditation-private', 'gravity-celestial-meditation', 'Private meditation session', 60, ['google_meet', 'in_person'], 0],
            ['executive-mind-consultation', 'executive-mind-architecture', 'Executive Mind Architecture — initial consultation', 90, ['google_meet', 'phone', 'in_person'], 1],
            ['catharsis-private-session', 'catharsis-energetic-detox', 'Private catharsis session', 90, ['in_person', 'google_meet'], 1],
            ['concierge-in-person', 'meet-on-demand', 'In-person concierge meeting', 120, ['in_person'], 1],
        ];
        $added = 0;
        foreach ($types as $i => [$slug, $service, $title, $minutes, $formats, $approval]) {
            if ($this->db->value('SELECT 1 FROM appointment_types WHERE slug = ?', [$slug])) {
                continue;
            }
            $this->db->insert('appointment_types', [
                'service_id' => $this->db->value('SELECT id FROM services WHERE slug = ?', [$service]),
                'slug' => $slug, 'title' => $title, 'duration_minutes' => $minutes, 'formats' => $formats,
                'requires_payment' => 1, 'requires_approval' => $approval, 'is_active' => 0, 'is_public' => $approval ? 0 : 1,
                'cancellation_policy' => 'Cancellations made at least 48 hours before the start time are refunded in full. Later cancellations are not refundable.',
                'reschedule_policy' => 'You may reschedule up to 24 hours before the start time, up to two times.',
                'sort_order' => $i + 1, 'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $added++;
        }

        return "Appointment types: {$added} added (inactive until prices and availability are set).";
    }

    private function pages(): string
    {
        $pages = [
            ['home', 'page', 'Home', 'published', $this->homeSections(), null],
            ['privacy-policy', 'legal', 'Privacy Policy', 'draft', null, self::legalDraft('privacy policy')],
            ['terms', 'legal', 'Terms of Engagement', 'draft', null, self::legalDraft('terms of engagement')],
            ['cookie-policy', 'legal', 'Cookie Policy', 'draft', null, self::legalDraft('cookie policy')],
            ['disclaimer', 'legal', 'Disclaimer', 'draft', null, self::legalDraft('disclaimer')],
        ];
        $added = 0;
        foreach ($pages as [$slug, $type, $title, $status, $sections, $body]) {
            if ($this->db->value('SELECT 1 FROM pages WHERE slug = ?', [$slug])) {
                continue;
            }
            $this->db->insert('pages', [
                'slug' => $slug, 'type' => $type, 'title' => $title, 'sections' => $sections, 'body' => $body, 'status' => $status,
                'published_at' => $status === 'published' ? $this->now : null, 'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $added++;
        }

        return "Pages: {$added} added (legal pages are drafts pending qualified review).";
    }

    /** @return array<string, mixed> */
    private function homeSections(): array
    {
        return [
            'hero' => [
                'eyebrow' => 'Private advisory · By application',
                'title' => 'Stillness, for those who carry the most.',
                'subtitle' => 'Meditation, contemplative practice and private counsel for leaders, founders and families — offered in confidence, at your pace.',
                'primary_cta' => ['label' => 'Request private access', 'href' => '/private-access'],
                'secondary_cta' => ['label' => 'Explore the practice', 'href' => '/practice'],
                'image_media_id' => $this->m('IMG_9697.jpg'),
            ],
            'introduction' => [
                'eyebrow' => 'The practitioner',
                'title' => 'A quiet room in a loud world.',
                'body' => "Hansmuniji works with a small number of people at a time. The work draws on Vedic meditation, breath and sound, and on long, unhurried conversation.\n\nThere is no programme to follow and nothing to perform — only attention, returned to where it belongs.",
                'cta' => ['label' => 'About Hansmuniji', 'href' => '/practitioner'],
                'image_media_id' => $this->m('IMG_9650.jpg'),
            ],
            'disciplines' => [
                'eyebrow' => 'The practice',
                'title' => 'Five disciplines, one intention.',
                'intro' => 'Each offering is private and shaped around the person. Some are booked directly; most begin with an application.',
            ],
            'philosophy' => [
                'eyebrow' => 'Philosophy',
                'title' => 'Clarity is a discipline, not a mood.',
                'body' => 'The work rests on three simple commitments.',
                'pillars' => [
                    ['numeral' => 'I', 'title' => 'Attention', 'body' => 'Noticing where the mind goes, without judgement, is the beginning of every practice.'],
                    ['numeral' => 'II', 'title' => 'Steadiness', 'body' => 'Breath and stillness build a centre that pressure cannot easily move.'],
                    ['numeral' => 'III', 'title' => 'Discretion', 'body' => 'What is shared stays in the room. Confidentiality is the foundation, not a feature.'],
                ],
                'image_media_id' => $this->m('IMG_9701.jpg'),
            ],
            'audio' => [
                'eyebrow' => 'Sound library',
                'title' => 'Practice between meetings.',
                'body' => 'Guided meditations and sound recordings to return to between sessions — a few minutes of stillness, wherever you are.',
                'cta' => ['label' => 'Enter the library', 'href' => '/library'],
                'image_media_id' => $this->m('IMG_9685.jpg'),
            ],
            'gatherings' => [
                'eyebrow' => 'Gatherings',
                'title' => 'Circles, retreats and evenings of sound.',
                'body' => 'Small gatherings held through the year, including full-moon and new-moon meditations. Places are limited and some are by invitation.',
                'cta' => ['label' => 'View gatherings', 'href' => '/gatherings'],
                'image_media_id' => $this->m('IMG_9682.jpg'),
            ],
            'confidentiality' => [
                'eyebrow' => 'Confidentiality',
                'title' => 'Discretion by design.',
                'body' => 'Privacy is built into how this practice operates, not added afterwards.',
                'points' => [
                    'Applications and booking details are encrypted at rest.',
                    'Access is limited to the private office, and every access is logged.',
                    'Nothing you share is used for marketing or sent to analytics providers.',
                    'You may ask to see or delete your information at any time.',
                ],
            ],
            'inquiry' => [
                'eyebrow' => 'Begin',
                'title' => 'Every engagement begins with a conversation.',
                'body' => 'Share a little about yourself and what you are seeking. Your application is read personally and held in confidence.',
                'cta' => ['label' => 'Request private access', 'href' => '/private-access'],
                'image_media_id' => $this->m('IMG_9719.jpg'),
            ],
        ];
    }

    private static function legalDraft(string $name): string
    {
        return '<p><strong>Draft — not yet published.</strong> This ' . $name . ' must be written or reviewed by a qualified legal professional for each jurisdiction in which the practice operates before it is published.</p>'
            . '<p>Points the platform already implements and which the final text should describe accurately: encryption of application and booking data at rest; restricted, logged administrative access; payment processing by Razorpay and Stripe without storing card details; Google Calendar for scheduling; optional, consent-based analytics that never receive confidential information; and data access and deletion requests via the privacy request form.</p>';
    }

    private function faqs(): string
    {
        if ($this->db->value('SELECT 1 FROM faqs LIMIT 1')) {
            return 'FAQs: exist.';
        }
        $faqs = [
            ['How is my information kept confidential?', 'Applications and booking details are encrypted at rest and visible only to authorised members of the private office, with every access recorded. Nothing you share is used for marketing or passed to analytics providers.'],
            ['Can sessions be held online?', 'Yes. Most consultations can be held over Google Meet or by telephone. In-person meetings are available in selected cities.'],
            ['Why do some offerings require an application?', 'An application helps us understand what you are seeking and whether this work is the right fit before any time is booked or payment taken.'],
            ['Is this a substitute for medical or psychological care?', 'No. These are contemplative and educational practices. If you are receiving medical or psychological care, please continue to follow the advice of your qualified practitioners.'],
            ['How do I reschedule or cancel?', 'Use the secure link in your confirmation email. Changes can be made online within the window stated in your booking policy; otherwise, contact the private office.'],
        ];
        foreach ($faqs as $i => [$q, $a]) {
            $this->db->insert('faqs', ['service_id' => null, 'question' => $q, 'answer' => $a, 'sort_order' => $i + 1, 'is_published' => 1, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }

        return 'FAQs: ' . count($faqs) . ' added.';
    }
}
