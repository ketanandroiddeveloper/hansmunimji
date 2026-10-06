import { QueryClient, useQuery } from '@tanstack/react-query'
import { api, ApiError } from './api'
import type {
  AppointmentType,
  AudioTrack,
  BlogDetail,
  BlogSummary,
  City,
  EventDetail,
  EventSummary,
  Faq,
  HomeSections,
  Media,
  Page,
  Practitioner,
  PublicSettings,
  ServiceDetail,
  ServiceSummary,
  Testimonial,
} from './types'

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5 * 60_000,
      gcTime: 30 * 60_000,
      refetchOnWindowFocus: false,
      retry: (count, error) => !(error instanceof ApiError && error.status >= 400 && error.status < 500) && count < 2,
    },
  },
})

export const useSettings = () => useQuery({ queryKey: ['settings'], queryFn: () => api.get<PublicSettings>('/settings/public'), staleTime: 10 * 60_000 })

export const usePage = <S = Record<string, unknown>>(slug: string) =>
  useQuery({ queryKey: ['page', slug], queryFn: () => api.get<Page<S>>(`/pages/${encodeURIComponent(slug)}`) })

export interface IntroSections {
  eyebrow?: string
  title?: string
  intro?: string
  image_media?: Media | null
}

/** Section intro copy managed in the CMS; resolves to null until such a page is published. */
export const useOptionalPage = (slug: string) =>
  useQuery({
    queryKey: ['page', slug, 'optional'],
    queryFn: () =>
      api.get<Page<IntroSections>>(`/pages/${encodeURIComponent(slug)}`).catch((e: unknown) => {
        if (e instanceof ApiError && e.status === 404) return null
        throw e
      }),
  })

export const usePublishedPages = () =>
  useQuery({ queryKey: ['pages'], queryFn: () => api.get<{ slug: string; title: string; type: string }[]>('/pages'), staleTime: 10 * 60_000 })

export const useHomePage = () => usePage<HomeSections>('home')

export const useServices = () => useQuery({ queryKey: ['services'], queryFn: () => api.get<ServiceSummary[]>('/services') })

export const useService = (slug: string) =>
  useQuery({ queryKey: ['service', slug], queryFn: () => api.get<ServiceDetail>(`/services/${encodeURIComponent(slug)}`), enabled: slug !== '' })

export const usePractitioner = () => useQuery({ queryKey: ['practitioner'], queryFn: () => api.get<Practitioner>('/practitioner') })

export const useEvents = (filters: { when?: 'upcoming' | 'past'; category?: string; city?: string } = {}) =>
  useQuery({ queryKey: ['events', filters], queryFn: () => api.get<EventSummary[]>('/events', { query: filters }) })

export const useEvent = (slug: string) =>
  useQuery({ queryKey: ['event', slug], queryFn: () => api.get<EventDetail>(`/events/${encodeURIComponent(slug)}`), enabled: slug !== '' })

export const useAudio = (category?: string) =>
  useQuery({ queryKey: ['audio', category ?? null], queryFn: () => api.get<AudioTrack[]>('/audio', { query: { category } }) })

export const useFaqs = () => useQuery({ queryKey: ['faqs'], queryFn: () => api.get<Faq[]>('/faqs') })

export const useTestimonials = () => useQuery({ queryKey: ['testimonials'], queryFn: () => api.get<Testimonial[]>('/testimonials') })

export const useCities = () => useQuery({ queryKey: ['cities'], queryFn: () => api.get<City[]>('/cities'), staleTime: 30 * 60_000 })

export const useAppointmentTypes = (service?: string, inviteToken?: string | null) =>
  useQuery({
    queryKey: ['appointment-types', service ?? null, inviteToken ?? null],
    queryFn: () =>
      api.get<AppointmentType[]>('/appointments/types', {
        query: { service },
        headers: inviteToken ? { 'X-Invite-Token': inviteToken } : undefined,
      }),
  })

export const useBlogs = (page = 1, category?: string) =>
  useQuery({
    queryKey: ['blogs', page, category ?? null],
    queryFn: () => api.getEnvelope<BlogSummary[]>('/blogs', { query: { page, category } }),
  })

export const useBlog = (slug: string) =>
  useQuery({ queryKey: ['blog', slug], queryFn: () => api.get<BlogDetail>(`/blogs/${encodeURIComponent(slug)}`), enabled: slug !== '' })
