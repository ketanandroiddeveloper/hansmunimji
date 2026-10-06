import { z } from 'zod'
import { PHONE_MESSAGE, PHONE_PATTERN } from '../../lib/forms'

/** Mirrors ApplicationService::RULES on the server; the server remains the authority. */
export const applicationSchema = z.object({
  full_name: z.string().trim().min(2, 'Please enter your full name.').max(190),
  email: z.string().trim().email('Enter a valid email address.').max(190),
  phone: z.string().trim().regex(PHONE_PATTERN, PHONE_MESSAGE),
  country: z.string().regex(/^[A-Z]{2}$/, 'Select your country of residence.'),
  city: z.string().trim().min(1, 'Enter your city.').max(120),

  designation: z.string().trim().min(1, 'Enter your role or title.').max(190),
  organization: z.string().trim().min(1, 'Enter your organisation, or “Private”.').max(190),
  professional_background: z.string().trim().min(20, 'A few sentences (at least 20 characters), please.').max(3000),

  consultation_type: z.string().min(1, 'Choose the area you are interested in.'),
  core_objective: z.string().trim().min(30, 'Please share a little more (at least 30 characters).').max(4000),
  preferred_format: z.enum(['google_meet', 'phone', 'in_person'], { message: 'Choose a preferred format.' }),

  preferred_availability: z.string().trim().max(500).optional().or(z.literal('')),
  referral_source: z.enum(['referral', 'search', 'event', 'media', 'social', 'other'], { message: 'Let us know how you heard of us.' }),
  referral_details: z.string().trim().max(500).optional().or(z.literal('')),
  confidential_notes: z.string().trim().max(4000).optional().or(z.literal('')),

  consent_privacy: z.literal(true, { message: 'Please confirm you have read the privacy notice.' }),
  consent_communications: z.boolean().optional(),
})

export type ApplicationForm = z.infer<typeof applicationSchema>
export type ApplicationField = keyof ApplicationForm

export const STEPS: { label: string; title: string; intro: string; fields: ApplicationField[] }[] = [
  {
    label: 'You',
    title: 'A little about you.',
    intro: 'These details let the private office reply to you personally. Your progress is saved securely as you go.',
    fields: ['full_name', 'email', 'phone', 'country', 'city'],
  },
  {
    label: 'Context',
    title: 'Your professional context.',
    intro: 'Share as much or as little as you are comfortable with. Everything is encrypted and read only by the private office.',
    fields: ['designation', 'organization', 'professional_background'],
  },
  {
    label: 'Intention',
    title: 'What you are seeking.',
    intro: 'There is no right answer — describe what you hope to find, change or understand.',
    fields: ['consultation_type', 'core_objective', 'preferred_format'],
  },
  {
    label: 'Details',
    title: 'Practical details.',
    intro: 'Optional context that helps us propose the right time and setting.',
    fields: ['preferred_availability', 'referral_source', 'referral_details', 'confidential_notes'],
  },
  {
    label: 'Review',
    title: 'Review and submit.',
    intro: 'Please check your answers. You can return to any step before submitting.',
    fields: ['consent_privacy', 'consent_communications'],
  },
]

export const SERVER_FIELDS: readonly string[] = Object.keys(applicationSchema.shape)

export const FORMAT_OPTIONS = [
  { value: 'google_meet', label: 'Google Meet', description: 'Private video call' },
  { value: 'phone', label: 'Telephone', description: 'A call at an agreed time' },
  { value: 'in_person', label: 'In person', description: 'Selected cities, by arrangement' },
]

export const REFERRAL_OPTIONS = [
  { value: 'referral', label: 'A personal referral' },
  { value: 'search', label: 'Search' },
  { value: 'event', label: 'An event or talk' },
  { value: 'media', label: 'Press or media' },
  { value: 'social', label: 'Social media' },
  { value: 'other', label: 'Other' },
]

/** Fields persisted to the encrypted server-side draft (consents are only sent on submit). */
export function draftPayload(values: Partial<ApplicationForm>, step: number): Record<string, unknown> {
  const out: Record<string, unknown> = { current_step: step + 1 }
  for (const [k, v] of Object.entries(values)) {
    if (k === 'consent_privacy' || k === 'consent_communications') continue
    if (typeof v === 'string' && v.trim() === '') continue
    out[k] = typeof v === 'string' ? v.trim() : v
  }
  return out
}
