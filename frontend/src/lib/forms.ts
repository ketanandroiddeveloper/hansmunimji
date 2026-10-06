import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { ApiError } from './api'

/**
 * Applies server-side field errors to the form. Returns the general message to display when
 * the error is not field-specific (or names fields the form does not render).
 */
export function applyServerErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>, knownFields: readonly string[]): string | null {
  if (!(error instanceof ApiError)) return 'Something went wrong. Please try again.'
  const entries = Object.entries(error.fields)
  let unmatched = entries.length === 0
  for (const [field, messages] of entries) {
    if (knownFields.includes(field)) {
      setError(field as Path<T>, { type: 'server', message: messages[0] })
    } else {
      unmatched = true
    }
  }
  if (!unmatched) return null
  if (error.status === 429) return 'Too many attempts. Please wait a few minutes and try again.'
  return error.message
}

export const PHONE_PATTERN = /^\+[1-9][0-9 ()-]{6,20}$/
export const PHONE_MESSAGE = 'Enter a phone number with country code, e.g. +971 50 123 4567.'
