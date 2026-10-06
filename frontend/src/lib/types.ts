export interface Media {
  id: number
  alt: string
  caption: string | null
  category: string | null
  mime: string
  width: number | null
  height: number | null
  focal_point?: string | null
  url?: string
  srcset?: Partial<Record<'avif' | 'webp' | 'jpg', string>>
  placeholder?: string | null
  private?: boolean
}

export interface Cta {
  label: string
  href: string
}

export interface Seo {
  title: string | null
  description: string | null
  canonical: string | null
  robots: string | null
  image: string | null
}

export interface Page<S = Record<string, unknown>> {
  slug: string
  type: string
  title: string
  sections: S
  body: string | null
  updated_at: string
  seo: Seo | null
}

export interface HomeSections {
  hero?: {
    eyebrow?: string
    title?: string
    subtitle?: string
    primary_cta?: Cta
    secondary_cta?: Cta
    image_media?: Media | null
  }
  introduction?: { eyebrow?: string; title?: string; body?: string; cta?: Cta; image_media?: Media | null }
  disciplines?: { eyebrow?: string; title?: string; intro?: string }
  philosophy?: {
    eyebrow?: string
    title?: string
    body?: string
    pillars?: { numeral?: string; title: string; body: string }[]
    image_media?: Media | null
  }
  audio?: { eyebrow?: string; title?: string; body?: string; cta?: Cta; image_media?: Media | null }
  gatherings?: { eyebrow?: string; title?: string; body?: string; cta?: Cta; image_media?: Media | null }
  confidentiality?: { eyebrow?: string; title?: string; body?: string; points?: string[] }
  inquiry?: { eyebrow?: string; title?: string; body?: string; cta?: Cta; image_media?: Media | null }
}

export type BookingMode = 'direct' | 'application' | 'inquiry'

export interface ServiceSummary {
  slug: string
  numeral: string | null
  title: string
  subtitle: string | null
  summary: string | null
  booking_mode: BookingMode
  duration_label: string | null
  price_display: string | null
  is_featured: boolean
  cover: Media | null
}

export interface Faq {
  question: string
  answer: string
}

export interface ServiceDetail extends ServiceSummary {
  category: string | null
  body: string | null
  highlights: string[]
  offerings: { title: string; description?: string }[]
  faqs: Faq[]
  seo: Seo | null
}

export interface PublicSettings {
  'site.name'?: string
  'site.tagline'?: string
  'site.description'?: string
  'contact.email'?: string
  'contact.phone'?: string
  'contact.whatsapp'?: string
  'currencies.default'?: string
  'currencies.enabled'?: string[]
  'social.links'?: { label: string; url: string }[]
  'analytics.ga_measurement_id'?: string
  'consent.banner_text'?: string
  'privacy.policy_version'?: string
  environment?: 'local' | 'testing' | 'staging' | 'production'
  [key: string]: unknown
}

export interface Qualification {
  title: string
  institution: string | null
  year: number | null
  description: string | null
  url: string | null
}

export interface Practitioner {
  slug: string
  full_name: string
  honorific: string | null
  title: string | null
  short_bio: string | null
  biography: string | null
  philosophy: string | null
  approach: string | null
  expertise: string[]
  experience: { period?: string; title: string; description?: string }[]
  same_as: string[]
  portrait: Media | null
  secondary_image: Media | null
  qualifications: Record<string, Qualification[]>
  gallery: Media[]
}

export interface Money {
  currency: string
  amount_minor: number
}

export interface EventSummary {
  slug: string
  title: string
  category: string | null
  summary: string | null
  city: { name: string; country: string } | null
  venue: string | null
  starts_at: string
  ends_at: string
  timezone: string
  registration_mode: 'open' | 'application' | 'invitation' | 'closed'
  /** Server-computed: whether and how a guest can register right now. */
  registration_state: 'open' | 'application' | 'waitlist' | 'full' | 'not_yet_open' | 'closed' | 'invitation' | 'cancelled'
  registration_opens_at: string | null
  registration_closes_at: string | null
  waitlist_enabled: boolean
  seats_left: number | null
  /** First listed price, kept for compact displays; `prices` holds every offered currency. */
  price: EventPrice | null
  prices: EventPrice[]
  tax: { label: string | null; rate_bp: number; inclusive: boolean } | null
  status: 'published' | 'cancelled'
  is_featured: boolean
  cover: Media | null
}

export interface EventPrice extends Money {
  /** False when no payment gateway is currently available for this currency. */
  payable: boolean
}

export interface EventDetail extends EventSummary {
  description: string | null
  requirements: string | null
  address: string | null
  cancellation_policy: string | null
  cancellation_window_hours: number
  refund_on_cancel_percent: number
  seo: Seo | null
}

export interface AudioTrack {
  slug: string
  title: string
  description: string | null
  category: string | null
  duration_seconds: number | null
  access: 'public' | 'clients'
  is_featured: boolean
  available: boolean
  cover: Media | null
}

export interface BlogSummary {
  slug: string
  title: string
  excerpt: string | null
  category: { slug: string; name: string } | null
  author: { name: string; slug: string } | null
  published_at: string
  reading_minutes: number | null
  tags: string[]
  cover: Media | null
}

export interface BlogDetail extends BlogSummary {
  body: string | null
  updated_at: string
  seo: Seo | null
}

export type MeetingFormat = 'google_meet' | 'in_person' | 'phone'

export interface AppointmentType {
  slug: string
  title: string
  description: string | null
  service: { slug: string; title: string } | null
  duration_minutes: number
  formats: MeetingFormat[]
  requires_payment: boolean
  requires_approval: boolean
  prices: Money[]
  tax: { label: string | null; rate_bp: number; inclusive: boolean }
  cancellation_policy: string | null
  reschedule_policy: string | null
  cancellation_window_hours: number
  reschedule_window_hours: number
  max_advance_days: number
}

export interface City {
  id: number
  name: string
  country: string
  timezone: string
}

export interface Testimonial {
  quote: string
  attribution: string | null
  role: string | null
}
