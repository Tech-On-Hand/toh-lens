import { BrowserBridge, detectBrowser } from './bridge.js';

// Runs at every service worker start, which re-establishes the native connection
// after the browser suspends the worker.
new BrowserBridge({ chrome, browser: detectBrowser(navigator.userAgent) }).start();
