import { useState } from 'react'
import type { CSSProperties } from 'react'
import type { Media } from '../../lib/types'

type PictureProps = {
  media: Media | null | undefined
  sizes?: string
  className?: string
  imgClassName?: string
  priority?: boolean
  alt?: string
  /** Fills the parent (object-cover) instead of using intrinsic aspect ratio. */
  fill?: boolean
}

/**
 * Responsive AVIF → WebP → JPEG picture with intrinsic dimensions (no layout shift) and a
 * blurred LQIP shown until the image decodes.
 */
export function Picture({ media, sizes = '100vw', className = '', imgClassName = '', priority = false, alt, fill = false }: PictureProps) {
  const [loaded, setLoaded] = useState(false)
  if (!media || media.private || !media.url) return null

  const placeholderStyle: CSSProperties | undefined =
    media.placeholder && !loaded
      ? { backgroundImage: `url("${media.placeholder}")`, backgroundSize: 'cover', backgroundPosition: media.focal_point ?? 'center' }
      : undefined

  const imgStyle: CSSProperties = { objectPosition: media.focal_point ?? 'center' }

  return (
    <picture className={`${fill ? 'absolute inset-0 block' : 'block'} ${className}`} style={placeholderStyle}>
      {media.srcset?.avif && <source type="image/avif" srcSet={media.srcset.avif} sizes={sizes} />}
      {media.srcset?.webp && <source type="image/webp" srcSet={media.srcset.webp} sizes={sizes} />}
      <img
        src={media.url}
        srcSet={media.srcset?.jpg}
        sizes={sizes}
        alt={alt ?? media.alt}
        width={media.width ?? undefined}
        height={media.height ?? undefined}
        loading={priority ? 'eager' : 'lazy'}
        decoding={priority ? 'sync' : 'async'}
        fetchPriority={priority ? 'high' : 'auto'}
        onLoad={() => setLoaded(true)}
        style={imgStyle}
        className={`${fill ? 'h-full w-full object-cover' : 'h-auto w-full'} transition-opacity duration-700 ease-expo ${loaded ? 'opacity-100' : 'opacity-0'} ${imgClassName}`}
      />
    </picture>
  )
}

type FrameProps = {
  media: Media | null | undefined
  sizes?: string
  priority?: boolean
  className?: string
  /** Width / height ratio of the frame, e.g. 4/5. */
  ratio?: number
  shape?: 'arch' | 'rect'
  tint?: boolean
}

/**
 * Arched "temple" frame — the Vedic reference in the visual system. A champagne hairline sits
 * slightly offset from the image to read as a printed mount rather than a UI card.
 */
export function ArchFrame({ media, sizes = '(min-width: 1024px) 40vw, 90vw', priority = false, className = '', ratio = 4 / 5, shape = 'arch', tint = true }: FrameProps) {
  const radius = shape === 'arch' ? 'rounded-t-[999px]' : ''
  return (
    <div className={`relative ${className}`}>
      <div aria-hidden="true" className={`pointer-events-none absolute -inset-3 border border-champagne-400/35 md:-inset-4 ${radius}`} />
      <div className={`relative overflow-hidden bg-midnight-800 ${radius}`} style={{ aspectRatio: String(ratio) }}>
        <Picture media={media} sizes={sizes} priority={priority} fill />
        {tint && <div aria-hidden="true" className="pointer-events-none absolute inset-0 bg-gradient-to-t from-midnight-950/45 via-transparent to-transparent" />}
      </div>
    </div>
  )
}
