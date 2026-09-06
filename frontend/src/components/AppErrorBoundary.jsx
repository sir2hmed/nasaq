import PropTypes from 'prop-types'
import { Component } from 'react'
import i18n from '../i18n/index.js'

export default class AppErrorBoundary extends Component {
  constructor(props) {
    super(props)
    this.state = { failed: false }
  }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  render() {
    if (this.state.failed) {
      return (
        <main className="unexpected-error" id="main-content" tabIndex="-1">
          <span aria-hidden="true">!</span>
          <h1>{i18n.t('errors.unexpectedTitle')}</h1>
          <p>{i18n.t('errors.unexpectedCopy')}</p>
          <button className="button button-primary" onClick={() => window.location.reload()} type="button">
            {i18n.t('errors.reload')}
          </button>
        </main>
      )
    }

    return this.props.children
  }
}

AppErrorBoundary.propTypes = {
  children: PropTypes.node.isRequired,
}
