import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import ar from './locales/ar.js'
import en from './locales/en.js'

export const LOCALE_STORAGE_KEY = 'nasaq.locale'
export const supportedLocales = ['en', 'ar']
export const translationCatalogs = { en, ar }

function storedLocale() {
  if (typeof window === 'undefined') return null
  const locale = window.localStorage.getItem(LOCALE_STORAGE_KEY)
  return supportedLocales.includes(locale) ? locale : null
}

function browserLocale() {
  if (typeof navigator === 'undefined') return 'en'
  return navigator.language?.toLowerCase().startsWith('ar') ? 'ar' : 'en'
}

export function applyDocumentLocale(locale) {
  if (typeof document === 'undefined') return
  document.documentElement.lang = locale
  document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr'
}

export function getStoredLocale() {
  return storedLocale()
}

const initialLocale = storedLocale() ?? browserLocale()

i18n.use(initReactI18next).init({
  resources: {
    en: { translation: en },
    ar: { translation: ar },
  },
  lng: initialLocale,
  fallbackLng: 'en',
  supportedLngs: supportedLocales,
  interpolation: { escapeValue: false },
  returnNull: false,
})

applyDocumentLocale(initialLocale)

i18n.on('languageChanged', (locale) => {
  const supportedLocale = supportedLocales.includes(locale) ? locale : 'en'
  if (typeof window !== 'undefined') {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, supportedLocale)
  }
  applyDocumentLocale(supportedLocale)
})

export default i18n
