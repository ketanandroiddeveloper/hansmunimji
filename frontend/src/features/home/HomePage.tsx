import { Seo, SITE_URL } from '../../components/seo/Seo'
import { ErrorBlock, LoadingBlock } from '../../components/ui/States'
import { useAppointmentTypes, useAudio, useEvents, useHomePage, usePractitioner, useServices, useSettings, useTestimonials } from '../../lib/queries'
import { organizationLd } from '../../lib/structuredData'
import { AudioSection } from './AudioSection'
import { ConfidentialitySection } from './ConfidentialitySection'
import { DisciplinesSection } from './DisciplinesSection'
import { GatheringsSection } from './GatheringsSection'
import { HeroSection } from './HeroSection'
import { InquirySection } from './InquirySection'
import { IntroductionSection } from './IntroductionSection'
import { PhilosophySection } from './PhilosophySection'
import { TestimonialsSection } from './TestimonialsSection'

export default function HomePage() {
  const page = useHomePage()
  const services = useServices()
  const settings = useSettings()
  const practitioner = usePractitioner()
  const events = useEvents({ when: 'upcoming' })
  const audio = useAudio()
  const testimonials = useTestimonials()
  const types = useAppointmentTypes()

  if (page.isPending) return <LoadingBlock className="min-h-[100svh]" />
  if (page.isError) return <ErrorBlock error={page.error} onRetry={() => page.refetch()} className="min-h-[100svh]" />

  const s = page.data.sections
  const disciplines = practitioner.data?.expertise ?? []

  return (
    <>
      <Seo
        override={page.data.seo}
        image={s.hero?.image_media?.url}
        jsonLd={organizationLd(SITE_URL, settings.data, practitioner.data)}
      />
      {s.hero && <HeroSection hero={s.hero} disciplines={disciplines} />}
      {s.introduction && <IntroductionSection intro={s.introduction} practitioner={practitioner.data} />}
      {s.disciplines && (services.data?.length ?? 0) > 0 && <DisciplinesSection section={s.disciplines} services={services.data ?? []} />}
      {s.philosophy && <PhilosophySection section={s.philosophy} />}
      {s.audio && <AudioSection section={s.audio} tracks={audio.data ?? []} />}
      {s.gatherings && <GatheringsSection section={s.gatherings} events={events.data ?? []} />}
      {(testimonials.data?.length ?? 0) > 0 && <TestimonialsSection testimonials={testimonials.data ?? []} />}
      {s.confidentiality && <ConfidentialitySection section={s.confidentiality} />}
      {s.inquiry && <InquirySection section={s.inquiry} hasDirectBooking={(types.data?.length ?? 0) > 0} />}
    </>
  )
}
