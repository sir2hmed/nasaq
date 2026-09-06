import '@testing-library/jest-dom/vitest'

class ResizeObserverMock {
  observe() {}

  unobserve() {}

  disconnect() {}
}

globalThis.ResizeObserver = ResizeObserverMock

Object.defineProperties(HTMLElement.prototype, {
  offsetHeight: { configurable: true, get: () => 640 },
  offsetWidth: { configurable: true, get: () => 900 },
})

HTMLElement.prototype.getBoundingClientRect = function getBoundingClientRect() {
  return {
    width: 900,
    height: 640,
    top: 0,
    left: 0,
    bottom: 640,
    right: 900,
    x: 0,
    y: 0,
    toJSON: () => {},
  }
}
