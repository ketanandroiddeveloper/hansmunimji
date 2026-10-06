import { lazy } from 'react'
import { createBrowserRouter } from 'react-router'
import { PublicLayout } from '../components/layout/PublicLayout'
import HomePage from '../features/home/HomePage'
import { NotFoundPage, RouteErrorPage } from '../pages/NotFoundPage'

const PracticePage = lazy(() => import('../features/practice/PracticePage'))
const ServicePage = lazy(() => import('../features/practice/ServicePage'))
const PractitionerPage = lazy(() => import('../features/practitioner/PractitionerPage'))
const ApplicationPage = lazy(() => import('../features/application/ApplicationPage'))
const ApplicationStatusPage = lazy(() => import('../features/application/ApplicationStatusPage'))
const BookingPage = lazy(() => import('../features/booking/BookingPage'))
const ManageAppointmentPage = lazy(() => import('../features/booking/ManageAppointmentPage'))
const LibraryPage = lazy(() => import('../features/library/LibraryPage'))
const GatheringsPage = lazy(() => import('../features/gatherings/GatheringsPage'))
const EventPage = lazy(() => import('../features/gatherings/EventPage'))
const RegistrationPage = lazy(() => import('../features/gatherings/RegistrationPage'))
const JournalPage = lazy(() => import('../features/journal/JournalPage'))
const ArticlePage = lazy(() => import('../features/journal/ArticlePage'))
const LegalPage = lazy(() => import('../features/legal/LegalPage'))
const PrivacyRequestPage = lazy(() => import('../features/legal/PrivacyRequestPage'))

export const router = createBrowserRouter([
  {
    path: 'admin/*',
    errorElement: <RouteErrorPage />,
    lazy: async () => ({ Component: (await import('../admin/AdminApp')).default }),
  },
  {
    element: <PublicLayout />,
    errorElement: <RouteErrorPage />,
    children: [
      { index: true, element: <HomePage /> },
      { path: 'practice', element: <PracticePage /> },
      { path: 'practice/:slug', element: <ServicePage /> },
      { path: 'practitioner', element: <PractitionerPage /> },
      { path: 'private-access', element: <ApplicationPage /> },
      { path: 'private-access/status/:reference', element: <ApplicationStatusPage /> },
      { path: 'consultation', element: <BookingPage /> },
      { path: 'consultation/:reference', element: <ManageAppointmentPage /> },
      { path: 'library', element: <LibraryPage /> },
      { path: 'gatherings', element: <GatheringsPage /> },
      { path: 'gatherings/registration/:reference', element: <RegistrationPage /> },
      { path: 'gatherings/:slug', element: <EventPage /> },
      { path: 'journal', element: <JournalPage /> },
      { path: 'journal/:slug', element: <ArticlePage /> },
      { path: 'legal/:slug', element: <LegalPage /> },
      { path: 'privacy/requests', element: <PrivacyRequestPage /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
