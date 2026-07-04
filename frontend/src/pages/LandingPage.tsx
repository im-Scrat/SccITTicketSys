import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { Hero } from '@/components/marketing/Hero'
import { FeaturesSection } from '@/components/marketing/FeaturesSection'
import { SolutionsSection } from '@/components/marketing/SolutionsSection'
import { AiSection } from '@/components/marketing/AiSection'
import { FloorPlanSection } from '@/components/marketing/FloorPlanSection'
import { QrSection } from '@/components/marketing/QrSection'
import { AnalyticsSection } from '@/components/marketing/AnalyticsSection'
import { SecuritySection } from '@/components/marketing/SecuritySection'
import { CtaSection } from '@/components/marketing/CtaSection'

export default function LandingPage() {
  useDocumentMeta({ title: 'SccIT — IT Asset & Service Management Platform' })

  return (
    <>
      <Hero />
      <FeaturesSection />
      <SolutionsSection />
      <AiSection />
      <FloorPlanSection />
      <QrSection />
      <AnalyticsSection />
      <SecuritySection />
      <CtaSection />
    </>
  )
}
