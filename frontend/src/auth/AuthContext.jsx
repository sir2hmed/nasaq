import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import PropTypes from 'prop-types'
import {
  getCurrentUser,
  loginAccount,
  logoutAccount,
  persistLocale,
  registerAccount,
} from '../api/authApi.js'
import i18n, { getStoredLocale } from '../i18n/index.js'

const AuthContext = createContext(null)

function isUnauthorized(error) {
  return error.response?.status === 401
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  const acceptUser = useCallback((nextUser) => {
    const preferredLocale = getStoredLocale() ?? nextUser.preferred_locale ?? 'en'
    void i18n.changeLanguage(preferredLocale)
    setUser(nextUser)

    if (nextUser.preferred_locale !== preferredLocale) {
      void persistLocale(preferredLocale)
        .then(setUser)
        .catch(() => undefined)
    }

    return nextUser
  }, [])

  useEffect(() => {
    let active = true

    const refreshCurrentUser = () => getCurrentUser()
      .then((nextUser) => {
        if (active) acceptUser(nextUser)
      })
      .catch((error) => {
        if (active && isUnauthorized(error)) setUser(null)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    void refreshCurrentUser()

    const clearSession = () => setUser(null)
    const refreshOnFocus = () => {
      if (document.visibilityState !== 'hidden') void refreshCurrentUser()
    }
    window.addEventListener('nasaq:unauthorized', clearSession)
    window.addEventListener('focus', refreshOnFocus)
    document.addEventListener('visibilitychange', refreshOnFocus)

    return () => {
      active = false
      window.removeEventListener('nasaq:unauthorized', clearSession)
      window.removeEventListener('focus', refreshOnFocus)
      document.removeEventListener('visibilitychange', refreshOnFocus)
    }
  }, [acceptUser])

  const login = useCallback(
    async (payload) => acceptUser(await loginAccount(payload)),
    [acceptUser],
  )

  const register = useCallback(
    async (payload) => acceptUser(await registerAccount(payload)),
    [acceptUser],
  )

  const logout = useCallback(async () => {
    try {
      await logoutAccount()
    } finally {
      setUser(null)
    }
  }, [])

  const changeLocale = useCallback(
    async (locale) => {
      await i18n.changeLanguage(locale)
      if (!user) return null

      try {
        const updatedUser = await persistLocale(locale)
        setUser(updatedUser)
        return updatedUser
      } catch {
        return null
      }
    },
    [user],
  )

  const value = useMemo(
    () => ({ user, loading, login, register, logout, changeLocale }),
    [user, loading, login, register, logout, changeLocale],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

AuthProvider.propTypes = {
  children: PropTypes.node.isRequired,
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside AuthProvider')
  return context
}
