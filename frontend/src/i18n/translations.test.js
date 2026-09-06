import { describe, expect, it } from 'vitest'
import { translationCatalogs } from './index.js'

function keys(value, prefix = '') {
  return Object.entries(value).flatMap(([key, nestedValue]) => {
    const path = prefix ? `${prefix}.${key}` : key
    return typeof nestedValue === 'object' ? keys(nestedValue, path) : [path]
  })
}

describe('translation catalogs', () => {
  it('keeps English and Arabic keys in exact parity', () => {
    expect(keys(translationCatalogs.ar).sort()).toEqual(keys(translationCatalogs.en).sort())
  })

  it('contains the required Arabic product terms', () => {
    expect(translationCatalogs.ar.navigation.dashboard).toBe('لوحة التحكم')
    expect(translationCatalogs.ar.navigation.workflows).toBe('مسارات العمل')
    expect(translationCatalogs.ar.dashboard.createWorkflow).toBe('إنشاء مسار عمل')
    expect(translationCatalogs.ar.navigation.integrations).toBe('التكاملات')
    expect(translationCatalogs.ar.navigation.logout).toBe('تسجيل الخروج')
  })
})
